# Rediseño de Transporte + Canales — Diseño

**Fecha:** 2026-06-05
**Alcance:** Capa de transporte (entrega web) + capa de canales (WhatsApp como canal primario).
**Fuera de alcance:** Roadmap comercial / re-secuenciación de producto. Rediseño del modelo de identidad/datos (el modelo sigue girando alrededor de `session_id` para web y `conversationKey()` para canales).

---

## 1. Contexto y punto de partida

El pipeline de chat **ya está unificado y es transport-agnostic**. Este rediseño no toca el núcleo; se apoya en costuras existentes.

- `Chat\ChatPipeline` — núcleo único. No conoce SSE ni WhatsApp. Escribe la salida en un `OutputSink`. Hace validación, contexto, LLM, persistencia, cuota, lead.
- `Chat\ChatService` — fachada web fina: valida Origin (CORS capa 2) y ejecuta el pipeline con un `StreamingSink`.
- `Channel\InboundDispatcher` — fachada de canal: ejecuta el pipeline con un `BufferedSink` y responde vía el adapter.
- `Chat\OutputSink` (interfaz) con `StreamingSink` (web/SSE) y `BufferedSink` (canales).
- `Channel\ChannelAdapterInterface`, `ChannelRegistry`, `ChannelRepository`, `ChannelEventRepository` (idempotencia), `Security\CredentialVault` (cifrado de credenciales).

**El `OutputSink` es la costura donde se enchufa la entrega adaptativa. El modelo async de Action Scheduler (ya usado por canales) es la costura para un futuro polling.**

---

## 2. Objetivos

1. **Entrega web resiliente** en el hosting compartido que usan las PyMEs argentinas (cPanel/LiteSpeed + Cloudflare gratis), sin reescribir el motor y sin re-ejecutar el LLM.
2. **WhatsApp grado producción** como canal primario: saber si las respuestas se entregan, manejar errores de la Graph API con criterio, conocer el estado de la ventana de 24h, y soportar templates.

## 3. No-objetivos (explícito)

- No se construye el modo polling/job async para web ahora (se diseña la costura).
- No se cambia el modelo de identidad (web = session_id, canal = teléfono/chatid).
- No se toca Sales Automation, cobro, ni el roadmap comercial.

---

## Bloque A — Transporte web: entrega adaptativa SSE → Full

### A.1 Principio

Un solo endpoint, **una sola generación del LLM**, el cliente se adapta a cómo llega la respuesta. El "modo Full" no re-ejecuta nada: es el mismo contenido entregado completo en lugar de incremental.

### A.2 Servidor

- Se mantiene `POST /infouno/v1/chat` con SSE y `StreamingSink`. La generación del LLM es **única** — nunca se repite por un fallback.
- Se agrega un parámetro explícito **`?mode=full`**: misma ruta de pipeline, pero con un sink que bufferiza y devuelve **JSON completo** (`{ reply, finish_reason, ... }`) en lugar de SSE. Reutiliza la lógica de `BufferedSink`.
  - El selector de modo vive en `ChatController` (capa de transporte), no en `ChatPipeline`.
- **Telemetría de entrega:** registrar el modo de entrega efectivamente logrado para cada request: `streamed` | `buffered_full` | `timeout_fallback`. Es el dato que decide objetivamente si el polling vale la pena en el futuro. Se loguea server-side (y opcionalmente lo reporta el cliente, ver A.3).

### A.3 Cliente (widget)

La inteligencia de detección vive en el cliente, porque el fallo dominante (SSE bufferizado por el proxy) **no genera una excepción**: el `fetch` tiene éxito, simplemente no llegan chunks incrementales.

- **Detección por timeout al primer chunk** (no por `catch`): si en `N` segundos no llegó el primer fragmento → abortar el `ReadableStream` (AbortController) y re-pedir con `?mode=full`, mostrando indicador "escribiendo…".
  - `N` default: **4 segundos**. Configurable por `data-*` attribute del script tag.
- **Buffer-al-final tolerado:** si los chunks llegan todos juntos al cierre (proxy bufferizado), el cliente los renderiza completos. No se pierde contenido.
- **Memoria de modo por dominio:** persistir en `localStorage` el modo que funcionó para este origen. La próxima sesión arranca directo en el modo bueno y no re-paga el timeout en cada mensaje.
  - Clave: `infouno_delivery_mode:<botToken|origin>`. Valores: `sse` | `full`.
  - Invalidación: si el modo guardado es `full` pero un reintento periódico (ej. 1 de cada N sesiones) logra `sse`, se actualiza. Evita quedar pegado en `full` para siempre si el hosting mejora.

