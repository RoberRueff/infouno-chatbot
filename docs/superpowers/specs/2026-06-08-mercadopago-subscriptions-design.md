# MercadoPago Suscripciones (premium) — Design Spec

**Fecha:** 2026-06-08
**Estado:** Aprobado (brainstorming) — pendiente de plan de implementación
**Branch:** `feature/mercadopago-subscriptions`

## Goal

Monetizar el SaaS permitiendo que un tenant se suscriba **self-service** al plan **premium** mediante **MercadoPago Suscripciones (preapproval)** — débito mensual recurrente en ARS. El plan se activa al autorizarse la suscripción, se mantiene mientras se pague, y se **suspende de inmediato** ante el primer rechazo de pago.

## Decisiones tomadas (brainstorming)

1. **Modelo de cobro:** MercadoPago Suscripciones (preapproval), débito recurrente mensual en ARS.
2. **Alcance de planes:** self-service **solo premium**. `agency` y cambios de plan (premium↔agency) quedan fuera (futuro). `free` sigue siendo el default gratuito; `trial` sigue activándose manual por el superadmin.
3. **Fallo de pago:** **suspensión inmediata** al primer rechazo (`tenant.status = 'suspended'`). Sin período de gracia/dunning en el MVP.
4. **Configuración:** precio premium (ARS) + credenciales MP configurables desde **WP Admin** (con override por constante de entorno para los secretos).
5. **Confianza en webhooks (Enfoque A):** el webhook es solo un "ping"; se **verifica la firma** (`x-signature` HMAC + `ts` anti-replay) y se **trae el estado autoritativo desde la API de MP** antes de reconciliar. Justificación: la firma de MP cubre `id;request-id;ts` (no el `status`), y la notificación trae solo el ID del recurso → leer el estado real es obligatorio, no opcional.

## Contexto del codebase (estado actual)

- Tabla `wp_infouno_tenants`: `status VARCHAR(20) DEFAULT 'active'`, `plan VARCHAR(50) DEFAULT 'free'`, `quota_limit`, `quota_used`, `quota_reset_at`. **Sin** columnas de pago.
- `TenantManager::PLAN_QUOTAS = [ free=>50_000, trial=>200_000, premium=>2_000_000, agency=>20_000_000 ]`. `SELF_SERVICE_PLANS = ['free']`.
- `TenantManager::validateForChat()` ya devuelve **403** si `status='suspended'` y **402** para otros estados no-activos → el gancho de "suspendido" ya está respetado por el chat.
- `INFOUNO_DB_VERSION` en `v10`. Migraciones idempotentes vía `Core\Migrator` (dbDelta, sin DROP).
- **Bloque D cerrado:** guard estático `NoRawSqlOutsidePersistenceTest` con **allowlist vacía** → todo SQL nuevo DEBE vivir en un repo/manager que extienda `Persistence\TenantScopedRepository` (con `guardScope()`). Ningún `$wpdb->` en `src/API` ni `src/Admin`.
- Patrón de webhook idempotente ya existente para canales (WhatsApp/Telegram) registrado en `API\RestRouter`.
- Tests backend: PHPUnit en Docker `php:8.3-cli` (no hay PHP local), con `WpdbStub` en `tests/bootstrap.php`.

## Arquitectura y componentes (nuevos)

| Componente | Namespace | Responsabilidad | Dependencias |
|---|---|---|---|
| `MercadoPagoClientInterface` + `MercadoPagoClient` | `Infouno\SaaS\Billing` | Cliente HTTP sobre la API de MP: `createPreapproval(array): array`, `getPreapproval(string $id): array`, `getPayment(string $id): array`, `cancelPreapproval(string $id): bool`. Lee el access token. Sin DB. La interface permite mockear en tests. | Un transporte HTTP inyectable (wrapper sobre `wp_remote_post`/`wp_remote_get`). |
| `WebhookSignatureVerifier` | `Infouno\SaaS\Billing` | `verify(string $signatureHeader, string $requestId, string $dataId, int $nowTs): bool` — recomputa el HMAC-SHA256 sobre el manifiesto `id:<dataId>;request-id:<requestId>;ts:<ts>;` con el webhook secret y compara (hash_equals); rechaza si `\|now - ts\| > ventana` (ej. 300 s). Puro. | webhook secret |
| `SubscriptionService` | `Infouno\SaaS\Billing` | Orquestación: `createSubscription(array $tenant): string` (init_point) y `reconcileFromNotification(string $type, string $resourceId): void` (fetch autoritativo + transición idempotente). Centraliza la máquina de estados. | MercadoPagoClientInterface, SubscriptionRepository, TenantManager |
| `SubscriptionRepository` | `Infouno\SaaS\Persistence` | **extends `TenantScopedRepository`**. Todo el SQL de `subscriptions` + `payment_events`. Métodos: `createPending()`, `findByPreapprovalId()`, `findActiveForTenant()`, `markAuthorized()`, `markCancelled()`, `recordPaymentEvent()` (idempotente por `mp_payment_id`), `updateNextPayment()`, `lastEventTs()`. | base (`$this->db`) |
| `BillingController` | `Infouno\SaaS\API` | REST: `subscribe`, `webhook`, `subscription`, `cancel`. Cero `$wpdb` (delega a servicio/repo). | SubscriptionService, SubscriptionRepository, TenantManager |
| `BillingSettings` | `Infouno\SaaS\Admin` | Página WP Admin: precio premium (ARS) + credenciales MP (enmascaradas). Persistencia en `wp_options` (`infouno_billing`). | — |
| `BillingConfig` | `Infouno\SaaS\Billing` | Helper de lectura de config: `accessToken()`, `webhookSecret()`, `publicKey()`, `premiumPriceArs()` — resuelve **constante de entorno primero, setting después**. | — |

