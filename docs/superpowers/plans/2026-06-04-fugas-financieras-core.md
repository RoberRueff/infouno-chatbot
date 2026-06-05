# Fugas Financieras del Core — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir la cuota de tokens en un límite duro y race-safe, y eliminar el descuadre/doble-cobro del fallback y el cobro $0.

**Architecture:** Reservar-y-reconciliar: `ChatPipeline` reserva un presupuesto estimado vía un UPDATE atómico-condicional antes del LLM y reconcilia a tokens reales después (o libera si falla). `LLMRouter` adopta commit-on-first-delta para preservar el streaming sin re-emitir/re-cobrar en reintentos/fallback. Un `TokenEstimator` provee la heurística de estimación.

**Tech Stack:** PHP 8.1+ estricto, WordPress, MySQL, PHPUnit 11 (con dg/bypass-finals), Docker para correr la toolchain.

**Spec de referencia:** `docs/superpowers/specs/2026-06-04-fugas-financieras-core-design.md`

**Rama de trabajo:** `feature/financial-core-fixes`

## Execution Environment (CRÍTICO — para todos los subagentes)

NO hay PHP/Composer local. Todo corre vía Docker desde el repo root `/Users/Rober/Desktop/Proyectos/infouno-chatbot/infouno-chatbot`:
- PHPUnit (archivo): `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage <ruta-test>`
- PHPUnit (suite): `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage`
- Lint: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli php -l <archivo>`

`tests/bootstrap.php` YA tiene: `\DG\BypassFinals::enable()` (permite mockear clases `final`), `define('ARRAY_A')`, stubs de `get_transient`/`set_transient`/`get_option`/`current_time`/`do_action`/`sanitize_*`, y un `WpdbStub` con `query()`/`stub_query_result`, `get_row`/`stub_get_row`, `insert`/`onInsert`, `get_results`/`stub_get_results`. **Agregá al bootstrap solo lo que falte, con guards** (`if (!function_exists)` / `if (!defined)` / chequeando que el método no exista ya). La suite actual está VERDE (81 tests).

## File Structure

**Nuevo:**
- `src/LLM/TokenEstimator.php` — heurística de estimación de tokens (estático, puro).

**Modificados:**
- `src/Tenant/TenantManager.php` — `reserve()`, `reconcile()`, `release()` (reemplazan el rol de `incrementQuota` en el chat).
- `src/LLM/LLMRouter.php` — commit-on-first-delta + providers inyectables para test.
- `src/Chat/ChatPipeline.php` — flujo reservar → LLM → reconcile/release.
- `tests/bootstrap.php` — solo si falta algún stub (probablemente no haga falta nada).

---

## Task 1: TokenEstimator

**Files:**
- Create: `plugins/infouno-custom/src/LLM/TokenEstimator.php`
- Test: `plugins/infouno-custom/tests/Unit/LLM/TokenEstimatorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\LLM;

use Infouno\SaaS\LLM\TokenEstimator;
use PHPUnit\Framework\TestCase;

final class TokenEstimatorTest extends TestCase {

    public function test_estimate_rounds_up_chars_over_four(): void {
        // 8 chars → 2 tokens; 9 chars → 3 (ceil 9/4 = 3)
        $this->assertSame( 2, TokenEstimator::estimate( 'abcdefgh' ) );
        $this->assertSame( 3, TokenEstimator::estimate( 'abcdefghi' ) );
    }

    public function test_estimate_minimum_is_one(): void {
        $this->assertSame( 1, TokenEstimator::estimate( '' ) );
        $this->assertSame( 1, TokenEstimator::estimate( 'a' ) );
    }

    public function test_estimate_messages_sums_each_content(): void {
        $messages = [
            [ 'role' => 'system', 'content' => 'abcdefgh' ],   // 2
            [ 'role' => 'user',   'content' => 'abcd' ],        // 1
        ];
        $this->assertSame( 3, TokenEstimator::estimateMessages( $messages ) );
    }