### A.4 Costura de polling (DISEÑADA, NO CONSTRUIDA)

Si la telemetría muestra cortes reales por **duración de conexión** (no buffering), se agrega un tercer modo sin tocar el núcleo:

- `POST /chat?mode=async` → encola un job (Action Scheduler, patrón de canales) y devuelve un `job_id`.
- El job ejecuta `ChatPipeline` con un `BufferedSink` persistente que escribe a un store (transient o tabla).
- `GET /chat/result/{job_id}` → el cliente hace polling.
- Nota de latencia: en WP de bajo tráfico, Action Scheduler depende del runner de la cola; un loopback request para disparar el runner sería necesario para UX interactiva. **Por eso no se construye hasta tener evidencia de que se necesita.**

### A.5 Error handling (Bloque A)

- Timeout al primer chunk → fallback a `full` (no es error visible al usuario).
- Error real del pipeline (4xx/5xx con código semántico) → mismo manejo actual; el widget muestra el mensaje y vuelve a READY.
- Si `full` también falla → mensaje de error genérico + retry manual del usuario.

### A.6 Testing (Bloque A)

- Cliente: el timeout-al-primer-chunk dispara el modo `full` (test con stream que no emite a tiempo).
- Cliente: persistencia y lectura del modo por dominio en `localStorage`.
- Servidor: `?mode=full` devuelve JSON completo con el mismo contenido que el SSE.
- Servidor: la telemetría registra el modo correcto.

### A.7 Puntos confirmados

- Timeout al primer chunk: **4s default**, configurable.
- Telemetría de modo de entrega: **sí**, es el insumo para decidir polling.

---

## Bloque B — Endurecimiento WhatsApp (canal primario)

### B.1 Recibos de estado (`statuses`)

Hoy `WhatsAppAdapter::parseInbound()` devuelve `null` ante eventos `statuses` → se descartan silenciosamente. No sabemos si una respuesta se entregó o falló.

- `parseInbound` deja de descartar `statuses`. Se introduce un camino de parseo de estado, vía un nuevo `kind = 'status'` en `InboundMessage` **o** un método paralelo `parseStatuses(array $payload): array` (decisión de implementación; se prefiere mantener `parseInbound` enfocado en mensajes y agregar `parseStatuses` separado para no sobrecargar el value object).
- Para mapear un recibo a *nuestra* respuesta saliente, hay que **capturar el `wamid` que devuelve la Graph API en `send()`** (hoy la respuesta se ignora) y ligarlo a la entrega.
- El dispatcher/worker registra la transición de estado (`sent` → `delivered` → `read` → `failed`) por entrega.

### B.2 Manejo robusto de errores Graph API

Hoy `send()` solo hace `error_log` ante HTTP >= 400.

- Parsear el body de error de Meta y **clasificar**:
  - **Transitorio** (rate limit, 5xx, errores de red) → re-lanzar para que Action Scheduler reintente con backoff.
  - **Permanente** (parámetro inválido, número inexistente, fuera de ventana sin template) → loguear estructurado y abandonar, sin reintento.
- Detectar códigos específicos: **131047** (re-engagement / fuera de ventana), **131026** (no entregable), **100** (parámetro inválido), límites de rate.

### B.3 Conciencia de ventana de 24h

- **Sin tabla nueva.** El ancla de la ventana es el `created_at` del último mensaje `role = user` de la conversación, que ya vive en `wp_infouno_messages`.
- Una query deriva ventana **abierta** (< 24h desde el último inbound) o **cerrada**.
- El estado de ventana alimenta la bifurcación de envío (B.4).

### B.4 Templates (infra completa)

> **Nota de alcance honesta:** un bot puramente reactivo casi nunca sale de la ventana de 24h (siempre responde a un mensaje recién recibido). La infra de templates **recién aporta valor real con mensajería proactiva (Sales Automation)**. Se construye completa por decisión del owner, diseñada para conectarse sin retrabajo cuando llegue la proactividad.

- **Nueva tabla `wp_infouno_channel_templates`:**
  - `id`, `tenant_id` (FK, INDEX), `channel_id` (FK), `name` (nombre del template aprobado en Meta), `language` (ej. `es_AR`), `variables_schema` (JSON: definición de placeholders), `status` (`approved` | `pending` | `rejected`), `created_at`, `updated_at`.
  - Toda query con filtro `tenant_id` (guardrail tenant-isolation).
- **Resolver de variables:** mapea datos de la conversación/lead a los placeholders del template.
- **Bifurcación en `send()`** (o en una capa de decisión previa al adapter):
  - **Ventana abierta** → texto free-form (comportamiento actual).
  - **Ventana cerrada** → template con variables resueltas (formato `type: template` de la Graph API).

