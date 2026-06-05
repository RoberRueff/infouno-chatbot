# Diseño — Fase 2: Canal WhatsApp (Cloud API)

> Estado: Aprobado para implementación
> Fecha: 2026-06-05
> Alcance: agregar WhatsApp como segundo canal sobre la columna vertebral multicanal (Fase 1).

---

## 1. Contexto y objetivo

La Fase 1 entregó la columna vertebral multicanal asíncrona + Telegram, validada end-to-end en runtime real. WhatsApp es el canal de mayor valor comercial para PyMEs argentinas. La abstracción de canales se diseñó para esto: **agregar WhatsApp = un adapter nuevo + registrarlo**, más dos extensiones puntuales que WhatsApp requiere y Telegram no (el GET de verificación de Meta y el manejo de mensajes no-texto).

No hay migración de BD: `wp_infouno_channels` ya soporta cualquier `channel_type`, credenciales cifradas y routing por `routing_key`.

### Decisiones tomadas (brainstorming)
- **Solo inbound + texto libre** dentro de la ventana de 24h (respondemos a quien nos escribió → sin plantillas aprobadas ni gestión de ventana). Outbound/plantillas = fase futura.
- **No-texto** (audio, foto, sticker, ubicación) → el bot responde un mensaje fijo pidiendo texto (no transcribe). Mejor UX de captación en AR que ignorar.
- **GET de verificación** → interfaz segregada `WebhookChallengeInterface` (Telegram no la implementa).
- **BYO credenciales**, cifradas con `CredentialVault` (igual que Telegram).

---

## 2. Componentes

### `WhatsAppAdapter` (nuevo — `Infouno\SaaS\Channel\WhatsAppAdapter`)

Implementa `ChannelAdapterInterface` y `WebhookChallengeInterface`.

```php
public function type(): string;                                   // 'whatsapp'
public function verifyWebhook( \WP_REST_Request $req, array $channel ): bool;
public function verifyChallenge( \WP_REST_Request $req, array $channel ): ?string;
public function parseInbound( array $payload ): ?InboundMessage;
public function send( array $channel, string $externalUser, string $text ): void;
public function splitMessage( string $text ): array;
```

- **`verifyWebhook`**: calcula `hash_hmac('sha256', <body crudo>, $appSecret)` y compara con el header `X-Hub-Signature-256` (formato `sha256=<hex>`) usando `hash_equals`. El body crudo se obtiene de `$req->get_body()`. El `app_secret` sale de las credenciales descifradas (`$channel['credentials_decrypted']['app_secret']`). Rechaza si falta el secret o el header.
- **`verifyChallenge`**: lee `hub.mode`, `hub.verify_token`, `hub.challenge` de los query params. Si `hub.mode === 'subscribe'` y `hub.verify_token === $channel['credentials_decrypted']['verify_token']` (hash_equals) → devuelve el string `hub.challenge`. Si no → `null`.
- **`parseInbound`**: navega `payload['entry'][0]['changes'][0]['value']`.
  - Si hay `value['messages'][0]` con `type === 'text'` → `InboundMessage('whatsapp', from, text.body, id, kind:'text')` donde `from = messages[0]['from']`, `id = messages[0]['id']`.
  - Si hay `value['messages'][0]` con `type !== 'text'` (audio/image/sticker/location/...) → `InboundMessage('whatsapp', from, '', id, kind:'unsupported')`.
  - Si no hay `messages` (p.ej. `value['statuses']` = delivery/read receipts) → `null`.
- **`send`**: descifra `access_token` + `phone_number_id` de las credenciales. Por cada chunk de `splitMessage`: POST a `https://graph.facebook.com/v21.0/{phone_number_id}/messages` con header `Authorization: Bearer {access_token}` y body `{ messaging_product:'whatsapp', to: $externalUser, type:'text', text:{ body: $chunk } }`. Loguea (sin token) si la respuesta es `code 0 || >= 400`, sin lanzar (igual que TelegramAdapter, para no re-cobrar reprocesando).
- **`splitMessage`**: corta en 4096 (límite de texto de WhatsApp), igual que Telegram.
- Constructor: `__construct( CredentialVault $vault, ChannelHttpClient $http )` (igual que TelegramAdapter).

### `WebhookChallengeInterface` (nuevo — `Infouno\SaaS\Channel\WebhookChallengeInterface`)

```php
interface WebhookChallengeInterface {
    /** Devuelve el string a echo-ear si el challenge GET es válido, o null. */
    public function verifyChallenge( \WP_REST_Request $request, array $channel ): ?string;
}
```

Interfaz segregada: solo los canales que requieren handshake GET (Meta) la implementan. El controller la consulta vía `instanceof`.

### `ChannelWebhookController` (modificado)

1. **Nueva ruta GET** `/channels/(?P<type>[a-z]+)/(?P<key>[A-Za-z0-9_\-]+)` (`READABLE`, `permission_callback => '__return_true'`) → `handleChallenge()`:
   - Resuelve el canal por `routing_key` (404 si no existe o el `channel_type` no coincide).
   - Resuelve el adapter. Si NO es `WebhookChallengeInterface` → 404.
   - `$challenge = $adapter->verifyChallenge($req, $channel)`. Si `null` → 403. Si string → devuelve **el string crudo** con `Content-Type: text/plain` y 200 (Meta espera el challenge tal cual en el body).