    public function test_estimate_messages_empty_is_zero(): void {
        $this->assertSame( 0, TokenEstimator::estimateMessages( [] ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/LLM/TokenEstimatorTest.php`
Expected: FAIL con `Class "Infouno\SaaS\LLM\TokenEstimator" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\LLM;

/**
 * Estimación heurística de tokens (chars/4, redondeo hacia arriba).
 * Conservadora: se usa para reservar presupuesto de cuota y como respaldo
 * cuando el proveedor no devuelve el conteo real de uso.
 */
final class TokenEstimator {

    private const CHARS_PER_TOKEN = 4;

    /** @return int >= 1 para texto no vacío; 1 para vacío. */
    public static function estimate( string $text ): int {
        $len = mb_strlen( $text, 'UTF-8' );
        return (int) max( 1, (int) ceil( $len / self::CHARS_PER_TOKEN ) );
    }

    /**
     * Suma la estimación del `content` de cada mensaje.
     * @param array<array{role?:string,content?:string}> $messages
     */
    public static function estimateMessages( array $messages ): int {
        $total = 0;
        foreach ( $messages as $m ) {
            $content = (string) ( $m['content'] ?? '' );
            if ( '' !== $content ) {
                $total += self::estimate( $content );
            }
        }
        return $total;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/LLM/TokenEstimatorTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
cd /Users/Rober/Desktop/Proyectos/infouno-chatbot/infouno-chatbot
git add plugins/infouno-custom/src/LLM/TokenEstimator.php plugins/infouno-custom/tests/Unit/LLM/TokenEstimatorTest.php
git commit -m "feat: TokenEstimator (heurística chars/4 para reserva de cuota)"
```

> Nota: `estimate('')` da 1 por el `max(1,...)`, pero `estimateMessages` ignora los content vacíos (no suma el mínimo de 1 por mensaje vacío) — por eso el test de mensajes vacíos espera 0. Es intencional: solo se estima contenido real.

---

## Task 2: TenantManager — reserve / reconcile / release

**Files:**
- Modify: `plugins/infouno-custom/src/Tenant/TenantManager.php`
- Test: `plugins/infouno-custom/tests/Unit/Tenant/TenantManagerQuotaTest.php`

> El `WpdbStub` ya tiene `query()` que devuelve `stub_query_result`. `$wpdb->query()` para un UPDATE devuelve el número de filas afectadas; `reserve` usa ese valor. No hace falta tocar el bootstrap.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Tenant;

use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class TenantManagerQuotaTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->stub_query_result = 0;
        $GLOBALS['wpdb']->last_query        = '';
    }

    public function test_reserve_returns_true_when_update_affects_one_row(): void {
        $GLOBALS['wpdb']->stub_query_result = 1;
        $this->assertTrue( ( new TenantManager() )->reserve( 7, 1500 ) );
    }

    public function test_reserve_returns_false_when_no_row_affected(): void {
        $GLOBALS['wpdb']->stub_query_result = 0;
        $this->assertFalse( ( new TenantManager() )->reserve( 7, 1500 ) );
    }

    public function test_reserve_query_is_atomic_conditional(): void {
        $GLOBALS['wpdb']->stub_query_result = 1;
        ( new TenantManager() )->reserve( 7, 1500 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'quota_used = quota_used + 1500', $q );
        $this->assertStringContainsString( 'quota_used + 1500 <= quota_limit', $q );
        $this->assertStringContainsString( "status = 'active'", $q );
    }

    public function test_reconcile_adjusts_reserved_to_actual(): void {
        $GLOBALS['wpdb']->stub_query_result = 1;
        // get_row para el chequeo de alerta 90%
        $GLOBALS['wpdb']->stub_get_row = [ 'quota_used' => 100, 'quota_limit' => 50000 ];
        ( new TenantManager() )->reconcile( 7, 1500, 320 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( '- 1500 + 320', $q );
        $this->assertStringContainsString( 'GREATEST(0', $q );
    }

    public function test_release_subtracts_reserved_floored_at_zero(): void {
        $GLOBALS['wpdb']->stub_query_result = 1;
        ( new TenantManager() )->release( 7, 1500 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'GREATEST(0, quota_used - 1500)', $q );
    }
}
```

- [ ] **Step 2: Add `last_query` capture to WpdbStub (only if missing)**

Abrí `tests/bootstrap.php`. Si `WpdbStub` NO tiene una propiedad `last_query`, agregala y hacé que `query()` la registre. Reemplazá el método `query` por:

```php
    public mixed $last_query = '';

    public function query( string $query ): mixed {
        $this->last_query = $query;
        return $this->stub_query_result;
    }
```

(Si `query` ya existe sin `last_query`, agregá la propiedad `public mixed $last_query = '';` y la línea `$this->last_query = $query;` dentro del método existente. No dupliques el método.)

- [ ] **Step 3: Run test to verify it fails**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/Tenant/TenantManagerQuotaTest.php`
Expected: FAIL — `reserve`/`reconcile`/`release` no existen aún.

- [ ] **Step 4: Implement reserve / reconcile / release in TenantManager**

Agregá estos tres métodos a `src/Tenant/TenantManager.php` (después de `incrementQuota`). Reutilizá el helper de alerta existente `dispatchQuotaAlert`.

```php
    /**
     * Reserva atómica-condicional de presupuesto de cuota ANTES de llamar al LLM.
     * El UPDATE solo afecta la fila si el tenant está activo y la reserva entra en el límite,
     * por lo que es race-safe (la condición se evalúa atómicamente en MySQL).
     *
     * @return bool true si reservó; false si no entra (cuota agotada o tenant inactivo).
     */
    public function reserve( int $tenantId, int $estimate ): bool {
        if ( $estimate <= 0 ) {
            return true; // nada que reservar
        }

        global $wpdb;
        $table = $wpdb->prefix . 'infouno_tenants';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET quota_used = quota_used + %d
                  WHERE id = %d
                    AND status = 'active'
                    AND quota_used + %d <= quota_limit",
                $estimate,
                $tenantId,
                $estimate
            )
        );

        return 1 === (int) $affected;
    }

    /**
     * Reconcilia la reserva con el consumo real tras el LLM:
     * quota_used = GREATEST(0, quota_used - reserved + actual).
     * Luego evalúa la alerta de 90% (igual que incrementQuota).
     */
    public function reconcile( int $tenantId, int $reserved, int $actual ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'infouno_tenants';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET quota_used = GREATEST(0, quota_used - %d + %d)
                  WHERE id = %d",
                $reserved,
                $actual,
                $tenantId
            )
        );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT quota_used, quota_limit FROM `{$table}` WHERE id = %d LIMIT 1",
                $tenantId
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return;
        }

        $limit = (int) $row['quota_limit'];
        $used  = (int) $row['quota_used'];

        if ( $limit > 0 && $used >= (int) ( $limit * 0.9 ) ) {
            $this->dispatchQuotaAlert( $tenantId, $used, $limit );
        }
    }

