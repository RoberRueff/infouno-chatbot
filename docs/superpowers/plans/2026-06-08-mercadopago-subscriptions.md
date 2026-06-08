# MercadoPago Suscripciones (premium) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que un tenant se suscriba self-service al plan **premium** vía MercadoPago Suscripciones (preapproval, débito mensual ARS); activar/suspender el plan según el estado autoritativo traído de la API de MP (Enfoque A).

**Architecture:** Webhook como "ping" → se verifica firma (`x-signature` HMAC + `ts`) → se hace fetch del recurso a MP → se reconcilia el estado del tenant de forma idempotente. Todo el SQL vive en `Persistence\SubscriptionRepository` (extiende `TenantScopedRepository`, Bloque D). Cliente MP sobre un transporte HTTP inyectable (testeable sin pegar a MP).

**Tech Stack:** PHP 8.1+, PSR-4 `Infouno\SaaS\`, WordPress (REST API, `wp_options`, `wp_remote_*`), PHPUnit en Docker `php:8.3-cli` (NO hay PHP local), `WpdbStub` en `tests/bootstrap.php`.

**Comando de tests (desde `plugins/infouno-custom/`):**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage [--filter <TestName>]
```

**Spec de referencia:** `docs/superpowers/specs/2026-06-08-mercadopago-subscriptions-design.md`

---

## Contexto crítico para el implementador

1. **Bloque D (guard total):** el test `tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php` falla el build si encuentra `$wpdb->` en `src/API` o `src/Admin`. Por eso `BillingController` y `BillingSettings` NO pueden tener SQL crudo — todo va por `SubscriptionRepository`. La allowlist está vacía: no agregar entradas.
2. **Base fail-closed:** `Persistence\TenantScopedRepository` provee `protected \wpdb $db` (de `global $wpdb`), `abstract protected function table(): string`, `final protected function guardScope(int): int` (lanza `Infouno\SaaS\Persistence\MissingTenantScopeException` si `<= 0`). Referencia de estilo: `src/Persistence/ConsentRepository.php`, `src/Opportunity/OpportunityRepository.php`.
3. **WpdbStub** (`tests/bootstrap.php`): `get_row/get_var/get_results` → `stub_get_row/stub_get_var/stub_get_results` y setean `last_query`; `insert($table,$data,$formats)` → `onInsert($table,$data)` + `insert_id`; `update` → `last_update_data`/`last_update_where`; `delete` → `last_delete_where`; `query` → `last_query`/`last_write_query`; `prepare` sustituye `%s`→`'val'`, `%d`→`val`. Ya stubeados: `get_option`/`update_option` (vía `$GLOBALS['__infouno_options']`), `wp_json_encode`, `sanitize_text_field`, `WP_REST_Request`/`WP_REST_Response`/`WP_Error` (con ArrayAccess y `get_error_data`). `bypassFinals` activo.
4. **Migraciones:** `Core\Migrator` tiene `INFOUNO_DB_VERSION` y métodos `migrateToN()` + `createXxxTable(\wpdb $wpdb)` que llaman `dbDelta($sql)`. En tests, `dbDelta` está stubeado y acumula el SQL en `$GLOBALS['__infouno_dbdelta_sql']`. Patrón a copiar: el de `migrateTo10` (Bloque B).
5. **HTTP:** `wp_remote_post`/`wp_remote_get` NO están stubeados. Por eso el cliente MP depende de un `HttpClientInterface` inyectable; producción usa `WpHttpClient` (wrapper), tests usan un fake.
6. **Email del payer:** la fila del tenant tiene `user_id` (no email). El `BillingController` resuelve el email del dueño vía `get_userdata($userId)->user_email` y lo pasa al servicio. Requiere un stub `get_userdata` en bootstrap (Task 7, aditivo).
7. **Reglas de merge del proyecto:** toda query nueva con `tenant_id`; `INFOUNO_DB_VERSION` incrementado + `migrateTo11()`; endpoints REST con `permission_callback`.

---

## File Structure

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `src/Core/Migrator.php` | **Modificar** | `INFOUNO_DB_VERSION='11'`; `migrateTo11()` + `createSubscriptionsTable()` + `createPaymentEventsTable()`. |
| `tests/Unit/Core/MigratorV11Test.php` | **Crear** | Verifica DDL de ambas tablas + versión. |
| `src/Billing/BillingConfig.php` | **Crear** | Lectura de config: env-first, setting (`infouno_billing`) fallback. |
| `tests/Unit/Billing/BillingConfigTest.php` | **Crear** | env override, fallback a opción, precio. |
| `src/Billing/WebhookSignatureVerifier.php` | **Crear** | Verifica `x-signature` HMAC + ventana `ts`. |
| `tests/Unit/Billing/WebhookSignatureVerifierTest.php` | **Crear** | firma válida/inválida, replay. |
| `src/Billing/HttpClientInterface.php` + `src/Billing/WpHttpClient.php` | **Crear** | Transporte HTTP inyectable. |
| `src/Billing/MercadoPagoException.php` | **Crear** | Excepción tipada de errores de la API MP. |
| `src/Billing/MercadoPagoClientInterface.php` + `src/Billing/MercadoPagoClient.php` | **Crear** | API MP: createPreapproval/getPreapproval/getPayment/cancelPreapproval. |
| `tests/Unit/Billing/MercadoPagoClientTest.php` | **Crear** | con transporte fake. |
| `src/Persistence/SubscriptionRepository.php` | **Crear** | SQL de `subscriptions` + `payment_events`. Extiende la base. |
| `tests/Unit/Persistence/SubscriptionRepositoryTest.php` | **Crear** | fail-closed, tenant_id, idempotencia. |
| `src/Billing/SubscriptionService.php` | **Crear** | createSubscription + reconcileFromNotification (máquina de estados). |
| `tests/Unit/Billing/SubscriptionServiceTest.php` | **Crear** | transiciones, idempotencia, orden. |
| `src/API/BillingController.php` | **Crear** | Endpoints REST. Sin `$wpdb`. |
| `src/API/RestRouter.php` | **Modificar** | Instanciar `BillingController` + `registerRoutes`. |
| `tests/Unit/API/BillingControllerTest.php` | **Crear** | subscribe/webhook/subscription/cancel. |
| `src/Admin/BillingSettings.php` | **Crear** | Página de ajustes WP Admin. |
| `src/Plugin.php` | **Modificar** | Instanciar/inicializar `BillingSettings`. |

---

## Task 1: Migración v11 — tablas `subscriptions` y `payment_events`

**Files:**
- Modify: `plugins/infouno-custom/src/Core/Migrator.php`
- Test: `plugins/infouno-custom/tests/Unit/Core/MigratorV11Test.php`

- [ ] **Step 1: Escribir el test que falla** — crear `tests/Unit/Core/MigratorV11Test.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Core;

use Infouno\SaaS\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorV11Test extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__infouno_dbdelta_sql'] = [];
        $GLOBALS['wpdb']->prefix          = 'wp_';
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb']->prefix = 'wp_';
    }

    private function invokeCreate( string $method ): string {
        $migrator = new Migrator();
        $ref      = new \ReflectionMethod( $migrator, $method );
        $ref->setAccessible( true );
        $ref->invoke( $migrator, $GLOBALS['wpdb'] );
        return implode( "\n", $GLOBALS['__infouno_dbdelta_sql'] );
    }

    public function test_db_version_is_11(): void {
        $this->assertSame( '11', Migrator::INFOUNO_DB_VERSION );
    }

    public function test_subscriptions_table_ddl(): void {
        $sql = $this->invokeCreate( 'createSubscriptionsTable' );
        $this->assertStringContainsString( 'wp_infouno_subscriptions', $sql );
        $this->assertStringContainsString( 'tenant_id', $sql );
        $this->assertStringContainsString( 'mp_preapproval_id', $sql );
        $this->assertStringContainsString( 'status', $sql );
        $this->assertStringContainsString( 'next_payment_at', $sql );
        $this->assertStringContainsString( 'last_event_ts', $sql );
        $this->assertStringContainsString( 'UNIQUE KEY', $sql );
    }

    public function test_payment_events_table_ddl(): void {
        $sql = $this->invokeCreate( 'createPaymentEventsTable' );
        $this->assertStringContainsString( 'wp_infouno_payment_events', $sql );
        $this->assertStringContainsString( 'mp_payment_id', $sql );
        $this->assertStringContainsString( 'tenant_id', $sql );
        $this->assertStringContainsString( 'UNIQUE KEY', $sql );
    }
}
```

- [ ] **Step 2: Correr, confirmar FAIL**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter MigratorV11Test
```
Esperado: FAIL — `INFOUNO_DB_VERSION` no es '11' y los métodos no existen.

- [ ] **Step 3: Implementar en `src/Core/Migrator.php`**

3a. Cambiar la constante de versión:
```php
public const INFOUNO_DB_VERSION = '11';
```
(buscar el valor actual `'10'` y reemplazarlo).

3b. En el método que orquesta las migraciones (donde se llama `migrateTo10`), agregar la llamada a `migrateTo11` después de `migrateTo10`. Seguir el patrón existente (comparar versión instalada y correr las migraciones pendientes). Agregar:
```php
        $this->migrateTo11( $wpdb );
