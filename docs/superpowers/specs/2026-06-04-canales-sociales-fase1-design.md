# Diseño — Canales Sociales (Fase 1: Columna Vertebral + Telegram)

> Estado: Aprobado para implementación
> Fecha: 2026-06-04
> Versión de BD objetivo: `INFOUNO_DB_VERSION = '9'`
> Alcance: Fase 1 de 3. Habilita la arquitectura multicanal asíncrona y entrega Telegram como canal de referencia.

---

## 1. Contexto y objetivo

El producto actual capta leads vía un **widget web embebido** (SSE síncrono en el sitio del cliente). El objetivo comercial es **captar potenciales clientes de PyMEs argentinas en redes sociales**. Esta fase construye la infraestructura para recibir y responder mensajes de redes sociales de forma autónoma, reutilizando el pipeline de chat existente, y la valida end-to-end con **Telegram**.

WhatsApp Cloud API (Fase 2) e Instagram DM + Messenger (Fase 3) se sumarán implementando el mismo contrato de adapter, sin tocar la columna vertebral.

### Decisiones tomadas en brainstorming

| Decisión | Elección | Razón |
|---|---|---|
| Canales objetivo | WhatsApp Cloud API, Instagram DM, Messenger, Telegram (+ widget web) | Cobertura de las redes relevantes para PyMEs argentinas |
| Modelo conversacional | **Bot autónomo 100%** (sin inbox humano en v1) | Reutiliza el pipeline actual; mínima complejidad operativa |
| Infraestructura async | **Action Scheduler** | Sin infra extra sobre WordPress; reintentos y logs incluidos |
| Onboarding de credenciales | **BYO ahora, diseñado para migrar a Embedded Signup** | Lanzar rápido sin App Review de Meta, sin rehacer la capa después |
| Alcance de mensajería | **Solo inbound** (v1) | Sin plantillas ni costos de Meta por iniciar conversación |
| Consentimiento (Ley 25.326) | **Por primer mensaje + evidencia server-side** | Estándar legal para chat inbound; baja fricción |
| Enfoque arquitectónico | **B — Pipeline transport-agnostic (sinks)** | Cero duplicación del pipeline; ambos transportes de primera clase |
| Canal de referencia Fase 1 | **Telegram** | API gratis, sin verificación de negocio ni firma compleja de Meta |

---

## 2. Arquitectura general

```
                          REDES SOCIALES (Telegram en Fase 1)
                                      │ webhook POST
                                      ▼
┌─────────────────────────────────────────────────────────────────┐
│  BACKEND — WORDPRESS PLUGIN (nuevo namespace Infouno\SaaS\Channel) │
│                                                                   │
│  1. ChannelWebhookController  (REST /infouno/v1/channels/{type})  │
│     ├─ verifica firma/secreto del canal                           │
│     ├─ dedup por external_message_id (idempotencia)               │
│     ├─ ack inmediato (HTTP 200) ← Meta/Telegram no reintentan     │
│     └─ as_enqueue_async_action('infouno_process_inbound', payload)│
│                          │                                        │
│                          ▼   (Action Scheduler, background)       │
│  2. InboundDispatcher (worker)                                    │
│     ├─ ChannelRegistry::resolve(type) → ChannelAdapter            │
│     ├─ adapter->parseInbound(payload) → InboundMessage normalizado│
│     ├─ ChannelRepository: routing_key → bot + tenant              │
│     ├─ ChannelConsentService: ¿primer contacto? → welcome+legal   │
│     ├─ ChatPipeline::run(bot, convKey, texto, BufferedSink) ◄─────┤ REUTILIZA
│     │      (mismas 12 etapas: InputGuard, tenant, cuota,          │  pipeline
│     │       rate-limit, LLMRouter, Lead Engine)                   │  existente
│     └─ adapter->send(externalUserId, respuestaCompleta)           │
│                                                                   │
│  WIDGET WEB existente → ChatController → ChatPipeline::run(StreamingSink)
└─────────────────────────────────────────────────────────────────┘
```

### Componentes nuevos — namespace `Infouno\SaaS\Channel`