    /**
     * Libera una reserva no consumida (el request falló antes de generar tokens).
     * quota_used = GREATEST(0, quota_used - reserved).
     */
    public function release( int $tenantId, int $reserved ): void {
        if ( $reserved <= 0 ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'infouno_tenants';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET quota_used = GREATEST(0, quota_used - %d) WHERE id = %d",
                $reserved,
                $tenantId
            )
        );
    }
```

> El `$wpdb->prepare` del stub sustituye `%d` por el valor, por eso el test puede asertar `quota_used + 1500 <= quota_limit` y `- 1500 + 320` sobre `last_query`.

- [ ] **Step 5: Run test to verify it passes**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/Tenant/TenantManagerQuotaTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Run full suite (regresión)**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage`
Expected: toda la suite verde.

- [ ] **Step 7: Commit**

```bash
git add plugins/infouno-custom/src/Tenant/TenantManager.php plugins/infouno-custom/tests/Unit/Tenant/TenantManagerQuotaTest.php plugins/infouno-custom/tests/bootstrap.php
git commit -m "feat: TenantManager reserve/reconcile/release (cuota dura race-safe)"
```

---

## Task 3: LLMRouter — commit-on-first-delta + providers inyectables

**Files:**
- Modify: `plugins/infouno-custom/src/LLM/LLMRouter.php`
- Test: `plugins/infouno-custom/tests/Unit/LLM/LLMRouterFallbackTest.php`