2. **No encolar si `parseInbound` da null** en `handle()` (POST): los status/read receipts de WhatsApp son frecuentes; si no hay mensaje procesable, responder 200 sin encolar (evita jobs no-op en Action Scheduler). Reestructura: parsear una vez; `null` → 200 `{ok:true, ignored:true}`; si no → dedup + enqueue.

### `InboundMessage` (modificado)

Agrega `public readonly string $kind = 'text'` como último parámetro del constructor (retrocompatible: TelegramAdapter no lo pasa → `'text'`). Valores: `'text'` | `'unsupported'`. `conversationKey()` no cambia (usa `externalUser`, presente también en no-texto).

### `InboundDispatcher` (modificado)

En `handle()`, tras `parseInbound`, antes de cargar el bot:
```php
if ( 'unsupported' === $inbound->kind ) {
    $this->trySend( $adapter, $channel, $inbound->externalUser, self::UNSUPPORTED );
    return;
}
```
Nueva constante `UNSUPPORTED = 'Por ahora solo puedo leer mensajes de texto. ¿Me contás tu consulta por escrito? 🙂'`. No corre consentimiento ni pipeline para no-texto (se manejan cuando el usuario manda texto real).

### `Plugin.php` (modificado)

En el bloque de canales (condicional a `INFOUNO_ENCRYPTION_KEY`), registrar el adapter:
```php
$this->channelRegistry->register( new WhatsAppAdapter( $vault, new WpHttpClient() ) );
```

---

## 3. Routing y credenciales

- **Routing**: cada tenant tiene su propia app de Meta (BYO) y configura el webhook de esa app apuntando a `/wp-json/infouno/v1/channels/whatsapp/{routing_key}` (token aleatorio por canal, igual que Telegram). El mismo `routing_key` resuelve el canal en GET (challenge) y POST (mensajes).
- **Credenciales** (cifradas con `CredentialVault`, en `wp_infouno_channels.credentials`):
  ```json
  { "access_token": "...", "phone_number_id": "...", "app_secret": "...", "verify_token": "..." }
  ```
- **Alta del canal**: vía `ChannelRepository::create(tenantId, botId, 'whatsapp', routingKey, credentials, webhookSecret, displayName)`. (`webhook_secret` no se usa en WhatsApp — la firma usa `app_secret`; se puede dejar vacío.)

---

## 4. Manejo de errores

| Situación | Comportamiento |
|---|---|
| GET challenge con `verify_token` correcto | 200 + echo del `hub.challenge` (text/plain) |
| GET challenge inválido | 403 |
| POST con firma `X-Hub-Signature-256` inválida | 403, no se encola (igual que Telegram) |
| POST status/receipt (sin `messages`) | `parseInbound` → null → 200 sin encolar |
| Mensaje no-texto | encolado → dispatcher envía respuesta fija pidiendo texto, sin pipeline |
| Mensaje de texto duplicado (retry de Meta) | dedup por `external_msg_id` (`messages[0].id`) — ya existe en la columna vertebral |
| Falla el envío saliente (5xx/timeout) | se loguea sin token, no se lanza (no re-cobra reprocesando) |

---

## 5. Testing

- **`WhatsAppAdapter`** (unit): `parseInbound` con payload de texto, de no-texto (kind='unsupported'), y de status (null); `verifyWebhook` con firma HMAC válida e inválida (usando un `app_secret` conocido y `get_body()` stub); `verifyChallenge` con verify_token correcto/incorrecto y mode subscribe; `send` POSTea a la URL correcta con Bearer y body de WhatsApp (con un `ChannelHttpClient` fake + token cifrado); `splitMessage` en 4096.
- **`InboundMessage`**: `kind` default 'text' y explícito 'unsupported'.
- **`InboundDispatcher`**: mensaje `unsupported` → envía la respuesta fija, no corre el pipeline.
- **`ChannelWebhookController`**: GET con adapter que implementa `WebhookChallengeInterface` → 200 + challenge; adapter sin la interfaz → 404; POST con `parseInbound` null → no encola.
- **Regresión**: la suite completa verde (Telegram y el resto sin cambios de comportamiento).
- **Smoke-test (runtime real)**: simular el GET de verificación y un POST de WhatsApp con firma HMAC válida + payload de Meta contra el endpoint en el WP local; correr el worker; verificar conversación creada con `channel='whatsapp'` y el ciclo (con clave LLM dummy, llega al LLM y reintenta como Telegram).

---

## 6. Guardrails respetados
- **Aislamiento multitenant**: el `tenant_id` sale del canal resuelto por `routing_key`, nunca del payload.
- **Credenciales cifradas at-rest**; `app_secret`/`access_token` nunca en logs.
- **Firma verificada antes de procesar/encolar** (autorización real del endpoint público).
- **Sin migración de BD / sin DROP.**
- **Reutiliza** ChatPipeline (con la cuota dura de la fase anterior), consentimiento por primer mensaje, idempotencia y el worker de Action Scheduler — sin duplicar.