```

3c. Agregar los 3 métodos (copiar el estilo de `createChannelTemplatesTable` / `migrateTo10`):
```php
    /**
     * v11 — MercadoPago Suscripciones: wp_infouno_subscriptions + wp_infouno_payment_events.
     */
    private function migrateTo11( \wpdb $wpdb ): void {
        $this->createSubscriptionsTable( $wpdb );
        $this->createPaymentEventsTable( $wpdb );
    }

    private function createSubscriptionsTable( \wpdb $wpdb ): void {
        $table   = $wpdb->prefix . 'infouno_subscriptions';
        $collate = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$table} (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id         BIGINT UNSIGNED NOT NULL,
            mp_preapproval_id VARCHAR(255)    NOT NULL,
            plan              VARCHAR(50)     NOT NULL DEFAULT 'premium',
            status            VARCHAR(20)     NOT NULL DEFAULT 'pending',
            amount            DECIMAL(12,2)   NOT NULL DEFAULT 0,
            currency          VARCHAR(3)      NOT NULL DEFAULT 'ARS',
            next_payment_at   DATETIME        NULL,
            last_event_ts     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_preapproval (mp_preapproval_id),
            KEY tenant (tenant_id),
            KEY status (status)
        ) {$collate};";
        dbDelta( $sql );
    }

    private function createPaymentEventsTable( \wpdb $wpdb ): void {
        $table   = $wpdb->prefix . 'infouno_payment_events';
        $collate = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$table} (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id         BIGINT UNSIGNED NOT NULL,
            mp_payment_id     VARCHAR(255)    NOT NULL,
            mp_preapproval_id VARCHAR(255)    NOT NULL,
            status            VARCHAR(20)     NOT NULL,
            amount            DECIMAL(12,2)   NOT NULL DEFAULT 0,
            processed_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_payment (mp_payment_id),
            KEY tenant (tenant_id)
        ) {$collate};";
        dbDelta( $sql );
    }
```

3d. Actualizar el docblock de versiones del Migrator (header) con la línea `v11`.

- [ ] **Step 4: Correr, confirmar PASS + suite completa**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter MigratorV11Test
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: MigratorV11Test PASS; suite completa verde (otros MigratorTest siguen pasando).

- [ ] **Step 5: Commit**
```bash
cd /Users/Rober/Desktop/Proyectos/infouno-chatbot/infouno-chatbot
git add plugins/infouno-custom/src/Core/Migrator.php plugins/infouno-custom/tests/Unit/Core/MigratorV11Test.php
git commit -m "feat(billing): migración v11 — tablas subscriptions + payment_events"
```

---

## Task 2: `BillingConfig` — lectura de config (env-first)

**Files:**
- Create: `plugins/infouno-custom/src/Billing/BillingConfig.php`
- Test: `plugins/infouno-custom/tests/Unit/Billing/BillingConfigTest.php`

`BillingConfig` lee credenciales/precio: primero una constante de entorno; si no está definida, la opción `infouno_billing` (array en `wp_options`). El precio sale solo de la opción.

- [ ] **Step 1: Escribir el test** — crear `tests/Unit/Billing/BillingConfigTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Billing;

use Infouno\SaaS\Billing\BillingConfig;
use PHPUnit\Framework\TestCase;

final class BillingConfigTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__infouno_options'] = [];
    }

    public function test_access_token_from_option_when_no_constant(): void {
        update_option( 'infouno_billing', [ 'access_token' => 'opt-token' ] );
        $this->assertSame( 'opt-token', ( new BillingConfig() )->accessToken() );
    }

    public function test_access_token_empty_when_unset(): void {
        $this->assertSame( '', ( new BillingConfig() )->accessToken() );
    }

    public function test_premium_price_reads_option_as_float(): void {
        update_option( 'infouno_billing', [ 'premium_price_ars' => '14900.50' ] );
        $this->assertSame( 14900.50, ( new BillingConfig() )->premiumPriceArs() );
    }

    public function test_premium_price_zero_when_unset(): void {
        $this->assertSame( 0.0, ( new BillingConfig() )->premiumPriceArs() );
    }

    public function test_webhook_secret_and_public_key_from_option(): void {
        update_option( 'infouno_billing', [ 'webhook_secret' => 'whsec', 'public_key' => 'pk' ] );
        $cfg = new BillingConfig();
        $this->assertSame( 'whsec', $cfg->webhookSecret() );
        $this->assertSame( 'pk', $cfg->publicKey() );
    }
}
```

- [ ] **Step 2: Correr, confirmar FAIL**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BillingConfigTest
```

- [ ] **Step 3: Crear `src/Billing/BillingConfig.php`**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

/**
 * Lectura centralizada de la configuración de billing.
 *
 * Secretos (access_token, webhook_secret): constante de entorno primero
 * (INFOUNO_MP_ACCESS_TOKEN, INFOUNO_MP_WEBHOOK_SECRET), opción de WP después.
 * Así quien quiera mantiene los secretos fuera de la BD.
 * Precio y public_key salen solo de la opción `infouno_billing`.
 */
final class BillingConfig {

    private const OPTION = 'infouno_billing';

    /** @return array<string,mixed> */
    private function options(): array {
        $opt = get_option( self::OPTION, [] );
        return is_array( $opt ) ? $opt : [];
    }

    private function fromEnvOrOption( string $constant, string $optionKey ): string {
        if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
            return (string) constant( $constant );
        }
        return (string) ( $this->options()[ $optionKey ] ?? '' );
    }

    public function accessToken(): string {
        return $this->fromEnvOrOption( 'INFOUNO_MP_ACCESS_TOKEN', 'access_token' );
    }

    public function webhookSecret(): string {
        return $this->fromEnvOrOption( 'INFOUNO_MP_WEBHOOK_SECRET', 'webhook_secret' );
    }

    public function publicKey(): string {
        return (string) ( $this->options()['public_key'] ?? '' );
    }

    public function premiumPriceArs(): float {
        return (float) ( $this->options()['premium_price_ars'] ?? 0 );
    }

    public function isConfigured(): bool {
        return '' !== $this->accessToken() && $this->premiumPriceArs() > 0;
    }
}
```

- [ ] **Step 4: Correr, confirmar PASS**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BillingConfigTest
```

- [ ] **Step 5: Commit**
```bash
git add plugins/infouno-custom/src/Billing/BillingConfig.php plugins/infouno-custom/tests/Unit/Billing/BillingConfigTest.php
git commit -m "feat(billing): BillingConfig — lectura env-first de credenciales y precio"
```

---

## Task 3: `WebhookSignatureVerifier` — firma HMAC + anti-replay

**Files:**
- Create: `plugins/infouno-custom/src/Billing/WebhookSignatureVerifier.php`
- Test: `plugins/infouno-custom/tests/Unit/Billing/WebhookSignatureVerifierTest.php`

MP manda `x-signature: ts=<unix>,v1=<hmac>`. El HMAC-SHA256 se calcula sobre el manifiesto `id:<dataId>;request-id:<requestId>;ts:<ts>;` con el `webhook_secret`. Se rechaza si el HMAC no coincide o si `|now - ts| > ventana` (300 s).

- [ ] **Step 1: Escribir el test** — crear `tests/Unit/Billing/WebhookSignatureVerifierTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Billing;

use Infouno\SaaS\Billing\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureVerifierTest extends TestCase {

    private const SECRET = 'whsec_test';

    private function sign( string $dataId, string $requestId, int $ts ): string {
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac( 'sha256', $manifest, self::SECRET );
        return "ts={$ts},v1={$v1}";
    }

    public function test_valid_signature_within_window(): void {
        $ts     = 1_000_000;
        $header = $this->sign( '123', 'req-1', $ts );
        $v = new WebhookSignatureVerifier( self::SECRET, 300 );
        $this->assertTrue( $v->verify( $header, 'req-1', '123', $ts + 10 ) );
    }

    public function test_tampered_signature_rejected(): void {
        $ts     = 1_000_000;
        $header = 'ts=' . $ts . ',v1=deadbeef';
        $v = new WebhookSignatureVerifier( self::SECRET, 300 );
        $this->assertFalse( $v->verify( $header, 'req-1', '123', $ts + 10 ) );
    }

    public function test_stale_ts_rejected_as_replay(): void {
        $ts     = 1_000_000;
        $header = $this->sign( '123', 'req-1', $ts );
        $v = new WebhookSignatureVerifier( self::SECRET, 300 );
        // now está 10 min después → fuera de la ventana de 300 s.
        $this->assertFalse( $v->verify( $header, 'req-1', '123', $ts + 600 ) );
    }

    public function test_malformed_header_rejected(): void {
        $v = new WebhookSignatureVerifier( self::SECRET, 300 );
        $this->assertFalse( $v->verify( 'garbage', 'req-1', '123', 1_000_000 ) );
    }

    public function test_empty_secret_rejects(): void {
        $ts     = 1_000_000;
        $header = $this->sign( '123', 'req-1', $ts );
        $v = new WebhookSignatureVerifier( '', 300 );
        $this->assertFalse( $v->verify( $header, 'req-1', '123', $ts ) );
    }
}
```