## Modelo de datos (migración v10 → **v11**)

`INFOUNO_DB_VERSION` → `'11'`; `Core\Migrator::migrateTo11()` crea dos tablas (idempotente, dbDelta, sin DROP).

### `wp_infouno_subscriptions`
```sql
id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
tenant_id         BIGINT UNSIGNED NOT NULL,
mp_preapproval_id VARCHAR(255)    NOT NULL,
plan              VARCHAR(50)     NOT NULL DEFAULT 'premium',
status            VARCHAR(20)     NOT NULL DEFAULT 'pending',  -- pending|authorized|cancelled
amount            DECIMAL(12,2)   NOT NULL DEFAULT 0,
currency          VARCHAR(3)      NOT NULL DEFAULT 'ARS',
next_payment_at   DATETIME        NULL,
last_event_ts     BIGINT UNSIGNED NOT NULL DEFAULT 0,         -- anti-replay / orden
created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
UNIQUE KEY uq_preapproval (mp_preapproval_id),
KEY tenant (tenant_id),
KEY status (status)
```
> `status` acá es el estado de la **suscripción** (espejo de MP), distinto de `tenant.status`.

### `wp_infouno_payment_events` (idempotencia + audit trail)
```sql
id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
tenant_id         BIGINT UNSIGNED NOT NULL,
mp_payment_id     VARCHAR(255)    NOT NULL,
mp_preapproval_id VARCHAR(255)    NOT NULL,
status            VARCHAR(20)     NOT NULL,                   -- approved|rejected|...
amount            DECIMAL(12,2)   NOT NULL DEFAULT 0,
processed_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
UNIQUE KEY uq_payment (mp_payment_id),
KEY tenant (tenant_id)
```
> `UNIQUE(mp_payment_id)` hace idempotente cada cobro recurrente y deja evidencia (como el audit trail de consents).

## Máquina de estados (efecto sobre `tenant`)

Toda transición se decide con el **estado autoritativo traído de MP**, no con el body del webhook.

| Evento (autoritativo) | `subscriptions.status` | `tenant` resultante |
|---|---|---|
| Alta → preapproval `pending` | `pending` | sin cambios (`free`) |
| preapproval `authorized` | `authorized` | `plan='premium'`, `status='active'`, `quota_limit=2_000_000`, `quota_reset_at` = +1 mes |
| pago recurrente `approved` | (sigue `authorized`) | `status='active'`, `next_payment_at` actualizado |
| pago recurrente `rejected` | (sigue `authorized`) | **`status='suspended'`** (inmediato) |
| preapproval `cancelled` | `cancelled` | `plan='free'`, `status='active'`, `quota_limit=50_000` |

**Reactivación:** si tras un `rejected` (suspended) entra un pago `approved`, el tenant vuelve a `status='active'`.
**Idempotencia:** un `mp_payment_id` ya presente en `payment_events` no re-aplica efecto. Un evento con `ts` ≤ `last_event_ts` se ignora (out-of-order).

## Flujos

### Alta (subscribe)
1. Tenant logueado con tenant activo → `POST /infouno/v1/billing/subscribe`.
2. `requireForCurrentUser()` resuelve el tenant. Si ya existe una sub `authorized`/`pending` → **409**.
3. `SubscriptionService::createSubscription()` arma el preapproval en MP: `auto_recurring { frequency:1, frequency_type:'months', transaction_amount: <precio setting>, currency_id:'ARS' }`, `payer_email` (email del dueño del tenant), `reason`, `back_url`, `status:'pending'`.
4. Persiste la fila `subscriptions` (`pending`, con `mp_preapproval_id`). Devuelve `{ init_point }`.
5. El front redirige al `init_point`. El tenant autoriza el débito en MP.
6. MP redirige al `back_url` **y** dispara el webhook → activación (abajo).