> Para testear sin red, los providers deben ser inyectables. Hoy `LLMRouter::__construct()` los hardcodea. Se agrega un parámetro opcional retrocompatible (`Plugin.php` sigue haciendo `new LLMRouter()`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\LLM;

use Infouno\SaaS\LLM\LLMProviderInterface;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\LLM\StreamResult;
use PHPUnit\Framework\TestCase;

final class LLMRouterFallbackTest extends TestCase {

    /** Provider configurable: emite N deltas y opcionalmente lanza después. */
    private function provider( array $deltas, ?int $throwCode, int $in = 5, int $out = 7 ): LLMProviderInterface {
        return new class( $deltas, $throwCode, $in, $out ) implements LLMProviderInterface {
            public int $calls = 0;
            public function __construct(
                private array $deltas,
                private ?int $throwCode,
                private int $in,
                private int $out,
            ) {}
            public function streamChat( array $messages, array $options, callable $onChunk ): StreamResult {
                $this->calls++;
                foreach ( $this->deltas as $d ) { $onChunk( $d ); }
                if ( null !== $this->throwCode ) {
                    throw new \RuntimeException( 'boom', $this->throwCode );
                }
                return new StreamResult( $this->in, $this->out, 'stop', 'anthropic', 'm' );
            }
        };
    }

    public function test_no_fallback_after_first_delta_emitted(): void {
        // primario emite 1 delta y LUEGO lanza 503 → NO debe usar fallback (re-lanza).
        $primary  = $this->provider( [ 'Hola' ], 503 );
        $fallback = $this->provider( [ 'NO-DEBE-VERSE' ], null );

        $router  = new LLMRouter( [ 'anthropic' => $primary, 'openai' => $fallback ] );
        $emitted = [];

        $this->expectException( \RuntimeException::class );
        try {
            $router->stream(
                [ 'llm_provider' => 'anthropic', 'llm_model' => 'claude-haiku-4-5-20251001', 'settings' => [] ],
                [ [ 'role' => 'user', 'content' => 'hi' ] ],
                function ( string $d ) use ( &$emitted ) { $emitted[] = $d; },
                'free'
            );
        } finally {
            $this->assertSame( [ 'Hola' ], $emitted );  // solo el delta del primario, NUNCA el fallback
            $this->assertSame( 0, $fallback->calls );    // fallback jamás se invocó
        }
    }

    public function test_fallback_runs_when_primary_fails_before_emitting(): void {
        // primario lanza 503 SIN emitir → fallback toma el relevo y responde.
        $primary  = $this->provider( [], 503 );
        $fallback = $this->provider( [ 'desde-fallback' ], null, 3, 4 );

        $router  = new LLMRouter( [ 'anthropic' => $primary, 'openai' => $fallback ] );
        $emitted = [];

        $result = $router->stream(
            [ 'llm_provider' => 'anthropic', 'llm_model' => 'claude-haiku-4-5-20251001', 'settings' => [] ],
            [ [ 'role' => 'user', 'content' => 'hi' ] ],
            function ( string $d ) use ( &$emitted ) { $emitted[] = $d; },
            'free'
        );

        $this->assertSame( [ 'desde-fallback' ], $emitted );
        $this->assertSame( 1, $fallback->calls );
        $this->assertSame( 7, $result->totalTokens() ); // 3 + 4 del fallback
    }
}
```

> **Verificá la firma real de `StreamResult`** antes de correr: `src/LLM/StreamResult.php`. El constructor es `(int $inputTokens, int $outputTokens, string $finishReason, string $provider, string $model)` y existe `totalTokens()`. Si difiere, ajustá las construcciones del test (NO la clase de producción).

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/LLM/LLMRouterFallbackTest.php`
Expected: FAIL — el constructor de `LLMRouter` no acepta providers, y/o el fallback re-emite (doble emisión) con el código actual.

- [ ] **Step 3: Modify LLMRouter**

a) Constructor inyectable. Reemplazá:

```php
    public function __construct() {
        $this->providers = [
            'anthropic' => new AnthropicProvider(),
            'openai'    => new OpenAIProvider(),
        ];
    }
```

por:

```php
    /** @param array<string,LLMProviderInterface>|null $providers Inyectable para tests; por defecto los reales. */
    public function __construct( ?array $providers = null ) {
        $this->providers = $providers ?? [
            'anthropic' => new AnthropicProvider(),
            'openai'    => new OpenAIProvider(),
        ];
    }
```

b) `stream()` con commit-on-first-delta. Reemplazá el cuerpo del método `stream` (desde la construcción de `$options` hasta el `throw` final) por esta versión, que envuelve `$onChunk` con un flag `emitted` y prohíbe reintento/fallback una vez emitido:

```php
    public function stream( array $bot, array $messages, callable $onChunk, string $tenantPlan = 'free' ): StreamResult {
        $primaryName  = $bot['llm_provider'] ?? 'anthropic';
        $fallbackName = 'anthropic' === $primaryName ? 'openai' : 'anthropic';
        $settings     = $bot['settings'] ?? [];

        $options = [
            'model'       => $this->resolveModel( $bot['llm_model'] ?? self::DEFAULT_MODEL, $tenantPlan ),
            'max_tokens'  => (int) ( $settings['max_tokens'] ?? 1024 ),
            'temperature' => (float) ( $settings['temperature'] ?? 0.7 ),
        ];

        // Commit-on-first-delta: una vez que se emitió contenido al cliente no se puede
        // des-emitir, así que se prohíbe cualquier reintento/fallback posterior.
        $emitted       = false;
        $wrapped       = static function ( string $delta ) use ( $onChunk, &$emitted ) {
            $emitted = true;
            $onChunk( $delta );
        };
        $lastException = null;

        // Proveedor primario con backoff exponencial.
        for ( $attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++ ) {
            try {
                if ( $attempt > 0 ) {
                    $this->sleep( self::BASE_DELAY_MS * ( 2 ** ( $attempt - 1 ) ) );
                }
                return $this->providers[ $primaryName ]->streamChat( $messages, $options, $wrapped );
            } catch ( \RuntimeException $e ) {
                $lastException = $e;
                // Si ya se emitió contenido, no se puede reintentar sin duplicar: propagar.
                if ( $emitted ) {
                    throw $e;
                }
                if ( ! $this->isRetryable( $e->getCode() ) ) {
                    break;
                }
            }
        }

        // Fallback (un intento) SOLO si todavía no se emitió nada.
        if ( ! $emitted && isset( $this->providers[ $fallbackName ] ) ) {
            do_action(
                'infouno_model_fallback',
                $primaryName,
                $fallbackName,
                $options['model'],
                $lastException?->getMessage() ?? ''
            );
            try {
                return $this->providers[ $fallbackName ]->streamChat( $messages, $options, $wrapped );
            } catch ( \RuntimeException $e ) {
                $lastException = $e;
            }
        }

        throw new \RuntimeException(
            'Todos los proveedores de IA fallaron. ' . ( $lastException?->getMessage() ?? '' ),
            503
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/LLM/LLMRouterFallbackTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run full suite (regresión)**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage`
Expected: toda la suite verde.

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/LLM/LLMRouter.php plugins/infouno-custom/tests/Unit/LLM/LLMRouterFallbackTest.php
git commit -m "fix: LLMRouter commit-on-first-delta (sin doble-emisión ni doble-cobro en fallback)"
```

---

## Task 4: ChatPipeline — reservar / reconciliar / liberar

**Files:**
- Modify: `plugins/infouno-custom/src/Chat/ChatPipeline.php`
- Test: `plugins/infouno-custom/tests/Unit/Chat/ChatPipelineQuotaTest.php`

> Reemplaza el `incrementQuota` post-LLM por reserve (pre-LLM) + reconcile/release (post-LLM). El resto del pipeline (InputGuard, validateForChat, rate-limit, getOrCreate, techo conv, history, saveExchange, leadService) no cambia.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Chat;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Bot\QuotaService;
use Infouno\SaaS\Chat\BufferedSink;
use Infouno\SaaS\Chat\ChatPipeline;
use Infouno\SaaS\Chat\ConversationRepository;
use Infouno\SaaS\Chat\PipelineContext;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\LLM\StreamResult;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class ChatPipelineQuotaTest extends TestCase {

    private function bot(): array {
        return [
            'id'            => 7,
            'tenant_id'     => 3,
            'system_prompt' => 'Sos un asistente.',
            'settings'      => [ 'context_window' => 10, 'max_conv_tokens' => 20000, 'max_tokens' => 1024 ],
        ];
    }

    private function convRepo(): ConversationRepository {
        $repo = $this->createMock( ConversationRepository::class );
        $repo->method( 'getOrCreate' )->willReturn( [ 'id' => 99 ] );
        $repo->method( 'totalTokensForConversation' )->willReturn( 0 );
        $repo->method( 'getRecentMessages' )->willReturn( [] );
        return $repo;
    }

    public function test_reserves_before_llm_and_reconciles_to_actual(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'validateForChat' )->willReturn( [ 'plan' => 'free' ] );
        $tm->method( 'reserve' )->willReturn( true );
        $tm->expects( $this->once() )->method( 'reconcile' )
           ->with( 3, $this->greaterThan( 0 ), 30 );   // actual = 10 + 20
        $tm->expects( $this->never() )->method( 'release' );

        $llm = $this->createMock( LLMRouter::class );
        $llm->method( 'stream' )->willReturnCallback(
            function ( $bot, $msgs, $cb ) { $cb( 'ok' ); return new StreamResult( 10, 20, 'stop', 'anthropic', 'm' ); }
        );

        $pipeline = new ChatPipeline( $tm, $this->createMock( BotManager::class ),
            $this->createMock( QuotaService::class ), $this->convRepo(), $llm, null );

        $pipeline->run( $this->bot(), 'tg:1', 'hola', new BufferedSink(), PipelineContext::forChannel( 'telegram', '1' ) );
    }

    public function test_rejects_402_when_reserve_fails_without_calling_llm(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'validateForChat' )->willReturn( [ 'plan' => 'free' ] );
        $tm->method( 'reserve' )->willReturn( false );

        $llm = $this->createMock( LLMRouter::class );
        $llm->expects( $this->never() )->method( 'stream' );

        $pipeline = new ChatPipeline( $tm, $this->createMock( BotManager::class ),
            $this->createMock( QuotaService::class ), $this->convRepo(), $llm, null );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionCode( 402 );
        $pipeline->run( $this->bot(), 'tg:1', 'hola', new BufferedSink(), PipelineContext::forChannel( 'telegram', '1' ) );
    }

    public function test_releases_reservation_when_llm_throws(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'validateForChat' )->willReturn( [ 'plan' => 'free' ] );
        $tm->method( 'reserve' )->willReturn( true );
        $tm->expects( $this->once() )->method( 'release' )->with( 3, $this->greaterThan( 0 ) );
        $tm->expects( $this->never() )->method( 'reconcile' );

        $llm = $this->createMock( LLMRouter::class );
        $llm->method( 'stream' )->willThrowException( new \RuntimeException( 'IA caída', 503 ) );

        $pipeline = new ChatPipeline( $tm, $this->createMock( BotManager::class ),
            $this->createMock( QuotaService::class ), $this->convRepo(), $llm, null );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionCode( 503 );
        $pipeline->run( $this->bot(), 'tg:1', 'hola', new BufferedSink(), PipelineContext::forChannel( 'telegram', '1' ) );
    }

    public function test_charges_estimate_when_usage_missing_but_text_emitted(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'validateForChat' )->willReturn( [ 'plan' => 'free' ] );
        $tm->method( 'reserve' )->willReturn( true );
        // result con 0 tokens pero hubo texto → reconcile con actual > 0
        $tm->expects( $this->once() )->method( 'reconcile' )
           ->with( 3, $this->greaterThan( 0 ), $this->greaterThan( 0 ) );

        $llm = $this->createMock( LLMRouter::class );
        $llm->method( 'stream' )->willReturnCallback(
            function ( $bot, $msgs, $cb ) { $cb( 'respuesta con texto real' ); return new StreamResult( 0, 0, 'stop', 'anthropic', 'm' ); }
        );

        $pipeline = new ChatPipeline( $tm, $this->createMock( BotManager::class ),
            $this->createMock( QuotaService::class ), $this->convRepo(), $llm, null );

        $pipeline->run( $this->bot(), 'tg:1', 'hola', new BufferedSink(), PipelineContext::forChannel( 'telegram', '1' ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/Chat/ChatPipelineQuotaTest.php`