- [ ] **Step 2: Correr, confirmar FAIL**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WebhookSignatureVerifierTest
```

- [ ] **Step 3: Crear `src/Billing/WebhookSignatureVerifier.php`**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

/**
 * Verifica la firma de los webhooks de MercadoPago.
 *
 * MP envía el header `x-signature: ts=<unix>,v1=<hmac>`. El HMAC-SHA256 se
 * calcula sobre `id:<dataId>;request-id:<requestId>;ts:<ts>;` con el webhook secret.
 * Se rechaza si el HMAC no coincide (hash_equals) o si el ts está fuera de ventana
 * (anti-replay). Sin secret configurado, rechaza todo (fail-closed).
 */
final class WebhookSignatureVerifier {

    public function __construct(
        private readonly string $secret,
        private readonly int    $toleranceSeconds = 300,
    ) {}

    public function verify( string $signatureHeader, string $requestId, string $dataId, int $nowTs ): bool {
        if ( '' === $this->secret ) {
            return false;
        }

        $parts = $this->parseHeader( $signatureHeader );
        if ( null === $parts ) {
            return false;
        }
        [ 'ts' => $ts, 'v1' => $v1 ] = $parts;

        if ( abs( $nowTs - $ts ) > $this->toleranceSeconds ) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $expected = hash_hmac( 'sha256', $manifest, $this->secret );

        return hash_equals( $expected, $v1 );
    }

    /** @return array{ts:int, v1:string}|null */
    private function parseHeader( string $header ): ?array {
        $ts = null;
        $v1 = null;
        foreach ( explode( ',', $header ) as $segment ) {
            $pair = explode( '=', trim( $segment ), 2 );
            if ( 2 !== count( $pair ) ) {
                continue;
            }
            if ( 'ts' === $pair[0] ) {
                $ts = (int) $pair[1];
            } elseif ( 'v1' === $pair[0] ) {
                $v1 = $pair[1];
            }
        }
        if ( null === $ts || null === $v1 || '' === $v1 ) {
            return null;
        }
        return [ 'ts' => $ts, 'v1' => $v1 ];
    }
}
```

- [ ] **Step 4: Correr, confirmar PASS**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WebhookSignatureVerifierTest
```

- [ ] **Step 5: Commit**
```bash
git add plugins/infouno-custom/src/Billing/WebhookSignatureVerifier.php plugins/infouno-custom/tests/Unit/Billing/WebhookSignatureVerifierTest.php
git commit -m "feat(billing): WebhookSignatureVerifier — HMAC x-signature + anti-replay"
```

---

## Task 4: Cliente MercadoPago (transporte HTTP inyectable)

**Files:**
- Create: `plugins/infouno-custom/src/Billing/HttpClientInterface.php`
- Create: `plugins/infouno-custom/src/Billing/WpHttpClient.php`
- Create: `plugins/infouno-custom/src/Billing/MercadoPagoException.php`
- Create: `plugins/infouno-custom/src/Billing/MercadoPagoClientInterface.php`
- Create: `plugins/infouno-custom/src/Billing/MercadoPagoClient.php`
- Test: `plugins/infouno-custom/tests/Unit/Billing/MercadoPagoClientTest.php`

- [ ] **Step 1: Crear las interfaces y la excepción (sin lógica testeable aún)**

`src/Billing/HttpClientInterface.php`:
```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

/**
 * Transporte HTTP inyectable — desacopla MercadoPagoClient de wp_remote_*.
 *
 * @phpstan-type HttpResponse array{status:int, body:string}
 */
interface HttpClientInterface {
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     */
    public function post( string $url, array $headers, string $body ): array;

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     */
    public function get( string $url, array $headers ): array;
}
```

`src/Billing/MercadoPagoException.php`:
```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

/** Error de la API de MercadoPago (status >= 400 o respuesta no parseable). */
final class MercadoPagoException extends \RuntimeException {}
```

`src/Billing/MercadoPagoClientInterface.php`:
```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

interface MercadoPagoClientInterface {
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed> Recurso preapproval creado (incluye id, init_point).
     */
    public function createPreapproval( array $payload ): array;

    /** @return array<string,mixed> */
    public function getPreapproval( string $id ): array;

    /** @return array<string,mixed> */
    public function getPayment( string $id ): array;

    public function cancelPreapproval( string $id ): bool;
}
```

`src/Billing/WpHttpClient.php`:
```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

/** Transporte HTTP de producción sobre wp_remote_*. */
final class WpHttpClient implements HttpClientInterface {

    public function post( string $url, array $headers, string $body ): array {
        $res = wp_remote_post( $url, [
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 15,
        ] );
        return $this->normalize( $res );
    }

    public function get( string $url, array $headers ): array {
        $res = wp_remote_get( $url, [
            'headers' => $headers,
            'timeout' => 15,
        ] );
        return $this->normalize( $res );
    }

    /** @param mixed $res */
    private function normalize( $res ): array {
        if ( is_wp_error( $res ) ) {
            throw new MercadoPagoException( 'HTTP error: ' . $res->get_error_message() );
        }
        return [
            'status' => (int) wp_remote_retrieve_response_code( $res ),
            'body'   => (string) wp_remote_retrieve_body( $res ),
        ];
    }
}
```

- [ ] **Step 2: Escribir el test del cliente** — crear `tests/Unit/Billing/MercadoPagoClientTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Billing;

use Infouno\SaaS\Billing\HttpClientInterface;
use Infouno\SaaS\Billing\MercadoPagoClient;
use Infouno\SaaS\Billing\MercadoPagoException;
use PHPUnit\Framework\TestCase;

final class MercadoPagoClientTest extends TestCase {

    /** Fake que registra la última request y devuelve respuestas programadas. */
    private function fakeHttp( int $status, string $body, ?array &$captured = null ): HttpClientInterface {
        return new class( $status, $body, $captured ) implements HttpClientInterface {
            public function __construct( private int $status, private string $body, private ?array &$captured ) {}
            public function post( string $url, array $headers, string $b ): array {
                $this->captured = [ 'method' => 'POST', 'url' => $url, 'headers' => $headers, 'body' => $b ];
                return [ 'status' => $this->status, 'body' => $this->body ];
            }
            public function get( string $url, array $headers ): array {
                $this->captured = [ 'method' => 'GET', 'url' => $url, 'headers' => $headers, 'body' => '' ];
                return [ 'status' => $this->status, 'body' => $this->body ];
            }
        };
    }

    public function test_createPreapproval_posts_with_bearer_and_parses_body(): void {
        $captured = null;
        $http = $this->fakeHttp( 201, json_encode( [ 'id' => 'pa-1', 'init_point' => 'https://mp/checkout' ] ), $captured );
        $client = new MercadoPagoClient( $http, 'tok-123' );

        $res = $client->createPreapproval( [ 'reason' => 'Premium' ] );

        $this->assertSame( 'pa-1', $res['id'] );
        $this->assertSame( 'https://mp/checkout', $res['init_point'] );
        $this->assertStringContainsString( '/preapproval', $captured['url'] );
        $this->assertSame( 'Bearer tok-123', $captured['headers']['Authorization'] );
        $this->assertStringContainsString( 'Premium', $captured['body'] );
    }

    public function test_getPayment_gets_and_parses(): void {
        $http = $this->fakeHttp( 200, json_encode( [ 'id' => 'pay-9', 'status' => 'approved' ] ) );
        $client = new MercadoPagoClient( $http, 'tok' );
        $res = $client->getPayment( 'pay-9' );
        $this->assertSame( 'approved', $res['status'] );
    }

    public function test_error_status_throws(): void {
        $http = $this->fakeHttp( 401, '{"message":"unauthorized"}' );
        $client = new MercadoPagoClient( $http, 'bad' );
        $this->expectException( MercadoPagoException::class );
        $client->getPreapproval( 'pa-1' );
    }

    public function test_cancelPreapproval_puts_cancelled_status(): void {
        $captured = null;
        $http = $this->fakeHttp( 200, json_encode( [ 'id' => 'pa-1', 'status' => 'cancelled' ] ), $captured );
        $client = new MercadoPagoClient( $http, 'tok' );
        $this->assertTrue( $client->cancelPreapproval( 'pa-1' ) );
    }
}
```

> Nota: `MercadoPagoClient` usa solo `post`/`get` del transporte. Para `cancelPreapproval` (que en la API real es un PUT), el cliente lo implementa vía `post` a la URL de cancelación con el header de override, o se agrega un `put()` al `HttpClientInterface`. Para el MVP, implementarlo con `post` al endpoint `/preapproval/{id}` enviando `{"status":"cancelled"}` y, si MP exige PUT, agregar `put()` a la interfaz + `WpHttpClient` (cambio aditivo) — documentarlo en el código. El test de arriba solo valida que devuelve true ante 200.

- [ ] **Step 3: Correr, confirmar FAIL**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter MercadoPagoClientTest
```

- [ ] **Step 4: Crear `src/Billing/MercadoPagoClient.php`**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

