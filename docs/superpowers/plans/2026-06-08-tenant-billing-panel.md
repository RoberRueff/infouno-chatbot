# Panel de Billing del Tenant (WP Admin) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar al tenant una página WP Admin para ver su plan/suscripción, suscribirse a Premium (redirect al checkout de MercadoPago) y cancelar — reutilizando el `SubscriptionService` ya existente.

**Architecture:** Un `Admin\TenantBillingPanel` (capability `read`) con handlers `admin_post_` (subscribe/cancel) que delegan en `SubscriptionService`, más un `Billing\BillingServiceFactory` que centraliza la construcción del servicio (hoy inline en `RestRouter`) y lo comparten ambos. Cero `$wpdb` en el panel (guard de Bloque D).

**Tech Stack:** PHP 8.1+, PSR-4 `Infouno\SaaS\`, WordPress (admin pages, `admin_post_`, `wp_redirect`), PHPUnit en Docker `php:8.3-cli`.

**Spec:** `docs/superpowers/specs/2026-06-08-tenant-billing-panel-design.md`

---

## Contexto crítico para el implementador

1. **Componentes ya existentes (de la feature MercadoPago, en `main`):**
   - `Billing\SubscriptionService`: `createSubscription(array $tenant, string $payerEmail, float $price): string` (devuelve init_point; lanza `\RuntimeException('already_subscribed')` o con otros mensajes), `cancelSubscription(string $preapprovalId): void`.
   - `Persistence\SubscriptionRepository`: `findActiveForTenant(int $tenantId): ?array` (sub `pending`/`authorized`; devuelve fila con `status`, `next_payment_at`, `mp_preapproval_id`).
   - `Billing\BillingConfig`: `premiumPriceArs(): float`, `isConfigured(): bool`, `accessToken(): string`, `webhookSecret(): string`.
   - `Billing\MercadoPagoClient(HttpClientInterface, string $token)` + `Billing\WpHttpClient`.
   - `Tenant\TenantManager`: `getForCurrentUser(): ?array` (fila con `id`, `user_id`, `plan`, `status`).
2. **`RestRouter::register()`** construye el `SubscriptionService` inline (líneas ~86-97). Este plan extrae esa construcción a `BillingServiceFactory::create()` y refactoriza RestRouter para usarlo (comportamiento idéntico).
3. **Patrón de panel admin con handler:** `Admin\BotWizard` es la referencia — `init()` registra `add_action('admin_post_<action>', [$this,'handler'])`; el handler hace `check_admin_referer(...)`, resuelve tenant, y `wp_safe_redirect(...); exit;`. Menú vía `add_submenu_page('infouno-dashboard', titulo, titulo, 'read', slug, [$this,'renderPage'])`.
4. **Bloque D guard:** `tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php` falla ante `$wpdb->` en `src/Admin`. El panel NO puede tener SQL crudo — usa `SubscriptionRepository`/`TenantManager`. Allowlist vacía: no agregar entradas.
5. **Stubs de tests** (`tests/bootstrap.php`): `get_option`/`update_option`, `get_userdata` (devuelve objeto con `user_email`), `WP_REST_*`. `home_url` NO está stubeado aún → se agrega en Task 1.
6. **`Plugin.php`** instancia los paneles admin (líneas ~176-180) y los `init()` (líneas ~190-201). `$this->tenantManager` está disponible. Seguir ese patrón para `TenantBillingPanel`.
7. **`wp_redirect` vs `wp_safe_redirect`:** el redirect al `init_point` de MP es a un host externo → usar `wp_redirect` (no `wp_safe_redirect`, que bloquea hosts no permitidos). Los redirects internos (de vuelta al panel) usan `wp_safe_redirect`.

---

## File Structure

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `src/Billing/BillingServiceFactory.php` | **Crear** | `create(TenantManager): SubscriptionService` — arma config + mp client + repo + servicio. |
| `tests/Unit/Billing/BillingServiceFactoryTest.php` | **Crear** | Verifica que devuelve un `SubscriptionService`. |
| `src/API/RestRouter.php` | **Modificar** | Usar el factory para construir `$billingService` (reemplaza el inline). |
| `tests/bootstrap.php` | **Modificar** | Stub `home_url` (aditivo). |
| `src/Admin/TenantBillingPanel.php` | **Crear** | Página WP Admin del tenant: render de estado + handlers subscribe/cancel. Cero `$wpdb`. |
| `src/Plugin.php` | **Modificar** | Instanciar + `init()` el `TenantBillingPanel`. |

---

## Task 1: `BillingServiceFactory` + refactor de RestRouter

**Files:**
- Create: `plugins/infouno-custom/src/Billing/BillingServiceFactory.php`
- Modify: `plugins/infouno-custom/tests/bootstrap.php`
- Modify: `plugins/infouno-custom/src/API/RestRouter.php`
- Test: `plugins/infouno-custom/tests/Unit/Billing/BillingServiceFactoryTest.php`

- [ ] **Step 1: Agregar stub `home_url` a `tests/bootstrap.php`** (junto a los otros stubs de funciones WP, guardado por `function_exists`):

```php
if ( ! function_exists( 'home_url' ) ) {
    function home_url( string $path = '' ): string {
        return 'https://example.test' . $path;
    }
}
```

- [ ] **Step 2: Escribir el test que falla** — crear `tests/Unit/Billing/BillingServiceFactoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Billing;