| Componente | Responsabilidad | Depende de |
|---|---|---|
| `ChannelAdapterInterface` | Contrato: `type()`, `verifyWebhook()`, `parseInbound()`, `send()`, `splitMessage()` | — |
| `TelegramAdapter` | Implementación Fase 1 del contrato | `CredentialVault`, HTTP client |
| `ChannelRegistry` | Resuelve `channel_type` → adapter. Punto único de extensión | adapters |
| `ChannelWebhookController` | Endpoint REST por canal: verifica, deduplica, ack, encola | `ChannelRepository`, `ChannelRegistry` |
| `InboundDispatcher` | Handler del job Action Scheduler: worker → pipeline → send | `ChannelRegistry`, `ChannelRepository`, `ChannelConsentService`, `ChatPipeline` |
| `ChannelRepository` | CRUD de conexiones de canal por tenant. Routing inbound → bot | `$wpdb`, `CredentialVault` |
| `CredentialVault` | Cifra/descifra tokens del tenant at-rest (clave en `wp-config.php`) | `INFOUNO_ENCRYPTION_KEY` |
| `ChannelConsentService` | Consentimiento por primer mensaje + evidencia server-side | `ConversationRepository`/consents |
| `InboundMessage` | Value object: `externalUser`, `text`, `externalMsgId`, `channelType`, `raw` | — |

### Refactor sobre código existente

- `Infouno\SaaS\Chat\ChatService` → se extrae el pipeline a `Infouno\SaaS\Chat\ChatPipeline::run(...)`.
- La validación de **Origin (paso 2 actual)** sale del pipeline → pasa a ser responsabilidad del transporte web (`ChatController::preValidate` ya la realiza). Los canales no la necesitan.
- Se introduce `Infouno\SaaS\Chat\OutputSink` (interfaz) con dos implementaciones: `StreamingSink` (web/SSE) y `BufferedSink` (canales).
- `ChatService` queda como **fachada delgada**: arma el `StreamingSink` y delega en `ChatPipeline`. El widget web no cambia de cara al usuario.

---

## 3. Modelo de datos y migración (v8 → v9)

Convención existente: `Migrator::createX()` idempotente con `dbDelta()`, sin `DROP`, `INFOUNO_DB_VERSION` → `'9'`. Las extensiones de columnas usan check previo de `INFORMATION_SCHEMA.COLUMNS` (patrón `migrateToN` existente).

### Tabla nueva: `wp_infouno_channels`

```sql
id              INT UNSIGNED PK AUTO_INCREMENT
tenant_id       INT UNSIGNED  INDEX          -- aislamiento multitenant
bot_id          INT UNSIGNED  INDEX          -- a qué bot enruta este canal
channel_type    VARCHAR(20)                  -- 'telegram' | 'whatsapp' | 'instagram' | 'messenger'
routing_key     VARCHAR(191) UNIQUE          -- resuelve inbound → canal (token en URL / phone_number_id / page_id)
credentials     TEXT                         -- JSON CIFRADO (CredentialVault): bot token, secrets
webhook_secret  VARCHAR(64)                  -- secreto para verificar el webhook entrante
status          VARCHAR(20) DEFAULT 'active'  -- active | disabled | error
display_name    VARCHAR(100) NULL            -- nombre legible (ej: "@MiPymeBot")
created_at      DATETIME
updated_at      DATETIME
```

`routing_key` es la pieza central del enrutamiento: el webhook entrante trae un identificador (token en la URL para Telegram; `phone_number_id`/`page_id` para Meta) que resuelve unívocamente `→ tenant + bot`. Las credenciales del tenant van **cifradas** con `CredentialVault` usando una clave maestra en `wp-config.php` (`INFOUNO_ENCRYPTION_KEY`), nunca en texto plano — consistente con el guardrail "sin credenciales en código".

### Tabla nueva: `wp_infouno_channel_events` (idempotencia)

```sql
id              BIGINT UNSIGNED PK
channel_type    VARCHAR(20)
external_msg_id VARCHAR(191)                 -- message_id del proveedor
received_at     DATETIME
UNIQUE KEY (channel_type, external_msg_id)   -- INSERT IGNORE; si existe, se descarta
```

Meta y Telegram reintentan webhooks si no reciben ack a tiempo. El `INSERT ... IGNORE` sobre esta UNIQUE garantiza procesamiento **único** aunque Action Scheduler corra dos veces. Purga vía wp_cron (eventos > 7 días).

### Extensión de `wp_infouno_conversations`

```sql
+ channel        VARCHAR(20)  DEFAULT 'web'  -- 'web' para lo existente; 'telegram', etc.
+ external_user  VARCHAR(191) NULL           -- chat_id de Telegram / wa_id / IG-scoped id
```