/**
 * Cliente de la API de MercadoPago (Suscripciones/preapproval + pagos).
 * Toda llamada va por el HttpClientInterface inyectado (testeable sin red).
 */
final class MercadoPagoClient implements MercadoPagoClientInterface {

    private const BASE = 'https://api.mercadopago.com';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string              $accessToken,
    ) {}

    /** @return array<string,string> */
    private function headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type'  => 'application/json',
        ];
    }

    public function createPreapproval( array $payload ): array {
        $res = $this->http->post( self::BASE . '/preapproval', $this->headers(), (string) wp_json_encode( $payload ) );
        return $this->parse( $res );
    }

    public function getPreapproval( string $id ): array {
        $res = $this->http->get( self::BASE . '/preapproval/' . rawurlencode( $id ), $this->headers() );
        return $this->parse( $res );
    }

    public function getPayment( string $id ): array {
        $res = $this->http->get( self::BASE . '/v1/payments/' . rawurlencode( $id ), $this->headers() );
        return $this->parse( $res );
    }

    public function cancelPreapproval( string $id ): bool {
        // MP cancela una suscripción seteando status='cancelled' en el preapproval.
        $res = $this->http->post(
            self::BASE . '/preapproval/' . rawurlencode( $id ),
            $this->headers(),
            (string) wp_json_encode( [ 'status' => 'cancelled' ] )
        );
        $this->parse( $res );
        return true;
    }

    /**
     * @param array{status:int, body:string} $res
     * @return array<string,mixed>
     */
    private function parse( array $res ): array {
        if ( $res['status'] >= 400 ) {
            throw new MercadoPagoException( 'MercadoPago API error (status ' . $res['status'] . ').' );
        }
        $decoded = json_decode( $res['body'], true );
        if ( ! is_array( $decoded ) ) {
            throw new MercadoPagoException( 'MercadoPago API: respuesta no parseable.' );
        }
        return $decoded;
    }
}
```

- [ ] **Step 5: Correr, confirmar PASS**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter MercadoPagoClientTest
```

- [ ] **Step 6: Commit**
```bash
git add plugins/infouno-custom/src/Billing/HttpClientInterface.php plugins/infouno-custom/src/Billing/WpHttpClient.php plugins/infouno-custom/src/Billing/MercadoPagoException.php plugins/infouno-custom/src/Billing/MercadoPagoClientInterface.php plugins/infouno-custom/src/Billing/MercadoPagoClient.php plugins/infouno-custom/tests/Unit/Billing/MercadoPagoClientTest.php
git commit -m "feat(billing): MercadoPagoClient sobre transporte HTTP inyectable"
```

---

## Task 5: `SubscriptionRepository` (extiende la base fail-closed)

**Files:**
- Create: `plugins/infouno-custom/src/Persistence/SubscriptionRepository.php`
- Test: `plugins/infouno-custom/tests/Unit/Persistence/SubscriptionRepositoryTest.php`

- [ ] **Step 1: Escribir el test** — crear `tests/Unit/Persistence/SubscriptionRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Persistence;

use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use PHPUnit\Framework\TestCase;

final class SubscriptionRepositoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->prefix           = 'wp_';
        $GLOBALS['wpdb']->stub_get_row     = null;
        $GLOBALS['wpdb']->stub_get_var     = null;
        $GLOBALS['wpdb']->last_query       = '';
        $GLOBALS['wpdb']->last_update_data = [];
        $GLOBALS['wpdb']->last_update_where = [];
        $GLOBALS['wpdb']->onInsert         = null;
        $GLOBALS['wpdb']->insert_id        = 0;
    }

    public function test_createPending_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new SubscriptionRepository() )->createPending( 0, 'pa-1', 'premium', 14900.0 );
    }

    public function test_createPending_inserts_with_tenant_and_preapproval(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $t, array $d ) use ( &$captured ) { $captured = [ 't' => $t, 'd' => $d ]; };
        $GLOBALS['wpdb']->insert_id = 7;
        $id = ( new SubscriptionRepository() )->createPending( 3, 'pa-1', 'premium', 14900.0 );
        $this->assertSame( 7, $id );
        $this->assertSame( 'wp_infouno_subscriptions', $captured['t'] );
        $this->assertSame( 3, $captured['d']['tenant_id'] );
        $this->assertSame( 'pa-1', $captured['d']['mp_preapproval_id'] );
        $this->assertSame( 'pending', $captured['d']['status'] );
    }

    public function test_findByPreapprovalId_filters_by_preapproval(): void {
        $GLOBALS['wpdb']->stub_get_row = [ 'id' => 1, 'tenant_id' => 3 ];
        $row = ( new SubscriptionRepository() )->findByPreapprovalId( 'pa-1' );
        $this->assertSame( 3, (int) $row['tenant_id'] );
        $this->assertStringContainsString( "mp_preapproval_id = 'pa-1'", $GLOBALS['wpdb']->last_query );
    }

    public function test_findActiveForTenant_fails_closed_on_zero(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new SubscriptionRepository() )->findActiveForTenant( 0 );
    }

    public function test_findActiveForTenant_filters_tenant_and_status(): void {
        $GLOBALS['wpdb']->stub_get_row = null;
        ( new SubscriptionRepository() )->findActiveForTenant( 3 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'tenant_id = 3', $q );
        $this->assertStringContainsString( "status IN ('pending','authorized')", $q );
    }

    public function test_markAuthorized_updates_status_scoped_by_tenant(): void {
        ( new SubscriptionRepository() )->markAuthorized( 3, 'pa-1', 1700000000, '2026-07-08 00:00:00' );
        $this->assertSame( 'authorized', $GLOBALS['wpdb']->last_update_data['status'] );
        $this->assertSame( [ 'tenant_id' => 3, 'mp_preapproval_id' => 'pa-1' ], $GLOBALS['wpdb']->last_update_where );
    }

    public function test_markAuthorized_fails_closed_on_zero(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new SubscriptionRepository() )->markAuthorized( 0, 'pa-1', 1, null );
    }

    public function test_recordPaymentEvent_inserts_idempotent_key(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $t, array $d ) use ( &$captured ) { $captured = [ 't' => $t, 'd' => $d ]; };
        ( new SubscriptionRepository() )->recordPaymentEvent( 3, 'pay-1', 'pa-1', 'approved', 14900.0 );
        $this->assertSame( 'wp_infouno_payment_events', $captured['t'] );
        $this->assertSame( 'pay-1', $captured['d']['mp_payment_id'] );
        $this->assertSame( 3, $captured['d']['tenant_id'] );
    }

    public function test_paymentEventExists_queries_by_payment_id(): void {
        $GLOBALS['wpdb']->stub_get_var = '1';
        $this->assertTrue( ( new SubscriptionRepository() )->paymentEventExists( 'pay-1' ) );
        $this->assertStringContainsString( "mp_payment_id = 'pay-1'", $GLOBALS['wpdb']->last_query );
    }
}
```