use Infouno\SaaS\Billing\BillingServiceFactory;
use Infouno\SaaS\Billing\SubscriptionService;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class BillingServiceFactoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__infouno_options'] = [];
    }

    public function test_create_returns_subscription_service(): void {
        $svc = BillingServiceFactory::create( $this->createMock( TenantManager::class ) );
        $this->assertInstanceOf( SubscriptionService::class, $svc );
    }

    public function test_create_works_with_empty_config(): void {
        // Sin credenciales configuradas, igual debe construir el servicio (no pega a red acá).
        $svc = BillingServiceFactory::create( $this->createMock( TenantManager::class ) );
        $this->assertInstanceOf( SubscriptionService::class, $svc );
    }
}
```

- [ ] **Step 3: Correr, confirmar FAIL**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BillingServiceFactoryTest
```
Esperado: FAIL — `Class "Infouno\SaaS\Billing\BillingServiceFactory" not found`.

- [ ] **Step 4: Crear `src/Billing/BillingServiceFactory.php`**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Construye el SubscriptionService con sus dependencias de producción.
 * Punto único de wiring — usado por RestRouter (rutas REST) y por
 * TenantBillingPanel (WP Admin), para no duplicar la construcción.
 */
final class BillingServiceFactory {

    public static function create( TenantManager $tenantManager ): SubscriptionService {
        $config = new BillingConfig();
        $client = new MercadoPagoClient( new WpHttpClient(), $config->accessToken() );

        return new SubscriptionService(
            $client,
            new SubscriptionRepository(),
            $tenantManager,
            home_url( '/billing/return' )
        );
    }
}
```

- [ ] **Step 5: Correr, confirmar PASS**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BillingServiceFactoryTest
```
Esperado: PASS (2 tests).

- [ ] **Step 6: Refactorizar `src/API/RestRouter.php`** — reemplazar el bloque inline de construcción del servicio (las líneas que arman `$mpClient` y `$billingService = new \Infouno\SaaS\Billing\SubscriptionService(...)`) por una llamada al factory. El bloque resultante (manteniendo `$billingConfig`, `$subscriptionRepo`, el verifier y el controller):

```php
        $billingConfig    = new \Infouno\SaaS\Billing\BillingConfig();
        $subscriptionRepo = new \Infouno\SaaS\Persistence\SubscriptionRepository();
        $billingService   = \Infouno\SaaS\Billing\BillingServiceFactory::create( $this->tenantManager );
        $billingController = new BillingController(
            $this->tenantManager,
            $billingService,
            new \Infouno\SaaS\Billing\WebhookSignatureVerifier( $billingConfig->webhookSecret() ),
            $subscriptionRepo,
            $billingConfig
        );
        $billingController->registerRoutes( self::NAMESPACE );
```
(Eliminar las líneas del `$mpClient` y del `new SubscriptionService(...)` inline; el resto queda igual. `$subscriptionRepo` y `$billingConfig` siguen construyéndose para el controller.)