### B.5 Esquema / migración (DB v10)

> **Corrección (2026-06-06):** la v9 ya está tomada por la migración de Canales (Fase 1: `wp_infouno_channels` + `wp_infouno_channel_events`). Las tablas de Bloque B van en una migración **v10**.

Una migración **v10** (`migrateTo10()` en `Core\Migrator`, idempotente, sin `DROP`, `INFOUNO_DB_VERSION = '10'`):

- **`wp_infouno_channel_templates`** (ver B.4).
- **`wp_infouno_channel_deliveries`** (estado de salientes) — **decisión tomada: tabla dedicada, NO columna en `messages`.**
  - `id`, `tenant_id` (FK, INDEX), `channel_id` (FK), `message_id` (FK a `wp_infouno_messages`, NULL si la entrega no corresponde a un mensaje persistido), `external_msg_id` (`wamid` devuelto por Meta, INDEX), `status` (`sent` | `delivered` | `read` | `failed`), `error_code` (NULL salvo failed), `status_updated_at`, `created_at`.
  - **Razón de tabla dedicada:** `messages` es compartida web + canales y tiene la regla "filas con tokens nunca se borran físicamente". Columnas solo-de-canal quedarían NULL para todos los mensajes web. Una tabla aparte aísla el concern de canal, permite transiciones de estado con timestamp, y mapea limpio por `wamid`. Costo: un join para ver estado junto al mensaje — trivial a este volumen.

### B.6 Error handling (Bloque B)

- Recibo `failed` → registrar `error_code`, loguear estructurado. No interrumpe nada (el usuario ya recibió o no su mensaje; es observabilidad).
- Error transitorio en `send()` → re-lanzar (Action Scheduler reintenta). Error permanente → abandonar con log.
- Envío fuera de ventana sin template configurado → loguear y abandonar con mensaje claro en el log (no romper el worker).

### B.7 Testing (Bloque B)

- Parseo de eventos `statuses` (sent/delivered/read/failed) → registro correcto en `channel_deliveries`.
- Captura del `wamid` en `send()` y su ligazón a la entrega.
- Clasificación de errores Graph API: transitorio re-lanza, permanente abandona.
- Cálculo del estado de ventana (< 24h vs >= 24h) a partir del último inbound.
- Selección free-form vs template según estado de ventana.
- Resolver de variables de template.
- Migración v10 idempotente (correr dos veces no rompe).

---

## 4. Resumen de cambios por archivo (orientativo)

| Archivo / componente | Cambio |
|---|---|
| `client-widget/src/api/client.ts` | Timeout al primer chunk; re-pedido `?mode=full`; lectura de modo por dominio. |
| `client-widget/src/hooks/useChat.ts` | Estado de modo de entrega; persistencia en `localStorage`. |
| `API/ChatController.php` | Selector `?mode=full` (sink bufferizado → JSON); telemetría de modo de entrega. |
| `Chat/` (sinks) | Posible sink "full/JSON" reutilizando `BufferedSink`. |
| `Channel/WhatsAppAdapter.php` | `parseStatuses()`; captura de `wamid` en `send()`; clasificación de errores Graph; bifurcación free-form vs template. |
| `Channel/InboundDispatcher.php` | Ruteo de eventos `status` al registro de entregas. |
| `Channel/` (nuevos) | `ChannelDeliveryRepository`, `ChannelTemplateRepository`, resolver de variables, helper de ventana 24h. |
| `Core/Migrator.php` | `migrateTo10()`: `channel_templates` + `channel_deliveries`. `INFOUNO_DB_VERSION = '10'`. |
| `ia/` | Sincronizar `taxonomy.md` / `architecture.md` / `branch-registry.md` (capa de canales, v10). |

---

## 5. Costuras dejadas explícitamente para después

1. **Polling/async web** (A.4): se activa solo si la telemetría muestra cortes por duración de conexión. Núcleo intacto.
2. **Disparo de templates en mensajería proactiva**: la infra de B.4 queda lista; el *uso* proactivo llega con Sales Automation (fuera de alcance).

---

## 6. Reglas no negociables respetadas

- Toda query SQL nueva incluye filtro `tenant_id`.
- Migración solo en `Migrator.php`, sin `DROP`, `INFOUNO_DB_VERSION` incrementado a `10`.
- Nuevos endpoints (si `?mode=async` se construye en el futuro) solo en `RestRouter.php` con `permission_callback`.
- El `bot_token` y credenciales de canal nunca en logs en texto plano.
- Lead Engine sigue best-effort.