- [ ] **Step 2: Correr, confirmar FAIL**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter SubscriptionRepositoryTest
```

- [ ] **Step 3: Crear `src/Persistence/SubscriptionRepository.php`**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Persistence;

/**
 * Acceso a wp_infouno_subscriptions y wp_infouno_payment_events.
 *
 * Extiende TenantScopedRepository: scope key = tenant_id en toda operación
 * scopeada. findByPreapprovalId/paymentEventExists son lookups por id de MP
 * (usados por el webhook, que resuelve el tenant DESDE ese id) — sin guardScope.
 */
final class SubscriptionRepository extends TenantScopedRepository {

    private string $tablePaymentEvents;

    public function __construct() {
        parent::__construct();
        $this->tablePaymentEvents = $this->db->prefix . 'infouno_payment_events';
    }

    protected function table(): string {
        return $this->db->prefix . 'infouno_subscriptions';
    }

    /** Crea la suscripción en estado 'pending'. Scope: tenant_id. */
    public function createPending( int $tenantId, string $preapprovalId, string $plan, float $amount ): int {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->insert(
            $this->table(),
            [
                'tenant_id'         => $tenantId,
                'mp_preapproval_id' => $preapprovalId,
                'plan'              => $plan,
                'status'            => 'pending',
                'amount'            => $amount,
                'currency'          => 'ARS',
            ],
            [ '%d', '%s', '%s', '%s', '%f', '%s' ]
        );

        return (int) $this->db->insert_id;
    }

    /**
     * Lookup por preapproval id (webhook). NO scopeado: resuelve el tenant desde el id.
     * @return array<string,mixed>|null
     */
    public function findByPreapprovalId( string $preapprovalId ): ?array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM `{$this->table()}` WHERE mp_preapproval_id = %s LIMIT 1",
                $preapprovalId
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Suscripción pendiente o autorizada del tenant (para bloquear duplicados). Scope: tenant_id.
     * @return array<string,mixed>|null
     */
    public function findActiveForTenant( int $tenantId ): ?array {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM `{$this->table()}`
                 WHERE tenant_id = %d AND status IN ('pending','authorized')
                 ORDER BY created_at DESC LIMIT 1",
                $tenantId
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** Marca la suscripción como authorized + actualiza next_payment + last_event_ts. Scope: tenant_id. */
    public function markAuthorized( int $tenantId, string $preapprovalId, int $eventTs, ?string $nextPaymentAt ): void {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->update(
            $this->table(),
            [
                'status'          => 'authorized',
                'next_payment_at' => $nextPaymentAt,
                'last_event_ts'   => $eventTs,
                'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ 'tenant_id' => $tenantId, 'mp_preapproval_id' => $preapprovalId ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d', '%s' ]
        );
    }

    /** Marca la suscripción como cancelled. Scope: tenant_id. */
    public function markCancelled( int $tenantId, string $preapprovalId, int $eventTs ): void {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->update(
            $this->table(),
            [ 'status' => 'cancelled', 'last_event_ts' => $eventTs, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'tenant_id' => $tenantId, 'mp_preapproval_id' => $preapprovalId ],
            [ '%s', '%d', '%s' ],
            [ '%d', '%s' ]
        );
    }

    /** Actualiza next_payment_at + last_event_ts (pago recurrente). Scope: tenant_id. */
    public function updateNextPayment( int $tenantId, string $preapprovalId, int $eventTs, ?string $nextPaymentAt ): void {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->update(
            $this->table(),
            [ 'next_payment_at' => $nextPaymentAt, 'last_event_ts' => $eventTs, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'tenant_id' => $tenantId, 'mp_preapproval_id' => $preapprovalId ],
            [ '%s', '%d', '%s' ],
            [ '%d', '%s' ]
        );
    }

    /** ¿Ya procesamos este pago? (idempotencia). Lookup por id de MP, sin scope. */
    public function paymentEventExists( string $paymentId ): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $id = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM `{$this->tablePaymentEvents}` WHERE mp_payment_id = %s LIMIT 1",
                $paymentId
            )
        );
        return (bool) $id;
    }

    /** Inserta el evento de pago (idempotente por UNIQUE(mp_payment_id)). Scope: tenant_id. */
    public function recordPaymentEvent( int $tenantId, string $paymentId, string $preapprovalId, string $status, float $amount ): void {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->insert(
            $this->tablePaymentEvents,
            [
                'tenant_id'         => $tenantId,
                'mp_payment_id'     => $paymentId,
                'mp_preapproval_id' => $preapprovalId,
                'status'            => $status,
                'amount'            => $amount,
            ],
            [ '%d', '%s', '%s', '%s', '%f' ]
        );
    }
}
```

- [ ] **Step 4: Correr, confirmar PASS + guard estático (verifica que el repo no viola nada y que la allowlist sigue vacía)**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter SubscriptionRepositoryTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter NoRawSqlOutsidePersistenceTest
```
Esperado: ambos verdes (el repo está en `src/Persistence/`, excluido del scan por terminar en `Repository.php`).

- [ ] **Step 5: Commit**
```bash
git add plugins/infouno-custom/src/Persistence/SubscriptionRepository.php plugins/infouno-custom/tests/Unit/Persistence/SubscriptionRepositoryTest.php
git commit -m "feat(billing): SubscriptionRepository — subscriptions + payment_events (fail-closed)"
```

---

## Task 6: `SubscriptionService` — orquestación + máquina de estados

**Files:**
- Create: `plugins/infouno-custom/src/Billing/SubscriptionService.php`
- Test: `plugins/infouno-custom/tests/Unit/Billing/SubscriptionServiceTest.php`

El servicio depende de `MercadoPagoClientInterface`, `SubscriptionRepository` y `TenantManager`. Para activar/suspender/bajar el plan usa métodos de `TenantManager`. **Requiere agregar a `TenantManager` un método `applyPlanChange(int $tenantId, string $plan, string $status)`** que setee `plan`, `status` y `quota_limit` (derivado de `PLAN_QUOTAS`) — todo vía SQL en TenantManager (que está autorizado por el guard, es un manager de datos excluido del scan). Esto se hace en el Step 3a.

- [ ] **Step 1: Escribir el test** — crear `tests/Unit/Billing/SubscriptionServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Billing;

use Infouno\SaaS\Billing\MercadoPagoClientInterface;
use Infouno\SaaS\Billing\SubscriptionService;
use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class SubscriptionServiceTest extends TestCase {

    private function client( array $resources ): MercadoPagoClientInterface {
        // $resources: ['preapproval'=>[...], 'payment'=>[...], 'create'=>[...]]
        return new class( $resources ) implements MercadoPagoClientInterface {
            public function __construct( private array $r ) {}
            public function createPreapproval( array $p ): array { return $this->r['create'] ?? []; }
            public function getPreapproval( string $id ): array { return $this->r['preapproval'] ?? []; }
            public function getPayment( string $id ): array { return $this->r['payment'] ?? []; }
            public function cancelPreapproval( string $id ): bool { return true; }
        };
    }

    public function test_reconcile_preapproval_authorized_activates_premium(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'findByPreapprovalId' )->willReturn( [ 'id' => 1, 'tenant_id' => 3, 'last_event_ts' => 0 ] );
        $repo->expects( $this->once() )->method( 'markAuthorized' )->with( 3, 'pa-1', $this->anything(), $this->anything() );

        $tm = $this->createMock( TenantManager::class );
        $tm->expects( $this->once() )->method( 'applyPlanChange' )->with( 3, 'premium', 'active' );

        $client = $this->client( [ 'preapproval' => [ 'id' => 'pa-1', 'status' => 'authorized', 'next_payment_date' => '2026-07-08T00:00:00Z' ] ] );
        $svc = new SubscriptionService( $client, $repo, $tm, 'https://site/back' );

        $svc->reconcileFromNotification( 'subscription_preapproval', 'pa-1', 1_700_000_100 );
    }

    public function test_reconcile_payment_rejected_suspends(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'getPaymentPreapprovalId' );
        $repo->method( 'paymentEventExists' )->willReturn( false );
        $repo->method( 'findByPreapprovalId' )->willReturn( [ 'id' => 1, 'tenant_id' => 3, 'last_event_ts' => 0 ] );
        $repo->expects( $this->once() )->method( 'recordPaymentEvent' );

        $tm = $this->createMock( TenantManager::class );
        $tm->expects( $this->once() )->method( 'applyPlanChange' )->with( 3, 'premium', 'suspended' );

        $client = $this->client( [ 'payment' => [ 'id' => 'pay-1', 'status' => 'rejected', 'transaction_amount' => 14900, 'metadata' => [], 'preapproval_id' => 'pa-1' ] ] );
        $svc = new SubscriptionService( $client, $repo, $tm, 'https://site/back' );

        $svc->reconcileFromNotification( 'payment', 'pay-1', 1_700_000_100 );
    }

    public function test_reconcile_payment_idempotent_skips_when_already_processed(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'paymentEventExists' )->willReturn( true );
        $repo->expects( $this->never() )->method( 'recordPaymentEvent' );

        $tm = $this->createMock( TenantManager::class );
        $tm->expects( $this->never() )->method( 'applyPlanChange' );

        $client = $this->client( [ 'payment' => [ 'id' => 'pay-1', 'status' => 'approved', 'preapproval_id' => 'pa-1' ] ] );
        $svc = new SubscriptionService( $client, $repo, $tm, 'https://site/back' );

        $svc->reconcileFromNotification( 'payment', 'pay-1', 1_700_000_100 );
    }

    public function test_reconcile_preapproval_cancelled_downgrades_to_free(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'findByPreapprovalId' )->willReturn( [ 'id' => 1, 'tenant_id' => 3, 'last_event_ts' => 0 ] );
        $repo->expects( $this->once() )->method( 'markCancelled' );

        $tm = $this->createMock( TenantManager::class );
        $tm->expects( $this->once() )->method( 'applyPlanChange' )->with( 3, 'free', 'active' );

        $client = $this->client( [ 'preapproval' => [ 'id' => 'pa-1', 'status' => 'cancelled' ] ] );
        $svc = new SubscriptionService( $client, $repo, $tm, 'https://site/back' );

        $svc->reconcileFromNotification( 'subscription_preapproval', 'pa-1', 1_700_000_100 );
    }

    public function test_reconcile_ignores_stale_event(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'findByPreapprovalId' )->willReturn( [ 'id' => 1, 'tenant_id' => 3, 'last_event_ts' => 1_700_000_500 ] );
        $repo->expects( $this->never() )->method( 'markAuthorized' );

        $tm = $this->createMock( TenantManager::class );
        $tm->expects( $this->never() )->method( 'applyPlanChange' );

        $client = $this->client( [ 'preapproval' => [ 'id' => 'pa-1', 'status' => 'authorized' ] ] );
        $svc = new SubscriptionService( $client, $repo, $tm, 'https://site/back' );

        // ts del evento (1_700_000_100) < last_event_ts (1_700_000_500) → ignora.
        $svc->reconcileFromNotification( 'subscription_preapproval', 'pa-1', 1_700_000_100 );
    }
}
```

> **Nota para el implementador:** el test referencia `getPaymentPreapprovalId` por error de tipeo en un `method()` sin efecto; elimínalo. El contrato real es: en `type='payment'`, el `preapproval_id` viene dentro del recurso `payment` traído de MP (campo `preapproval_id` o `metadata.preapproval_id` según la API). El servicio lo lee de ahí. Ajustá el fixture si la doc de MP indica otro campo.

- [ ] **Step 2: Correr, confirmar FAIL**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter SubscriptionServiceTest
```