- [ ] **Step 7: Correr la suite completa** (el refactor no debe cambiar comportamiento):
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: verde (BillingControllerTest y todo lo demás sin regresión).

- [ ] **Step 8: Commit**
```bash
cd /Users/Rober/Desktop/Proyectos/infouno-chatbot/infouno-chatbot
git add plugins/infouno-custom/src/Billing/BillingServiceFactory.php plugins/infouno-custom/src/API/RestRouter.php plugins/infouno-custom/tests/bootstrap.php plugins/infouno-custom/tests/Unit/Billing/BillingServiceFactoryTest.php
git commit -m "feat(billing): BillingServiceFactory — wiring único del SubscriptionService (RestRouter usa el factory)"
```

---

## Task 2: `TenantBillingPanel` (página WP Admin) + wiring en Plugin

**Files:**
- Create: `plugins/infouno-custom/src/Admin/TenantBillingPanel.php`
- Modify: `plugins/infouno-custom/src/Plugin.php`

Página admin del tenant (capability `read`). Sin unit test propio (convención del proyecto para paneles WP Admin con `wp_redirect`/`exit` + render HTML); la lógica vive en `SubscriptionService` (ya testeado). Verificación: SQL-free + guard + suite verde.

- [ ] **Step 1: Crear `src/Admin/TenantBillingPanel.php`** (ZERO `$wpdb`):

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Admin;

use Infouno\SaaS\Billing\BillingConfig;
use Infouno\SaaS\Billing\SubscriptionService;
use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Panel de suscripción del tenant (WP Admin, capability `read`).
 * Distinto de BillingSettings (superadmin). Permite ver el plan, suscribirse a
 * Premium (redirect al checkout de MP) y cancelar. Sin SQL crudo — delega.
 */
final class TenantBillingPanel {

    private const PAGE_SLUG        = 'infouno-subscription';
    private const ACTION_SUBSCRIBE = 'infouno_subscribe';
    private const ACTION_CANCEL    = 'infouno_cancel_subscription';