Las conversaciones de canal reusan la UNIQUE existente `(tenant_id, bot_id, session_id)`. El `session_id` para canales es **determinístico y sintético**: `"tg:<chat_id>"`. Así `getOrCreate()` funciona sin cambios y cada usuario de cada canal mantiene su hilo. El `external_user` se guarda aparte para que el adapter sepa a quién responder.

### Extensión de `wp_infouno_consents`

```sql
+ channel        VARCHAR(20) DEFAULT 'web'
```

En el primer mensaje de un canal, `ChannelConsentService` registra evidencia con `session_hash = SHA-256("tg:<chat_id>")`, `channel='telegram'`, `consent_version`, `ip_hash = NULL`.

### Lo que NO cambia

`wp_infouno_leads`, `wp_infouno_lead_consents`, `wp_infouno_messages`, `wp_infouno_bots`, `wp_infouno_tenants`. El Lead Engine recibe el `session_id` sintético y funciona igual.

---

## 4. Capa de adapters y refactor del pipeline

### OutputSink

```php
interface OutputSink {
    public function write(string $delta): void;  // un fragmento de la respuesta
    public function isAborted(): bool;            // ¿el cliente cortó? (web: connection_aborted)
    public function finish(): void;               // cierre del stream
}
```

| Sink | `write()` | `isAborted()` | `finish()` |
|---|---|---|---|
| `StreamingSink` (web) | `echo "data: ..."` + flush | `connection_aborted()` | `sendEvent('done')` |
| `BufferedSink` (canal) | acumula en buffer | `false` (no hay cliente) | no-op → `getBuffer()` |

### ChatPipeline

```php
ChatPipeline::run(
    array $bot,
    string $conversationKey,   // "abc-123" (web) | "tg:987654" (canal)
    string $userMessage,
    OutputSink $sink,
    PipelineContext $ctx       // channel, externalUser, rateLimitSecondaryKey
): StreamResult
```

Cambios respecto al `ChatService` actual:
- La validación de Origin (paso 2) **sale** del pipeline hacia el transporte web.
- La acumulación de `$fullResponse` queda **dentro del pipeline** (persiste en ambos transportes); el sink solo maneja el transporte.
- `QuotaService::checkRateLimit($sessionKey, ?$secondaryKey)`: web pasa la IP (capa 2 actual); canales pasan el `external_user`. Cambio retrocompatible.
- `ChatService` queda como fachada delgada que delega en `ChatPipeline`.

### Contrato de adapters

```php
interface ChannelAdapterInterface {
    public function type(): string;                                 // 'telegram'
    public function verifyWebhook(WP_REST_Request $req, array $channel): bool;
    public function parseInbound(array $payload): ?InboundMessage;   // null = ignorar (no-texto)
    public function send(array $channel, string $externalUser, string $text): void;
    public function splitMessage(string $text): array;              // respeta límite de chars del canal
}
```

### TelegramAdapter (Fase 1)

- **Webhook:** `POST /infouno/v1/channels/telegram/{routing_key}`. `routing_key` es un token aleatorio generado por canal y registrado en Telegram vía `setWebhook` con `secret_token`. `verifyWebhook()` compara el header `X-Telegram-Bot-Api-Secret-Token` con `webhook_secret`.
- **parseInbound:** `update.message.chat.id` → externalUser; `.text`; `.message_id` → dedup; ignora updates sin texto.
- **send:** `POST https://api.telegram.org/bot<token>/sendMessage` con `{chat_id, text}`. `splitMessage()` corta en 4096 chars.

`ChannelRegistry` mapea `channel_type → adapter`, cableado en `Plugin.php` (DI). Agregar WhatsApp/Meta = registrar un adapter nuevo, sin cambios en webhook controller, dispatcher ni pipeline.

---

## 5. Flujo end-to-end (Action Scheduler)

```
ChannelWebhookController::handle( {type}, {routing_key} )
  1. ChannelRepository::resolveByRoutingKey() → canal o 404
  2. adapter->verifyWebhook() → si falla, 403 (sin encolar)
  3. extraer external_msg_id → INSERT IGNORE en channel_events
        └─ ya existía (retry) → 200 y FIN (idempotencia)
  4. as_enqueue_async_action('infouno_process_inbound', [channel_id, payload])
  5. return 200  ← inmediato, dentro del timeout del proveedor

InboundDispatcher::handle( channel_id, payload )   [background]
  6. adapter->parseInbound(payload) → InboundMessage (o descartar si no es texto)
  7. cargar bot + tenant desde el canal
  8. ChannelConsentService::ensure() → primer contacto? envía bienvenida+aviso legal,
        registra evidencia en wp_infouno_consents + lead_consents
  9. ChatPipeline::run(bot, "tg:<chatid>", texto, BufferedSink, ctx)
 10. adapter->send(externalUser, BufferedSink::getBuffer())   // con splitMessage
```