### Webhook (reconcile)
1. `POST /infouno/v1/billing/webhook` (público).
2. `WebhookSignatureVerifier::verify(...)` con header `x-signature`, `x-request-id`, `data.id`, `ts`. Si falla → responder **200** sin efecto (no revelar; MP no debe reintentar un request inválido) y loguear el rechazo (sin secretos).
3. `SubscriptionService::reconcileFromNotification(type, resourceId)`:
   - `type='subscription_preapproval'` → `getPreapproval(id)` → según `status` (`authorized`/`cancelled`) aplica transición.
   - `type='payment'` → `getPayment(id)` → `recordPaymentEvent()` idempotente → `approved`/`rejected` aplica transición.
   - Resuelve el `tenant_id` desde `subscriptions` por `mp_preapproval_id` (el `preapproval_id` viene en el recurso de MP).
4. Responde **200** rápido siempre que la firma sea válida (incluso si el evento ya estaba procesado).

### Cancelación
- `POST /infouno/v1/billing/cancel` → `cancelPreapproval(id)` en MP. El downgrade a `free` lo aplica el webhook `cancelled` (fuente única de verdad; evita divergencia si la llamada y el webhook compiten).

## Endpoints REST (en `RestRouter`)

| Método + ruta | `permission_callback` | Respuesta |
|---|---|---|
| `POST /infouno/v1/billing/subscribe` | login + tenant activo | `{ init_point }` 200 · 409 si ya suscripto |
| `POST /infouno/v1/billing/webhook` | `__return_true` (firma validada adentro) | 200 siempre (firma ok); ignora si firma inválida |
| `GET /infouno/v1/billing/subscription` | login + tenant | `{ plan, status, next_payment_at }` |
| `POST /infouno/v1/billing/cancel` | login + tenant | `{ cancelled: true }` |

## Seguridad

- **Secretos** (`access_token`, `webhook_secret`): `BillingConfig` los lee de **constante de entorno** (`INFOUNO_MP_ACCESS_TOKEN`, `INFOUNO_MP_WEBHOOK_SECRET`) y, si no están, del setting de WP Admin. **Nunca** se loguean ni se devuelven en respuestas; en el panel se muestran enmascarados.
- **Webhook**: firma HMAC + ventana de `ts` (anti-replay) → fetch autoritativo → idempotencia por `mp_payment_id` → guarda por `last_event_ts` (orden).
- **Aislamiento (Bloque D)**: `SubscriptionRepository extends TenantScopedRepository` con `guardScope()`. `tenant_id` del servidor (subscribe) o del mapeo `preapproval_id→tenant` (webhook), nunca del request.
- **Reglas de merge** del proyecto: toda query con `tenant_id`; `INFOUNO_DB_VERSION` incrementado + `migrateTo11()`; endpoints con `permission_callback`.

## Settings (WP Admin — `BillingSettings`)

Página bajo el menú del plugin: **precio mensual premium (ARS)**, **public key**, **access token** (enmascarado), **webhook secret** (enmascarado). Persistencia en una opción `infouno_billing` (`get_option`/`update_option`, ya stubeados en tests). Validación: precio > 0; deshabilitar el botón de suscripción si falta el access token.

## Testing (PHPUnit en Docker + WpdbStub)

- `WebhookSignatureVerifierTest` — firma válida/inválida, `ts` fuera de ventana (replay), header roto.
- `MercadoPagoClientTest` — transporte HTTP **inyectado/mockeado** (no pega a MP): arma bien el preapproval, parsea preapproval/payment, maneja 4xx/5xx.
- `SubscriptionServiceTest` — transiciones authorized→premium, payment approved→active, rejected→suspended, cancelled→free; **idempotencia** (mismo `payment_id` ×2 = 1 efecto); evento viejo (`ts` menor) ignorado; reactivación tras rejected.
- `SubscriptionRepositoryTest` — fail-closed (`guardScope`), `tenant_id` en cada WHERE/INSERT, idempotencia por `mp_payment_id`.
- `BillingControllerTest` — subscribe 200/409, webhook firma inválida → sin cambio de estado, `subscription` devuelve estado del tenant, `cancel` invoca `cancelPreapproval`.
- `MigratorV11Test` — `migrateTo11` emite el DDL de ambas tablas con las columnas/índices esperados; `INFOUNO_DB_VERSION='11'`.

## Fuera de alcance (YAGNI — incrementos futuros)

Plan **agency** y **cambios de plan** (premium↔agency) · período de gracia / dunning · cron de reconciliación (red de seguridad ante webhooks perdidos) · reembolsos · facturas/UI de historial · proración. El diseño deja los enganches (la tabla `subscriptions` admite otros planes; `SubscriptionService` centraliza las transiciones) pero el MVP cobra **solo premium con suspensión inmediata**.

## Riesgos / notas

- **Formato del `x-signature`/topic de MP**: confirmar contra la doc vigente de MercadoPago al implementar `WebhookSignatureVerifier` y el ruteo `type` del webhook (la API evoluciona). El manifiesto de firma asumido es `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`.
- **Email del payer**: se toma del dueño del tenant (usuario WP). Confirmar que ese dato esté disponible en `create`/`getForCurrentUser`.
- **back_url**: requiere una URL pública del sitio; en local/staging usar la URL del entorno.