    public function __construct(
        private readonly TenantManager          $tenantManager,
        private readonly SubscriptionService    $service,
        private readonly SubscriptionRepository $repository,
        private readonly BillingConfig          $config,
    ) {}

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
        add_action( 'admin_post_' . self::ACTION_SUBSCRIBE, [ $this, 'handleSubscribe' ] );
        add_action( 'admin_post_' . self::ACTION_CANCEL, [ $this, 'handleCancel' ] );
    }

    public function addMenuPage(): void {
        add_submenu_page(
            'infouno-dashboard',
            'Mi Suscripción',
            'Mi Suscripción',
            'read',
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    public function handleSubscribe(): void {
        check_admin_referer( self::ACTION_SUBSCRIBE );

        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            wp_die( esc_html__( 'Sin tenant activo.', 'infouno-custom' ), 403 );
        }

        $user  = get_userdata( (int) ( $tenant['user_id'] ?? 0 ) );
        $email = $user && isset( $user->user_email ) ? (string) $user->user_email : '';

        try {
            $initPoint = $this->service->createSubscription( $tenant, $email, $this->config->premiumPriceArs() );
        } catch ( \RuntimeException $e ) {
            error_log( '[INFOUNO] tenant subscribe error: ' . $e->getMessage() );
            wp_safe_redirect( $this->pageUrl( [ 'error' => 'subscribe' ] ) );
            exit;
        }

        // Host externo (mercadopago.com): wp_redirect, no wp_safe_redirect.
        wp_redirect( $initPoint );
        exit;
    }

    public function handleCancel(): void {
        check_admin_referer( self::ACTION_CANCEL );

        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            wp_die( esc_html__( 'Sin tenant activo.', 'infouno-custom' ), 403 );
        }

        $sub = $this->repository->findActiveForTenant( (int) $tenant['id'] );
        if ( null === $sub ) {
            wp_safe_redirect( $this->pageUrl( [ 'error' => 'no_subscription' ] ) );
            exit;
        }

        $this->service->cancelSubscription( (string) $sub['mp_preapproval_id'] );
        wp_safe_redirect( $this->pageUrl( [ 'subscribed' => 'cancel_requested' ] ) );
        exit;
    }

    public function renderPage(): void {
        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            echo '<div class="wrap"><p>' . esc_html__( 'No tenés un tenant activo.', 'infouno-custom' ) . '</p></div>';
            return;
        }

        $plan   = (string) ( $tenant['plan'] ?? 'free' );
        $status = (string) ( $tenant['status'] ?? 'active' );
        $sub    = $this->repository->findActiveForTenant( (int) $tenant['id'] );

        echo '<div class="wrap"><h1>' . esc_html__( 'Mi Suscripción', 'infouno-custom' ) . '</h1>';
        $this->renderNotices();

        if ( ! $this->config->isConfigured() ) {
            echo '<div class="notice notice-warning"><p>' .
                 esc_html__( 'El administrador aún no configuró los pagos. Volvé más tarde.', 'infouno-custom' ) .
                 '</p></div></div>';
            return;
        }

        if ( 'suspended' === $status ) {
            echo '<div class="notice notice-error"><p>' .
                 esc_html__( 'Tu último pago fue rechazado y tu cuenta está suspendida.', 'infouno-custom' ) .
                 '</p></div>';
            $this->renderCancelButton();
        } elseif ( 'premium' === $plan && $sub && 'authorized' === ( $sub['status'] ?? '' ) ) {
            $next = $sub['next_payment_at'] ?? null;
            echo '<p><strong>' . esc_html__( 'Plan Premium activo', 'infouno-custom' ) . '</strong> — 2.000.000 tokens/mes.</p>';
            if ( $next ) {
                printf( '<p>%s %s</p>', esc_html__( 'Próximo cobro:', 'infouno-custom' ), esc_html( (string) $next ) );
            }
            $this->renderCancelButton();
        } else {
            printf(
                '<p>%s <strong>%s</strong> — 50.000 tokens/mes.</p>',
                esc_html__( 'Plan actual:', 'infouno-custom' ),
                esc_html( ucfirst( $plan ) )
            );
            $this->renderSubscribeButton();
        }

        echo '</div>';
    }

    private function renderSubscribeButton(): void {
        $this->renderActionForm( self::ACTION_SUBSCRIBE, esc_html__( 'Suscribirme a Premium', 'infouno-custom' ), 'button-primary' );
    }

    private function renderCancelButton(): void {
        $this->renderActionForm( self::ACTION_CANCEL, esc_html__( 'Cancelar suscripción', 'infouno-custom' ), 'button' );
    }

    private function renderActionForm( string $action, string $label, string $class ): void {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px">';
        wp_nonce_field( $action );
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
        printf( '<button type="submit" class="button %s">%s</button>', esc_attr( $class ), esc_html( $label ) );
        echo '</form>';
    }

    private function renderNotices(): void {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( ! empty( $_GET['subscribed'] ) && 'cancel_requested' === $_GET['subscribed'] ) {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 esc_html__( 'Cancelación solicitada. Tu plan bajará a Free al confirmarse.', 'infouno-custom' ) .
                 '</p></div>';
        }
        if ( ! empty( $_GET['error'] ) ) {
            $msg = 'no_subscription' === $_GET['error']
                ? esc_html__( 'No hay una suscripción activa para cancelar.', 'infouno-custom' )
                : esc_html__( 'No se pudo iniciar la suscripción. Intentá de nuevo.', 'infouno-custom' );
            echo '<div class="notice notice-error is-dismissible"><p>' . $msg . '</p></div>';
        }
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /** @param array<string,string> $args */
    private function pageUrl( array $args ): string {
        return add_query_arg(
            array_merge( [ 'page' => self::PAGE_SLUG ], $args ),
            admin_url( 'admin.php' )
        );
    }
}
```

Verificar SQL-free:
```bash
grep -n '\$wpdb' plugins/infouno-custom/src/Admin/TenantBillingPanel.php   # vacío
```

- [ ] **Step 2: Wirear en `src/Plugin.php`**

2a. Import (junto a los otros `use Infouno\SaaS\Admin\...`):
```php
use Infouno\SaaS\Admin\TenantBillingPanel;
```
2b. Propiedad (junto a `$billingSettings`):
```php
    private TenantBillingPanel     $tenantBillingPanel;
