# Panel de Billing del Tenant (WP Admin) — Design Spec

**Fecha:** 2026-06-08
**Estado:** Aprobado (brainstorming) — pendiente de plan de implementación
**Branch:** `feature/tenant-billing-panel`

## Goal

Dar al tenant una superficie para **activar y gestionar su suscripción premium**: una página WP Admin (capability `read`) con su estado de plan/suscripción, un botón **Suscribirme a Premium** que lo lleva al checkout de MercadoPago, y la opción de **cancelar**. Desbloquea el cobro construido en la feature de MercadoPago (hoy los endpoints existen pero el tenant no tiene dónde hacer clic).

## Decisiones (brainstorming)

1. **Superficie:** página **WP Admin** para el tenant (capability `read`), consistente con `BotDashboard`/`LeadDashboard`. NO se crea el tema Astra front-end (queda como proyecto separado futuro).
2. **Mecanismo del botón:** handler `admin_post_` + `wp_redirect` server-side (mismo patrón que `BotWizard`). Sin JS, sin round-trip REST.
3. **Alcance acotado:** solo ver estado + suscribir + cancelar. Sin historial de pagos, sin gestión de bots/leads (siguen en sus paneles actuales), sin cambio a otros planes.

## Contexto del codebase (estado actual)

- **No existe** carpeta `themes/` (el tema Astra está referenciado en la taxonomía pero nunca se creó). Los tenants gestionan todo vía páginas WP Admin con capability `read`: `BotDashboard`, `LeadDashboard`, `OpportunityDashboard`, `BotWizard` — todas bajo el menú padre `infouno-dashboard`.
- `BillingSettings` (creado en la feature MercadoPago) es del **superadmin** (`manage_options`): configura precio + credenciales. NO es para el tenant.
- Feature MercadoPago (v11, ya en `main`) provee:
  - `Billing\SubscriptionService`: `createSubscription(array $tenant, string $payerEmail, float $price): string` (devuelve init_point; lanza `RuntimeException('already_subscribed')` si ya hay sub activa, u otros mensajes), `cancelSubscription(string $preapprovalId): void`.
  - `Persistence\SubscriptionRepository`: `findActiveForTenant(int $tenantId): ?array` (sub `pending`/`authorized`).
  - `Billing\BillingConfig`: `premiumPriceArs(): float`, `isConfigured(): bool`, `accessToken()`, `webhookSecret()`.
  - `Billing\MercadoPagoClient(HttpClientInterface, string $token)` + `WpHttpClient`.
  - `Tenant\TenantManager`: `getForCurrentUser(): ?array` (fila del tenant: `id`, `user_id`, `plan`, `status`).
  - `API\RestRouter::register()` construye el `SubscriptionService` inline (mp client + repo + tenantManager + `home_url('/billing/return')`).
- Máquina de estados (recordatorio): pago `rejected` → `tenant.status='suspended'` pero la fila `subscriptions` queda `authorized`. Es decir, un tenant suspendido **igual tiene** una sub activa según `findActiveForTenant`.
- **Bloque D (guard total):** `NoRawSqlOutsidePersistenceTest` falla el build ante `$wpdb->` en `src/Admin`. El panel NO puede tener SQL crudo — delega en repo/manager.
- Tests backend: PHPUnit en Docker `php:8.3-cli`; `WpdbStub` en `tests/bootstrap.php`.

## Arquitectura y componentes

| Componente | Namespace | Responsabilidad |
|---|---|---|
| `TenantBillingPanel` | `Infouno\SaaS\Admin` | Página WP Admin (capability `read`) para el tenant: render del estado + handlers `admin_post_` de subscribe/cancel. Cero `$wpdb`. |
| `BillingServiceFactory` | `Infouno\SaaS\Billing` | `create(TenantManager): SubscriptionService` — arma el servicio (BillingConfig → MercadoPagoClient(WpHttpClient, token) + SubscriptionRepository + tenantManager + backUrl). Usado por `RestRouter` **y** `TenantBillingPanel` (DRY: hoy esa construcción está inline en RestRouter). |

`RestRouter` se refactoriza para usar `BillingServiceFactory::create()` en vez de construir el servicio inline (cambio acotado, mismo comportamiento).

## Flujo de pantalla (`renderPage`)

1. Resolver tenant con `TenantManager::getForCurrentUser()`. Si no hay → mensaje "sin tenant activo" (no fatal).
2. Leer `plan`/`status` del tenant y `findActiveForTenant()` para la suscripción.
3. Mostrar notices de `?subscribed=cancel_requested` / `?error=...` (de los redirects de los handlers).
4. Render según estado:

| Condición | Render |
|---|---|
| `BillingConfig::isConfigured()` == false | Aviso: "El administrador aún no configuró los pagos." (sin botón de suscribir) |
| `status='suspended'` | Aviso de pago rechazado + cuenta suspendida + botón **Cancelar suscripción** |
| `plan='premium'` y sub `authorized` | "Premium activo" + `next_payment_at` + botón **Cancelar suscripción** |
| free / sin sub activa (y configurado) | "Plan Free" + botón **Suscribirme a Premium** |

> El orden de evaluación importa: `suspended` se chequea antes que `premium activo` (un suspendido puede tener plan premium + sub authorized).

## Handlers (admin-post)

Registrados en `init()` con `add_action('admin_post_<action>', ...)`. Mismo patrón que `BotWizard::handleSave`.

### `handleSubscribe` (`admin_post_infouno_subscribe`)
1. `check_admin_referer(self::ACTION_SUBSCRIBE)`.
2. `$tenant = TenantManager::getForCurrentUser()`; si no → `wp_die(403)`.
3. `$email = get_userdata($tenant['user_id'])->user_email`.
4. `try { $initPoint = $service->createSubscription($tenant, $email, $config->premiumPriceArs()); wp_redirect($initPoint); exit; }`
5. `catch (\RuntimeException $e)` → `error_log` del detalle; `wp_safe_redirect(panel . '?error=subscribe')`; `exit`. (No expone el mensaje interno; el panel muestra un aviso genérico.)

> `wp_redirect` (no `wp_safe_redirect`) para el init_point porque es un host externo (mercadopago.com) — `wp_safe_redirect` lo bloquearía.

### `handleCancel` (`admin_post_infouno_cancel_subscription`)
1. `check_admin_referer(self::ACTION_CANCEL)`.
2. `$tenant = getForCurrentUser()`; si no → `wp_die(403)`.
3. `$sub = $repository->findActiveForTenant((int)$tenant['id'])`; si null → redirect `?error=no_subscription`.
4. `$service->cancelSubscription((string)$sub['mp_preapproval_id'])`; `wp_safe_redirect(panel . '?subscribed=cancel_requested')`; `exit`.

## Wiring (Plugin / RestRouter)

- **`BillingServiceFactory`** centraliza la construcción del `SubscriptionService`.
- **`RestRouter::register()`** usa `BillingServiceFactory::create($this->tenantManager)` (reemplaza el bloque inline; el `BillingController` se sigue construyendo igual con ese servicio).
- **`Plugin.php`** instancia e inicializa `TenantBillingPanel` (deps: `SubscriptionService` vía factory, `SubscriptionRepository`, `TenantManager`, `BillingConfig`), junto a los otros paneles admin (`->init()`).

## Seguridad

- Capability `read` para ver/usar el panel (es el tenant, no superadmin). Los handlers verifican nonce (`check_admin_referer`) y resuelven el tenant del servidor (`getForCurrentUser`) — nunca del request.
- `tenant_id` jamás del request; el `payer_email` del `user_id` del tenant vía `get_userdata`.
- Cero `$wpdb` en el panel (guard de Bloque D). Errores logueados sin secretos; al cliente, avisos genéricos.
- `createSubscription` ya bloquea duplicados (409/`already_subscribed`); el panel lo traduce a un aviso.

## Testing (PHPUnit en Docker + WpdbStub)

- `BillingServiceFactoryTest` — `create()` devuelve un `SubscriptionService` (arma las deps sin pegar a red; valida el tipo y que no explote con config vacía).
- **`TenantBillingPanel`**: siguiendo la convención del proyecto (los paneles WP Admin no tienen unit tests por el `wp_redirect`/`exit` y el render HTML), no lleva unit test propio. Su lógica de negocio vive en `SubscriptionService` (ya cubierto, 10 tests). Se verifica: **SQL-free** (`grep $wpdb` vacío) + **guard verde** (allowlist sigue vacía) + **suite completa verde** (el wiring en Plugin/RestRouter no rompe nada).
- Si al implementar se puede extraer la lógica de "qué estado mostrar" a un método puro (sin WP), agregar un test de esa resolución de estado (free/premium/suspended/no-configurado). Opcional pero recomendado.

## Fuera de alcance (YAGNI)

Tema Astra front-end (proyecto separado) · historial de pagos en el panel · gestión de bots/leads desde acá · cambio a plan `agency` o entre planes · actualización de medio de pago in-app (el tenant lo hace en MP). Solo: **ver estado + suscribir + cancelar**.

## Riesgos / notas

- `wp_redirect` al init_point externo (no `wp_safe_redirect`). Confirmar que ningún filtro `allowed_redirect_hosts` lo bloquee; si hiciera falta, agregar `mercadopago.com`/`mercadopago.com.ar` al allowlist de redirect.
- El `BillingServiceFactory` introduce un punto único de construcción del servicio; al refactorizar `RestRouter` confirmar que el comportamiento de las rutas REST no cambia (suite completa).
- El panel asume el menú padre `infouno-dashboard` (ya existe; lo usan los otros paneles).