Expected: FAIL — el pipeline aún llama `incrementQuota`, no `reserve`/`reconcile`/`release`.

- [ ] **Step 3: Modify ChatPipeline**

a) Agregá el `use` de TokenEstimator al inicio del archivo, junto a los demás `use`:

```php
use Infouno\SaaS\LLM\TokenEstimator;
```

b) Localizá el bloque que hoy hace (pasos 6–9 del método `run`):

```php
        // 6. Incrementar rate limit antes de llamar al LLM (evita retry flooding)
        $this->quotaService->increment( $conversationKey, $ctx->rateLimitSecondaryKey );

        // 7. Stream al sink — acumula la respuesta completa para persistirla
        $fullResponse = '';
        $result       = $this->llmRouter->stream(
            $bot,
            $messages,
            static function ( string $delta ) use ( $sink, &$fullResponse ) {
                $fullResponse .= $delta;
                $sink->write( $delta );
            },
            $tenantPlan
        );
        $sink->finish();

        // 8. Persistir el intercambio y actualizar cuota mensual
        $this->conversationRepo->saveExchange(
            $convId,
            $userMessage,
            $fullResponse,
            $result->inputTokens,
            $result->outputTokens,
            $tenantPlan
        );
        $this->tenantManager->incrementQuota( $tenantId, $result->totalTokens() );
```