- [ ] **Step 3a: Agregar `applyPlanChange` a `src/Tenant/TenantManager.php`** (entre `create` y `resetExpiredQuotas`, usando `global $wpdb` como el resto del manager — TenantManager está excluido del guard):

```php
    /**
     * Aplica un cambio de plan + estado al tenant, derivando quota_limit del plan.
     * Usado por billing (activar premium, suspender, bajar a free).
     */
    public function applyPlanChange( int $tenantId, string $plan, string $status ): void {
        global $wpdb;

        if ( ! array_key_exists( $plan, self::PLAN_QUOTAS ) ) {
            throw new \InvalidArgumentException( "Plan inválido: {$plan}" );
        }

        $table = $wpdb->prefix . 'infouno_tenants';
        $wpdb->update(
            $table,
            [
                'plan'        => $plan,
                'status'      => $status,
                'quota_limit' => self::PLAN_QUOTAS[ $plan ],
            ],
            [ 'id' => $tenantId ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );
    }
```

- [ ] **Step 3b: Crear `src/Billing/SubscriptionService.php`**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Orquesta el alta y la reconciliación de suscripciones MercadoPago.
 *
 * reconcileFromNotification es el corazón del Enfoque A: el caller ya validó la
 * firma; acá se trae el recurso autoritativo de MP y se aplica la transición de
 * estado de forma idempotente (por payment_id) y ordenada (por last_event_ts).
 */
final class SubscriptionService {

    public function __construct(
        private readonly MercadoPagoClientInterface $mp,
        private readonly SubscriptionRepository     $repo,
        private readonly TenantManager              $tenants,
        private readonly string                     $backUrl,
    ) {}

    /**
     * Crea el preapproval en MP, persiste la sub pending y devuelve el init_point.
     *
     * @param array<string,mixed> $tenant Fila del tenant (id requerido).
     * @return string init_point
     * @throws \RuntimeException si no hay precio configurado o ya existe sub activa.
     */
    public function createSubscription( array $tenant, string $payerEmail, float $premiumPriceArs ): string {
        $tenantId = (int) $tenant['id'];

        if ( $premiumPriceArs <= 0 ) {
            throw new \RuntimeException( 'Precio premium no configurado.' );
        }
        if ( $this->repo->findActiveForTenant( $tenantId ) !== null ) {
            throw new \RuntimeException( 'already_subscribed' );
        }

        $preapproval = $this->mp->createPreapproval( [
            'reason'         => 'Suscripción Premium infouno',
            'payer_email'    => $payerEmail,
            'back_url'       => $this->backUrl,
            'status'         => 'pending',
            'auto_recurring' => [
                'frequency'          => 1,
                'frequency_type'     => 'months',
                'transaction_amount' => $premiumPriceArs,
                'currency_id'        => 'ARS',
            ],
        ] );

        $preapprovalId = (string) ( $preapproval['id'] ?? '' );
        $initPoint     = (string) ( $preapproval['init_point'] ?? '' );
        if ( '' === $preapprovalId || '' === $initPoint ) {
            throw new \RuntimeException( 'MercadoPago no devolvió preapproval válido.' );
        }

        $this->repo->createPending( $tenantId, $preapprovalId, 'premium', $premiumPriceArs );

        return $initPoint;
    }

    /** Cancela la suscripción en MP. El downgrade a free lo aplica el webhook `cancelled`. */
    public function cancelSubscription( string $preapprovalId ): void {
        $this->mp->cancelPreapproval( $preapprovalId );
    }

    /** Reconcilia el estado del tenant a partir de una notificación ya verificada. */
    public function reconcileFromNotification( string $type, string $resourceId, int $eventTs ): void {
        if ( 'payment' === $type ) {
            $this->reconcilePayment( $resourceId, $eventTs );
            return;
        }
        if ( 'subscription_preapproval' === $type || 'preapproval' === $type ) {
            $this->reconcilePreapproval( $resourceId, $eventTs );
        }
        // otros topics: no-op
    }

    private function reconcilePreapproval( string $preapprovalId, int $eventTs ): void {
        $resource = $this->mp->getPreapproval( $preapprovalId );
        $status   = (string) ( $resource['status'] ?? '' );

        $sub = $this->repo->findByPreapprovalId( $preapprovalId );
        if ( null === $sub ) {
            return; // preapproval desconocido (no nuestro)
        }
        $tenantId = (int) $sub['tenant_id'];
        if ( $eventTs <= (int) ( $sub['last_event_ts'] ?? 0 ) ) {
            return; // evento viejo / fuera de orden
        }

        if ( 'authorized' === $status ) {
            $next = $this->normalizeDate( $resource['next_payment_date'] ?? null );
            $this->repo->markAuthorized( $tenantId, $preapprovalId, $eventTs, $next );
            $this->tenants->applyPlanChange( $tenantId, 'premium', 'active' );
        } elseif ( 'cancelled' === $status ) {
            $this->repo->markCancelled( $tenantId, $preapprovalId, $eventTs );
            $this->tenants->applyPlanChange( $tenantId, 'free', 'active' );
        }
    }

    private function reconcilePayment( string $paymentId, int $eventTs ): void {
        if ( $this->repo->paymentEventExists( $paymentId ) ) {
            return; // idempotente
        }

        $payment       = $this->mp->getPayment( $paymentId );
        $status        = (string) ( $payment['status'] ?? '' );
        $preapprovalId = (string) ( $payment['preapproval_id'] ?? ( $payment['metadata']['preapproval_id'] ?? '' ) );
        $amount        = (float) ( $payment['transaction_amount'] ?? 0 );

        if ( '' === $preapprovalId ) {
            return;
        }
        $sub = $this->repo->findByPreapprovalId( $preapprovalId );
        if ( null === $sub ) {
            return;
        }
        $tenantId = (int) $sub['tenant_id'];

        $this->repo->recordPaymentEvent( $tenantId, $paymentId, $preapprovalId, $status, $amount );

        if ( 'approved' === $status ) {
            $this->repo->updateNextPayment( $tenantId, $preapprovalId, $eventTs, null );
            $this->tenants->applyPlanChange( $tenantId, 'premium', 'active' );
        } elseif ( 'rejected' === $status ) {
            $this->tenants->applyPlanChange( $tenantId, 'premium', 'suspended' );
        }
    }

    private function normalizeDate( ?string $iso ): ?string {
        if ( null === $iso || '' === $iso ) {
            return null;
        }
        $ts = strtotime( $iso );
        return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
    }
}
```

> Borrar del test el `method('getPaymentPreapprovalId')` (Step 1) — no es parte del contrato. El `preapproval_id` se lee del recurso payment.

- [ ] **Step 4: Correr, confirmar PASS + suite completa**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter SubscriptionServiceTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: SubscriptionServiceTest PASS; suite completa verde (incluye un TenantManager con `applyPlanChange` nuevo — si hay un TenantManagerTest, confirmar que sigue verde).

- [ ] **Step 5: Commit**
```bash
git add plugins/infouno-custom/src/Billing/SubscriptionService.php plugins/infouno-custom/src/Tenant/TenantManager.php plugins/infouno-custom/tests/Unit/Billing/SubscriptionServiceTest.php
git commit -m "feat(billing): SubscriptionService — alta + reconciliación idempotente (máquina de estados)"
```

---

## Task 7: `BillingController` + wiring en `RestRouter`

**Files:**
- Create: `plugins/infouno-custom/src/API/BillingController.php`
- Modify: `plugins/infouno-custom/src/API/RestRouter.php`
- Modify: `plugins/infouno-custom/tests/bootstrap.php` (stub `get_userdata`, aditivo)
- Test: `plugins/infouno-custom/tests/Unit/API/BillingControllerTest.php`

- [ ] **Step 1: Agregar stub `get_userdata` a `tests/bootstrap.php`** (junto a los demás stubs de funciones WP, guardado por `function_exists`):

```php
if ( ! function_exists( 'get_userdata' ) ) {
    function get_userdata( int $userId ) {
        // Devuelve un objeto con user_email configurable por test.
        $email = $GLOBALS['__infouno_user_emails'][ $userId ] ?? ( 'user' . $userId . '@example.com' );
        return (object) [ 'ID' => $userId, 'user_email' => $email ];
    }
}
if ( ! isset( $GLOBALS['__infouno_user_emails'] ) ) {
    $GLOBALS['__infouno_user_emails'] = [];
}
```

- [ ] **Step 2: Escribir el test** — crear `tests/Unit/API/BillingControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\API;