---

## 6. Consentimiento social (Ley 25.326)

En el **primer mensaje** de un usuario de canal, `ChannelConsentService`:

1. Envía un mensaje de bienvenida con el aviso legal + link a la política de privacidad (texto configurable por bot).
2. Registra evidencia en `wp_infouno_consents` (`session_hash`, `channel`, `consent_version`).
3. Escribe `wp_infouno_lead_consents` con los flags `can_capture_*` que **declara ese aviso** (continuar la conversación = aceptación). El Lead Engine funciona sin tocarlo — sigue leyendo `lead_consents`.
4. Procesa el mensaje del usuario normalmente.

Esto mantiene la regla nº12 ("PII solo con consentimiento por campo"): el consentimiento se otorga por mensaje en vez de por checkbox, con evidencia server-side equivalente.

---

## 7. Manejo de errores

| Situación | Acción |
|---|---|
| Firma de webhook inválida | 403, no se encola. Log de seguridad sin payload sensible |
| Mensaje duplicado (retry) | Descarte silencioso vía `channel_events` UNIQUE |
| Error transitorio (LLM 5xx, red) | Action Scheduler reintenta con backoff (máx 3) |
| Cuota/tenant (402/403/429/503) | Fallback amable al usuario (reusa mapa `ERROR_MESSAGES`) y FIN, sin reintento |
| InputGuard rechaza (422) | Respuesta de rechazo cortés al usuario |
| Falla el envío saliente | Action Scheduler reintenta |
| Error del Lead Engine | Best-effort (`catch(\Throwable)`) — nunca rompe |

Observabilidad: cada inbound/outbound se registra (status ok/failed) siguiendo el patrón de `automation_logs`. **Nunca** se loguean tokens de canal ni PII.

---

## 8. Testing

- **Unit nuevos:** `TelegramAdapter` (parseInbound con payloads variados, splitMessage en 4096, verifyWebhook), `CredentialVault` (cifrado roundtrip), `ChannelRepository` (routing + aislamiento tenant), `ChannelConsentService` (lógica de primer contacto), `BufferedSink`, idempotencia (dedup).
- **Test clave del refactor:** `ChatPipeline` con un sink fake + sub-servicios mockeados — garantiza que el refactor **preserva el comportamiento** de las 12 etapas.
- **Regresión:** los tests existentes (`LeadScorer`/`LeadService`/`InputGuard`) deben seguir verdes; el flujo web (StreamingSink) sin cambios funcionales.
- Las capas nuevas nacen con cobertura.

---

## 9. Alcance explícito (qué NO incluye la Fase 1)

- WhatsApp Cloud API, Instagram DM, Messenger (Fases 2 y 3).
- Embedded Signup / Tech Provider de Meta (se mantiene BYO; la capa de credenciales queda preparada).
- Mensajería outbound / plantillas / campañas.
- Inbox de agentes humanos / handoff.
- Reacción a comentarios de publicaciones.
- UI de administración para conectar canales (se define la API/repositorio; la UI del wizard puede quedar mínima o vía WP-CLI/REST en Fase 1).

---

## 10. Guardrails y reglas del proyecto respetadas

- **Aislamiento multitenant:** toda query a `wp_infouno_channels`/`channel_events` filtra por `tenant_id`; el `tenant_id` se deriva del canal resuelto, nunca del payload del webhook.
- **Sin credenciales en código:** tokens de tenant cifrados at-rest con clave en `wp-config.php`.
- **Sin secretos en logs:** ni tokens de canal ni PII.
- **Migraciones solo en `Migrator.php`**, sin `DROP`, con `INFOUNO_DB_VERSION` incrementado a `'9'`.
- **Nuevos endpoints REST solo en `RestRouter.php`** con `permission_callback` (`__return_true` + verificación de firma del canal dentro del handler, como ya hace `/chat`).
- **Lead Engine best-effort** preservado.
- **PII solo con consentimiento** (otorgado por primer mensaje, con evidencia server-side).