y reemplazalo por (reserva antes del LLM; reconcile/release alrededor):

```php
        // 6. Reservar presupuesto de cuota ANTES del LLM — límite duro y race-safe.
        $maxTokens = (int) ( $bot['settings']['max_tokens'] ?? 1024 );
        $estimate  = TokenEstimator::estimateMessages( $messages ) + $maxTokens;
        if ( ! $this->tenantManager->reserve( $tenantId, $estimate ) ) {
            throw new \RuntimeException( 'Cuota mensual agotada.', 402 );
        }

        // 7. Incrementar rate limit antes de llamar al LLM (evita retry flooding)
        $this->quotaService->increment( $conversationKey, $ctx->rateLimitSecondaryKey );

        // 8. Stream al sink — acumula la respuesta completa para persistirla.
        //    Reconcilia la reserva con el consumo real; libera si el request falla.
        $fullResponse = '';
        try {
            $result = $this->llmRouter->stream(
                $bot,
                $messages,
                static function ( string $delta ) use ( $sink, &$fullResponse ) {
                    $fullResponse .= $delta;
                    $sink->write( $delta );
                },
                $tenantPlan
            );
            $sink->finish();

            // Conteo real; si el proveedor no devolvió usage pero hubo texto, estimar.
            $actual = $result->totalTokens();
            if ( 0 === $actual && '' !== trim( $fullResponse ) ) {
                $actual = TokenEstimator::estimateMessages( $messages ) + TokenEstimator::estimate( $fullResponse );
            }

            $this->conversationRepo->saveExchange(
                $convId,
                $userMessage,
                $fullResponse,
                $result->inputTokens,
                $result->outputTokens,
                $tenantPlan
            );

            $this->tenantManager->reconcile( $tenantId, $estimate, $actual );
        } catch ( \Throwable $e ) {
            $this->tenantManager->release( $tenantId, $estimate );
            throw $e;
        }
```

> El `saveExchange` queda DENTRO del try (igual que antes corría tras el stream exitoso). El Lead Engine (paso siguiente, best-effort) no cambia y queda DESPUÉS del bloque try/catch.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/Chat/ChatPipelineQuotaTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Run full suite (regresión)**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage`
Expected: toda la suite verde. (Si `ChatPipelineTest` original asertaba `incrementQuota`, actualizalo para reflejar reserve/reconcile — ese test previo verificaba el viejo flujo; ajustalo a la nueva expectativa sin debilitar la cobertura.)

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/Chat/ChatPipeline.php plugins/infouno-custom/tests/Unit/Chat/ChatPipelineQuotaTest.php plugins/infouno-custom/tests/Unit/Chat/ChatPipelineTest.php
git commit -m "fix: ChatPipeline reserva/reconcilia cuota (límite duro + sin cobro \$0)"
```

---

## Cierre

Al completar las 4 tareas:
- La cuota se reserva atómicamente antes del LLM (límite duro, race-safe) y se reconcilia al consumo real.
- El fallback/reintento nunca re-emite ni descuadra tokens (commit-on-first-delta), preservando el streaming en vivo.
- Un request fallido libera su reserva (no cobra); una respuesta sin `usage` cobra el estimado (no $0).

**Verificación de cierre recomendada (fuera del plan):** correr el smoke-test de canales y observar, con una clave LLM real, que `quota_used` sube acorde al consumo y que una respuesta exitosa cobra > 0. El `incrementQuota` queda sin uso en el chat; verificar con grep si algún otro caller lo usa antes de decidir removerlo.