use Infouno\SaaS\API\BillingController;
use Infouno\SaaS\Billing\BillingConfig;
use Infouno\SaaS\Billing\SubscriptionService;
use Infouno\SaaS\Billing\WebhookSignatureVerifier;
use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class BillingControllerTest extends TestCase {

    private function request( array $params = [], array $headers = [], string $body = '' ): \WP_REST_Request {
        $req = new \WP_REST_Request();
        foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
        foreach ( $headers as $k => $v ) { $req->set_header( $k, $v ); }
        if ( '' !== $body ) { $req->set_body( $body ); }
        return $req;
    }

    private function ctrl( $tm, $svc, $verifier, $repo ): BillingController {
        $cfg = $this->createMock( BillingConfig::class );
        $cfg->method( 'premiumPriceArs' )->willReturn( 14900.0 );
        return new BillingController( $tm, $svc, $verifier, $repo, $cfg );
    }

    public function test_subscribe_returns_init_point(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'requireForCurrentUser' )->willReturn( [ 'id' => 3, 'user_id' => 7, 'status' => 'active' ] );

        $svc = $this->createMock( SubscriptionService::class );
        $svc->method( 'createSubscription' )->willReturn( 'https://mp/checkout/abc' );

        $ctrl = $this->ctrl( $tm, $svc, $this->createMock( WebhookSignatureVerifier::class ), $this->createMock( SubscriptionRepository::class ) );
        $resp = $ctrl->subscribe( $this->request() );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertSame( 'https://mp/checkout/abc', $resp->get_data()['init_point'] );
    }

    public function test_subscribe_409_when_already_subscribed(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'requireForCurrentUser' )->willReturn( [ 'id' => 3, 'user_id' => 7 ] );

        $svc = $this->createMock( SubscriptionService::class );
        $svc->method( 'createSubscription' )->willThrowException( new \RuntimeException( 'already_subscribed' ) );

        $ctrl = $this->ctrl( $tm, $svc, $this->createMock( WebhookSignatureVerifier::class ), $this->createMock( SubscriptionRepository::class ) );
        $resp = $ctrl->subscribe( $this->request() );

        $this->assertInstanceOf( \WP_Error::class, $resp );
        $this->assertSame( 409, $resp->get_error_data()['status'] );
    }

    public function test_webhook_invalid_signature_does_not_reconcile(): void {
        $verifier = $this->createMock( WebhookSignatureVerifier::class );
        $verifier->method( 'verify' )->willReturn( false );

        $svc = $this->createMock( SubscriptionService::class );
        $svc->expects( $this->never() )->method( 'reconcileFromNotification' );

        $ctrl = $this->ctrl( $this->createMock( TenantManager::class ), $svc, $verifier, $this->createMock( SubscriptionRepository::class ) );
        $resp = $ctrl->webhook( $this->request(
            [ 'type' => 'payment', 'data' => [ 'id' => 'pay-1' ] ],
            [ 'x-signature' => 'ts=1,v1=bad', 'x-request-id' => 'req-1' ]
        ) );

        $this->assertSame( 200, $resp->get_status() );
    }

    public function test_webhook_valid_signature_reconciles(): void {
        $verifier = $this->createMock( WebhookSignatureVerifier::class );
        $verifier->method( 'verify' )->willReturn( true );

        $svc = $this->createMock( SubscriptionService::class );
        $svc->expects( $this->once() )->method( 'reconcileFromNotification' )->with( 'payment', 'pay-1', $this->anything() );

        $ctrl = $this->ctrl( $this->createMock( TenantManager::class ), $svc, $verifier, $this->createMock( SubscriptionRepository::class ) );
        $resp = $ctrl->webhook( $this->request(
            [ 'type' => 'payment', 'data' => [ 'id' => 'pay-1' ] ],
            [ 'x-signature' => 'ts=1,v1=good', 'x-request-id' => 'req-1' ]
        ) );

        $this->assertSame( 200, $resp->get_status() );
    }
}
```

- [ ] **Step 3: Correr, confirmar FAIL**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BillingControllerTest
```

- [ ] **Step 4: Crear `src/API/BillingController.php`** (cero `$wpdb`):

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Billing\BillingConfig;
use Infouno\SaaS\Billing\SubscriptionService;
use Infouno\SaaS\Billing\WebhookSignatureVerifier;
use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Endpoints de billing (MercadoPago Suscripciones).
 * Sin SQL crudo — delega en SubscriptionService / SubscriptionRepository.
 */
final class BillingController {

    public function __construct(
        private readonly TenantManager            $tenantManager,
        private readonly SubscriptionService      $service,
        private readonly WebhookSignatureVerifier $verifier,
        private readonly SubscriptionRepository   $repository,
        private readonly BillingConfig            $config,
    ) {}