```
2c. Instanciación (junto a `$this->billingSettings = new BillingSettings();`, usando el factory de Task 1 + las deps ya disponibles):
```php
        $this->tenantBillingPanel = new TenantBillingPanel(
            $this->tenantManager,
            \Infouno\SaaS\Billing\BillingServiceFactory::create( $this->tenantManager ),
            new \Infouno\SaaS\Persistence\SubscriptionRepository(),
            new \Infouno\SaaS\Billing\BillingConfig()
        );
```
2d. Init (junto a `$this->billingSettings->init();`):
```php
        $this->tenantBillingPanel->init();
```

- [ ] **Step 3: Verificar SQL-free + guard + suite completa**
```bash
grep -n '\$wpdb' plugins/infouno-custom/src/Admin/TenantBillingPanel.php   # vacío
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter NoRawSqlOutsidePersistenceTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: grep vacío; guard verde (allowlist sigue vacía); suite completa verde.

- [ ] **Step 4: Commit**
```bash
git add plugins/infouno-custom/src/Admin/TenantBillingPanel.php plugins/infouno-custom/src/Plugin.php
git commit -m "feat(billing): TenantBillingPanel — panel WP Admin del tenant (suscribir/cancelar)"
```

---

## Self-Review: Cobertura vs Spec

| Requisito del spec | Task(s) | Estado |
|---|---|---|
| Panel WP Admin del tenant (capability `read`), distinto de BillingSettings | Task 2 | ✅ |
| Estados: no-configurado / suspended / premium-activo / free | Task 2 (`renderPage`, orden suspended→premium→free) | ✅ |
| Botón Suscribirme → handler admin-post → `wp_redirect(init_point)` | Task 2 (`handleSubscribe`) | ✅ |
| Botón Cancelar → handler admin-post → `cancelSubscription` → redirect con notice | Task 2 (`handleCancel`) | ✅ |
| `wp_redirect` (externo) vs `wp_safe_redirect` (interno) | Task 2 | ✅ |
| nonce + tenant del servidor + payer email de `get_userdata` | Task 2 | ✅ |
| `already_subscribed`/error → aviso genérico (sin detalle interno) | Task 2 (`catch` → `?error=subscribe`) | ✅ |
| `BillingServiceFactory` compartido con RestRouter (DRY) | Task 1 | ✅ |
| RestRouter refactorizado a usar el factory (sin cambio de comportamiento) | Task 1 | ✅ |
| Cero `$wpdb` en el panel (guard Bloque D) | Tasks 2-3 (grep + guard) | ✅ |
| Test del factory; panel sin unit test (convención) | Tasks 1-2 | ✅ |

**Notas / riesgos (del spec):**
- `wp_redirect` al init_point externo: si un filtro `allowed_redirect_hosts` lo bloqueara, permitir `mercadopago.com`. `wp_redirect` (no safe) ya evita el bloqueo por defecto.
- El factory crea su propio `SubscriptionRepository` interno; RestRouter y el panel además crean uno para sus lecturas. Son instancias stateless — sin problema.
- `add_query_arg`/`admin_url`/`wp_nonce_field`/`get_userdata`/`home_url` son funciones WP de runtime (el panel no tiene unit test; el factory usa `home_url`, stubeado en Task 1).