    public function registerRoutes( string $namespace ): void {
        register_rest_route( $namespace, '/billing/subscribe', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'subscribe' ],
            'permission_callback' => [ $this, 'requireLogin' ],
        ] );
        register_rest_route( $namespace, '/billing/webhook', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'webhook' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $namespace, '/billing/subscription', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'subscription' ],
            'permission_callback' => [ $this, 'requireLogin' ],
        ] );
        register_rest_route( $namespace, '/billing/cancel', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'cancel' ],
            'permission_callback' => [ $this, 'requireLogin' ],
        ] );
    }

    public function requireLogin(): bool {
        return is_user_logged_in();
    }

    public function subscribe( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant = $this->tenantManager->requireForCurrentUser();
        $email  = $this->ownerEmail( (int) ( $tenant['user_id'] ?? 0 ) );

        try {
            $initPoint = $this->service->createSubscription( $tenant, $email, $this->config->premiumPriceArs() );
        } catch ( \RuntimeException $e ) {
            if ( 'already_subscribed' === $e->getMessage() ) {
                return new \WP_Error( 'already_subscribed', 'Ya tenés una suscripción activa.', [ 'status' => 409 ] );
            }
            return new \WP_Error( 'subscribe_failed', $e->getMessage(), [ 'status' => 500 ] );
        }

        return new \WP_REST_Response( [ 'init_point' => $initPoint ], 200 );
    }

    public function webhook( \WP_REST_Request $request ): \WP_REST_Response {
        $body      = $request->get_json_params();
        $type      = (string) ( $body['type'] ?? $body['topic'] ?? '' );
        $dataId    = (string) ( $body['data']['id'] ?? $body['id'] ?? '' );
        $signature = (string) $request->get_header( 'x-signature' );
        $requestId = (string) $request->get_header( 'x-request-id' );

        if ( '' === $dataId || ! $this->verifier->verify( $signature, $requestId, $dataId, time() ) ) {
            // Firma inválida o payload incompleto → 200 sin efecto (no revelar, sin reintentos).
            return new \WP_REST_Response( [ 'ok' => true ], 200 );
        }

        try {
            $this->service->reconcileFromNotification( $type, $dataId, time() );
        } catch ( \Throwable $e ) {
            // Nunca propagar a MP; loguear sin secretos.
            error_log( '[INFOUNO] webhook reconcile error: ' . $e->getMessage() );
        }

        return new \WP_REST_Response( [ 'ok' => true ], 200 );
    }

    public function subscription( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant = $this->tenantManager->requireForCurrentUser();
        $sub    = $this->repository->findActiveForTenant( (int) $tenant['id'] );

        return new \WP_REST_Response( [
            'plan'            => $tenant['plan'] ?? 'free',
            'tenant_status'   => $tenant['status'] ?? 'active',
            'subscription'    => $sub
                ? [ 'status' => $sub['status'], 'next_payment_at' => $sub['next_payment_at'] ?? null ]
                : null,
        ], 200 );
    }

    public function cancel( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant = $this->tenantManager->requireForCurrentUser();
        $sub    = $this->repository->findActiveForTenant( (int) $tenant['id'] );
        if ( null === $sub ) {
            return new \WP_Error( 'no_subscription', 'No hay suscripción activa.', [ 'status' => 404 ] );
        }

        $this->service->cancelSubscription( (string) $sub['mp_preapproval_id'] );
        return new \WP_REST_Response( [ 'cancelled' => true ], 200 );
    }

    private function ownerEmail( int $userId ): string {
        $user = $userId > 0 ? get_userdata( $userId ) : null;
        return $user && isset( $user->user_email ) ? (string) $user->user_email : '';
    }
}
```

> **Nota:** `cancel()` usa `$this->service->cancelSubscription(string $preapprovalId)`, que ya está definido en `SubscriptionService` (Task 6). El test de cancel no está en el set mínimo de arriba; agregalo si querés cubrir el 404/200 (mockeando `findActiveForTenant` para devolver `null` y una sub respectivamente).

- [ ] **Step 5: Wirear en `src/API/RestRouter.php`**

Seguir el patrón de `ConsentController` (línea ~45). Agregar la propiedad, instanciarla en el constructor y llamar `registerRoutes` en `register()`. Como `BillingController` tiene varias deps, instanciarlas inline:
```php
// en register(), junto a los otros registerRoutes:
$billingConfig   = new \Infouno\SaaS\Billing\BillingConfig();
$subscriptionRepo = new \Infouno\SaaS\Persistence\SubscriptionRepository();
$mpClient        = new \Infouno\SaaS\Billing\MercadoPagoClient(
    new \Infouno\SaaS\Billing\WpHttpClient(),
    $billingConfig->accessToken()
);
$billingService  = new \Infouno\SaaS\Billing\SubscriptionService(
    $mpClient,
    $subscriptionRepo,
    $this->tenantManager,
    home_url( '/billing/return' )
);
$billingController = new BillingController(
    $this->tenantManager,
    $billingService,
    new \Infouno\SaaS\Billing\WebhookSignatureVerifier( $billingConfig->webhookSecret() ),
    $subscriptionRepo,
    $billingConfig
);
$billingController->registerRoutes( self::NAMESPACE );
```
(Agregar `use` para `BillingController` en la cabecera, siguiendo el estilo de imports del archivo.)

- [ ] **Step 6: Correr test + suite completa + guard estático**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BillingControllerTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter NoRawSqlOutsidePersistenceTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: BillingControllerTest verde; **guard verde** (BillingController sin `$wpdb`, allowlist vacía intacta); suite completa verde. Verificar:
```bash
grep -n '\$wpdb' src/API/BillingController.php   # vacío
```

- [ ] **Step 7: Commit**
```bash
git add plugins/infouno-custom/src/API/BillingController.php plugins/infouno-custom/src/API/RestRouter.php plugins/infouno-custom/tests/bootstrap.php plugins/infouno-custom/tests/Unit/API/BillingControllerTest.php
git commit -m "feat(billing): BillingController + rutas REST (subscribe/webhook/subscription/cancel)"
```

---

## Task 8: `BillingSettings` — página de ajustes WP Admin + wiring en Plugin

**Files:**
- Create: `plugins/infouno-custom/src/Admin/BillingSettings.php`
- Modify: `plugins/infouno-custom/src/Plugin.php`

Esta tarea es una página de admin (sin tests unitarios; verificación: SQL-free + suite verde + guard). Persiste en la opción `infouno_billing` (array). Muestra los secretos enmascarados; al guardar, si el campo de un secreto viene vacío, conserva el valor previo (no lo borra).

- [ ] **Step 1: Crear `src/Admin/BillingSettings.php`** (cero `$wpdb` — usa `get_option`/`update_option`):

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Admin;

/**
 * Página de ajustes de billing (MercadoPago): precio premium + credenciales.
 * Persiste en la opción `infouno_billing`. Secretos enmascarados; vacío = conservar.
 */
final class BillingSettings {

    private const PAGE_SLUG  = 'infouno-billing';
    private const OPTION     = 'infouno_billing';
    private const ACTION     = 'infouno_billing_save';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
        add_action( 'admin_post_' . self::ACTION, [ $this, 'handleSave' ] );
    }

    public function addMenuPage(): void {
        add_submenu_page(
            'infouno-dashboard',
            'Billing / MercadoPago',
            'Billing',
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    public function handleSave(): void {
        check_admin_referer( self::ACTION );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'infouno-custom' ), 403 );
        }

        $current = get_option( self::OPTION, [] );
        $current = is_array( $current ) ? $current : [];

        $new = [
            'premium_price_ars' => (float) ( $_POST['premium_price_ars'] ?? 0 ), // phpcs:ignore
            'public_key'        => sanitize_text_field( wp_unslash( $_POST['public_key'] ?? '' ) ), // phpcs:ignore
            // Secretos: si vienen vacíos, conservar el valor previo.
            'access_token'      => $this->keepIfEmpty( $_POST['access_token'] ?? '', $current['access_token'] ?? '' ),     // phpcs:ignore
            'webhook_secret'    => $this->keepIfEmpty( $_POST['webhook_secret'] ?? '', $current['webhook_secret'] ?? '' ), // phpcs:ignore
        ];

        update_option( self::OPTION, $new );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&updated=1' ) );
        exit;
    }

    private function keepIfEmpty( string $incoming, string $previous ): string {
        $incoming = sanitize_text_field( wp_unslash( $incoming ) );
        return '' !== $incoming ? $incoming : $previous;
    }

    public function renderPage(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $opt   = get_option( self::OPTION, [] );
        $opt   = is_array( $opt ) ? $opt : [];
        $price = (float) ( $opt['premium_price_ars'] ?? 0 );
        $pk    = (string) ( $opt['public_key'] ?? '' );
        $hasToken  = '' !== (string) ( $opt['access_token'] ?? '' );
        $hasSecret = '' !== (string) ( $opt['webhook_secret'] ?? '' );

        echo '<div class="wrap"><h1>' . esc_html__( 'Billing — MercadoPago', 'infouno-custom' ) . '</h1>';
        if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ajustes guardados.', 'infouno-custom' ) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( self::ACTION );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<table class="form-table">';
        printf(
            '<tr><th>%s</th><td><input type="number" step="0.01" min="0" name="premium_price_ars" value="%s" class="regular-text"></td></tr>',
            esc_html__( 'Precio premium (ARS/mes)', 'infouno-custom' ),
            esc_attr( (string) $price )
        );
        printf(
            '<tr><th>%s</th><td><input type="text" name="public_key" value="%s" class="regular-text"></td></tr>',
            esc_html__( 'Public Key', 'infouno-custom' ),
            esc_attr( $pk )
        );
        printf(
            '<tr><th>%s</th><td><input type="password" name="access_token" placeholder="%s" class="regular-text" autocomplete="new-password"></td></tr>',
            esc_html__( 'Access Token', 'infouno-custom' ),
            esc_attr( $hasToken ? '•••• (configurado — dejar vacío para conservar)' : 'sin configurar' )
        );
        printf(
            '<tr><th>%s</th><td><input type="password" name="webhook_secret" placeholder="%s" class="regular-text" autocomplete="new-password"></td></tr>',
            esc_html__( 'Webhook Secret', 'infouno-custom' ),
            esc_attr( $hasSecret ? '•••• (configurado — dejar vacío para conservar)' : 'sin configurar' )
        );
        echo '</table>';
        submit_button( esc_html__( 'Guardar', 'infouno-custom' ) );
        echo '</form></div>';
    }
}
```

- [ ] **Step 2: Wirear en `src/Plugin.php`** — instanciar e inicializar `BillingSettings` donde se inicializan los otros paneles admin (p. ej. junto a `botDashboard`/`opportunityDashboard`). Seguir el patrón existente:
```php
$this->billingSettings = new \Infouno\SaaS\Admin\BillingSettings();
$this->billingSettings->init();
```
(Agregar la propiedad y el `use` según el estilo del archivo.)

- [ ] **Step 3: Verificar SQL-free + suite + guard**
```bash
grep -n '\$wpdb' src/Admin/BillingSettings.php   # vacío
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter NoRawSqlOutsidePersistenceTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: grep vacío; guard verde; suite completa verde.

- [ ] **Step 4: Commit**
```bash
git add plugins/infouno-custom/src/Admin/BillingSettings.php plugins/infouno-custom/src/Plugin.php
git commit -m "feat(billing): página de ajustes WP Admin (precio + credenciales MP)"
```

---

## Self-Review: Cobertura vs Spec

| Requisito del spec | Task(s) | Estado |
|---|---|---|
| Migración v11 (subscriptions + payment_events) | Task 1 | ✅ |
| Config env-first (token/secret) + precio (setting) | Task 2 (`BillingConfig`), Task 8 (settings) | ✅ |
| Verificación de firma `x-signature` + anti-replay | Task 3 | ✅ |
| Cliente MP sobre transporte inyectable (testeable) | Task 4 | ✅ |
| `SubscriptionRepository` extiende la base (fail-closed, idempotencia) | Task 5 | ✅ |
| Máquina de estados: authorized→premium, approved→active, rejected→suspended, cancelled→free | Task 6 | ✅ |
| Idempotencia (payment_id) + orden (last_event_ts) | Tasks 5-6 | ✅ |
| Endpoints subscribe/webhook/subscription/cancel | Task 7 | ✅ |
| Webhook firma inválida → 200 sin efecto | Task 7 | ✅ |
| `tenant_id` del servidor / del mapeo preapproval→tenant | Tasks 5-7 | ✅ |
| Settings WP Admin (precio + credenciales enmascaradas) | Task 8 | ✅ |
| Sin `$wpdb` en API/Admin (guard total Bloque D) | Tasks 7-8 (grep + guard) | ✅ |
| `INFOUNO_DB_VERSION` + `migrateTo11` + endpoints con permission_callback | Tasks 1, 7 | ✅ |

**Notas / riesgos heredados del spec:**
- Confirmar contra la doc vigente de MP: formato exacto de `x-signature`, el `type`/`topic` del webhook, y de dónde sale el `preapproval_id` dentro del recurso `payment` (Tasks 3, 6 lo dejan parametrizado/leído del recurso).
- `cancelPreapproval` se implementó como `POST {status:cancelled}`; si MP exige `PUT`, agregar `put()` al `HttpClientInterface` + `WpHttpClient` (aditivo) — Task 4.
- `back_url` usa `home_url('/billing/return')`; ajustar a la ruta real de retorno del front cuando exista.
- Si existe `TenantManagerTest`, confirmar que el nuevo `applyPlanChange` no rompe nada (Task 6 Step 4 corre la suite completa).
