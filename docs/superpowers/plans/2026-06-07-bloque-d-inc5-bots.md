# Bloque D Incremento 5 — Dominio Bots (cierre) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mover el SQL crudo de `API/BotController.php`, `Admin/BotWizard.php` y `Admin/BotDashboard.php` a `Bot\BotManager` (que pasa a extender la base fail-closed `TenantScopedRepository`), y **vaciar la ALLOWLIST** del guard estático (3→0) → aislamiento total, cierre del Bloque D.

**Architecture:** `BotManager` (8 métodos, `global $wpdb`, no extiende la base) pasa a `extends TenantScopedRepository`: `guardScope()` en sus métodos tenant-scoped, `global $wpdb`→`$this->db`, `table()`. Se le agregan 2 métodos que absorben el SQL de los 3 archivos: `saveWizardResult` (el update duplicado en BotController::wizard y BotWizard) y `leadCountsForBots` (el GROUP BY de leads del BotDashboard). Los 3 archivos ya tienen `BotManager` inyectado → solo delegan. Excepción de scope: `getByPublicToken()` NO lleva guardScope (es el lookup público del widget que *resuelve* el tenant a partir del token). Al quitar las 3 entradas restantes, la ALLOWLIST queda vacía y el guard pasa a ser total.

**Tech Stack:** PHP 8.1+, PSR-4 `Infouno\SaaS\`, PHPUnit en Docker `php:8.3-cli` (NO hay PHP local), WpdbStub de `tests/bootstrap.php`.

**Comando de tests (desde `plugins/infouno-custom/`):**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage [--filter <TestName>]
```

---

## Contexto crítico para el implementador

1. **Base fail-closed** (`src/Persistence/TenantScopedRepository.php`): `protected \wpdb $db` (de `global $wpdb` en el ctor sin args), `abstract table(): string`, `final guardScope(int): int` (lanza `Infouno\SaaS\Persistence\MissingTenantScopeException` si `<= 0`). Referencias ya migradas con este patrón EXACTO: `src/Opportunity/OpportunityRepository.php` (inc 4 — el más parecido a esta tarea), `src/Lead/LeadRepository.php`.

2. **`BotManager`** (`src/Bot/BotManager.php`), `final`, NO extiende nada, usa `global $wpdb`. Métodos públicos: `create(tenantId,data)`, `countForTenant(tenantId)`, `getById(botId,tenantId)`, `getAllForTenant(tenantId)`, `update(botId,tenantId,data)`, `delete(botId,tenantId)`, `getByPublicToken(token)`, `validateOrigin(bot,origin)`. Privados: `decodeSettings`, `generatePublicToken`. Consts `PLAN_BOT_LIMITS`, `DEFAULT_SETTINGS`. Tabla principal: `infouno_bots`. Se instancia `new BotManager()` (sin args) en `Plugin.php:83`.

3. **El guard estático YA EXCLUYE `Bot/BotManager.php` del escaneo** (está en la lista de "excluidos siempre"). Por eso mover SQL a BotManager lo saca del radar del guard. Lo que el guard SÍ escanea y debe quedar SQL-free son los 3 archivos: `API/BotController.php`, `Admin/BotDashboard.php`, `Admin/BotWizard.php`.

4. **Stub** (`tests/bootstrap.php`, `WpdbStub`): `get_row/get_var/get_results` devuelven `stub_get_row/stub_get_var/stub_get_results` y setean `last_query`; `insert` invoca `onInsert($table,$data)` y setea `insert_id`; `update` setea `last_update_data`/`last_update_where`; `delete` existe; `prepare` sustituye `%s`→`'val'`, `%d`→`val`. `bypassFinals` activo.

5. **DI ya resuelta:** `BotController`, `BotWizard`, `BotDashboard` ya reciben `BotManager` en su constructor (`$this->botManager`). No hay que tocar wiring en Plugin.php.

6. **Guardrail:** comportamiento observable preservado. `getByPublicToken` NO lleva guardScope (resuelve el tenant desde el token — es la entrada pública del widget).

---

## File Structure

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `src/Bot/BotManager.php` | **Modificar** | Extender `TenantScopedRepository`; `global $wpdb`→`$this->db`; `guardScope()` en métodos tenant-scoped; `table()`. + 2 métodos nuevos. |
| `tests/Unit/Bot/BotManagerTest.php` | **Crear** | Fail-closed por método tenant-scoped + SQL-shape + los 2 métodos nuevos. |
| `src/API/BotController.php` | **Modificar** | `wizard()` delega a `saveWizardResult`; quitar `global $wpdb` + el update. |
| `tests/Unit/API/BotControllerTest.php` | **Crear** | Caracterización de `wizard()`: validación 422 / save=true delega / save=false no toca BD. |
| `src/Admin/BotWizard.php` | **Modificar** | El handler delega a `saveWizardResult`; quitar `global $wpdb` + el update. |
| `src/Admin/BotDashboard.php` | **Modificar** | Contadores de leads delegan a `leadCountsForBots`; quitar `global $wpdb` + la query. |
| `tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php` | **Modificar** | Vaciar la ALLOWLIST (3→0); adaptar el self-test del caso allowlist-vacía + assert "guard total". |

---

## Task 1: `BotManager` extiende la base fail-closed

**Files:**
- Modify: `plugins/infouno-custom/src/Bot/BotManager.php`
- Test: `plugins/infouno-custom/tests/Unit/Bot/BotManagerTest.php`

Hacer que extienda `TenantScopedRepository`, añadir `table()`, cambiar `global $wpdb`→`$this->db` en los métodos con SQL, y añadir `guardScope()` a los tenant-scoped. `getByPublicToken` NO lleva guardScope. `validateOrigin`, `decodeSettings`, `generatePublicToken` no tienen SQL → sin cambios (salvo que usen `$wpdb`, que no es el caso).

- [ ] **Step 1: Escribir los tests que fallan** — crear `tests/Unit/Bot/BotManagerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Bot;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use PHPUnit\Framework\TestCase;

final class BotManagerTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->prefix          = 'wp_';
        $GLOBALS['wpdb']->stub_get_var    = null;
        $GLOBALS['wpdb']->stub_get_row    = null;
        $GLOBALS['wpdb']->stub_get_results = [];
        $GLOBALS['wpdb']->last_query      = '';
        $GLOBALS['wpdb']->last_update_data  = [];
        $GLOBALS['wpdb']->last_update_where = [];
        $GLOBALS['wpdb']->last_delete_where = [];
        $GLOBALS['wpdb']->onInsert        = null;
        $GLOBALS['wpdb']->insert_id       = 0;
    }

    // ── fail-closed en métodos tenant-scoped ──────────────────────────────

    public function test_create_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->create( 0, [ 'bot_name' => 'X' ] );
    }

    public function test_countForTenant_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->countForTenant( 0 );
    }

    public function test_getById_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->getById( 1, 0 );
    }

    public function test_getAllForTenant_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->getAllForTenant( 0 );
    }

    public function test_update_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->update( 1, 0, [ 'bot_name' => 'X' ] );
    }

    public function test_delete_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->delete( 1, 0 );
    }

    // ── getByPublicToken NO lleva guardScope (entrada pública del widget) ──

    public function test_getByPublicToken_does_not_require_tenant_scope(): void {
        $GLOBALS['wpdb']->stub_get_row = null; // token no encontrado
        // No debe lanzar MissingTenantScopeException — resuelve el tenant desde el token.
        $this->assertNull( ( new BotManager() )->getByPublicToken( 'abc' ) );
        $this->assertStringContainsString( "public_token = 'abc'", $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( 'is_active = 1', $GLOBALS['wpdb']->last_query );
    }

    // ── SQL-shape: tenant_id presente ─────────────────────────────────────

    public function test_getById_query_includes_tenant_filter(): void {
        $GLOBALS['wpdb']->stub_get_row = null;
        ( new BotManager() )->getById( 5, 3 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'infouno_bots', $q );
        $this->assertStringContainsString( 'id = 5', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
    }

    public function test_countForTenant_query_includes_tenant_filter(): void {
        $GLOBALS['wpdb']->stub_get_var = '2';
        $this->assertSame( 2, ( new BotManager() )->countForTenant( 3 ) );
        $this->assertStringContainsString( 'tenant_id = 3', $GLOBALS['wpdb']->last_query );
    }

    public function test_update_where_includes_tenant(): void {
        ( new BotManager() )->update( 9, 3, [ 'bot_name' => 'Nuevo' ] );
        $this->assertSame( [ 'id' => 9, 'tenant_id' => 3 ], $GLOBALS['wpdb']->last_update_where );
    }

    public function test_delete_where_includes_tenant(): void {
        ( new BotManager() )->delete( 9, 3 );
        $this->assertSame( [ 'id' => 9, 'tenant_id' => 3 ], $GLOBALS['wpdb']->last_delete_where );
    }
}
```

> Nota: el `WpdbStub` ya tiene `delete()` (agregado en bootstrap.php, setea `last_delete_where`) y `last_delete_where` se resetea en el `setUp` de este test (agregar `$GLOBALS['wpdb']->last_delete_where = [];` al `setUp` junto a los otros resets).

- [ ] **Step 2: Correr, confirmar FAIL** (los `*_fails_closed_*` no lanzan todavía):
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BotManagerTest
```

- [ ] **Step 3: Refactorizar `src/Bot/BotManager.php`**

3a. Header — import + extends:
```php
namespace Infouno\SaaS\Bot;

use Infouno\SaaS\Persistence\TenantScopedRepository;

/**
 * CRUD de bots con scope estricto por tenant_id (extiende TenantScopedRepository).
 * Todo método tenant-scoped llama guardScope() y filtra por tenant_id.
 * Excepción: getByPublicToken() resuelve el tenant desde el token público (widget) — sin guardScope.
 */
final class BotManager extends TenantScopedRepository {
```

3b. Añadir `table()` después de las constantes (antes de `create`):
```php
    protected function table(): string {
        return $this->db->prefix . 'infouno_bots';
    }
```

3c. En cada método con SQL: eliminar `global $wpdb;`, reemplazar `$wpdb`→`$this->db`, reemplazar `$wpdb->prefix . 'infouno_bots'` por `$this->table()`, y añadir `guardScope()`:

| Método | guardScope |
|---|---|
| `create( int $tenantId, array $data )` | `$this->guardScope( $tenantId );` (primera línea) |
| `countForTenant( int $tenantId )` | `$this->guardScope( $tenantId );` |
| `getById( int $botId, int $tenantId )` | `$this->guardScope( $tenantId );` |
| `getAllForTenant( int $tenantId )` | `$this->guardScope( $tenantId );` |
| `update( int $botId, int $tenantId, array $data )` | `$this->guardScope( $tenantId );` |
| `delete( int $botId, int $tenantId )` | `$this->guardScope( $tenantId );` |
| `getByPublicToken( string $token )` | **SIN guardScope** — solo `global $wpdb;`→`$this->db` y `$this->table()`. |

`validateOrigin`, `decodeSettings`, `generatePublicToken`: sin cambios (no usan `$wpdb`).

Ejemplo — `getById` queda:
```php
    public function getById( int $botId, int $tenantId ): ?array {
        $this->guardScope( $tenantId );

        $table = $this->table();
        $row   = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM `{$table}` WHERE id = %d AND tenant_id = %d LIMIT 1",
                $botId,
                $tenantId
            ),
            ARRAY_A
        );

        return $row ? $this->decodeSettings( $row ) : null;
    }
```
Y `getByPublicToken` (sin guardScope):
```php
    public function getByPublicToken( string $token ): ?array {
        $table = $this->table();
        $row   = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM `{$table}` WHERE public_token = %s AND is_active = 1 LIMIT 1",
                $token
            ),
            ARRAY_A
        );

        return $row ? $this->decodeSettings( $row ) : null;
    }
```

No cambiar la lógica interna (merge de settings en update, decodeSettings, etc.).

Verificar:
```bash
grep -n 'global \$wpdb' plugins/infouno-custom/src/Bot/BotManager.php   # vacío
grep -nE '[^>]\$wpdb' plugins/infouno-custom/src/Bot/BotManager.php       # vacío
```

- [ ] **Step 4: Correr, confirmar PASS + suite completa**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BotManagerTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: BotManagerTest PASS; suite completa sin regresiones (BotManager es muy usado — confirmar verde).

- [ ] **Step 5: Commit**
```bash
cd /Users/Rober/Desktop/Proyectos/infouno-chatbot/infouno-chatbot
git add plugins/infouno-custom/src/Bot/BotManager.php plugins/infouno-custom/tests/Unit/Bot/BotManagerTest.php
git commit -m "refactor(bots): BotManager extiende TenantScopedRepository (fail-closed)"
```

---

## Task 2: Métodos nuevos — saveWizardResult + leadCountsForBots

**Files:**
- Modify: `plugins/infouno-custom/src/Bot/BotManager.php`
- Test: `plugins/infouno-custom/tests/Unit/Bot/BotManagerTest.php`

- [ ] **Step 1: Agregar tests** (antes del `}` de cierre de `BotManagerTest`):

```php
    // ── saveWizardResult ──────────────────────────────────────────────────

    public function test_saveWizardResult_updates_prompt_and_wizard_data_scoped_by_tenant(): void {
        ( new BotManager() )->saveWizardResult( 9, 3, 'PROMPT', [ 'industry' => 'retail' ] );
        $this->assertSame( 'PROMPT', $GLOBALS['wpdb']->last_update_data['system_prompt'] );
        $this->assertArrayHasKey( 'wizard_data', $GLOBALS['wpdb']->last_update_data );
        $this->assertSame( [ 'id' => 9, 'tenant_id' => 3 ], $GLOBALS['wpdb']->last_update_where );
    }

    public function test_saveWizardResult_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->saveWizardResult( 9, 0, 'PROMPT', [] );
    }

    // ── leadCountsForBots ─────────────────────────────────────────────────

    public function test_leadCountsForBots_groups_by_bot_scoped_by_tenant(): void {
        $GLOBALS['wpdb']->stub_get_results = [
            [ 'bot_id' => '5', 'total' => '12' ],
            [ 'bot_id' => '7', 'total' => '3' ],
        ];
        $counts = ( new BotManager() )->leadCountsForBots( [ 5, 7 ], 3 );
        $this->assertSame( [ 5 => 12, 7 => 3 ], $counts );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'infouno_leads', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
        $this->assertStringContainsString( 'GROUP BY bot_id', $q );
    }

    public function test_leadCountsForBots_returns_empty_for_no_bots(): void {
        $this->assertSame( [], ( new BotManager() )->leadCountsForBots( [], 3 ) );
    }

    public function test_leadCountsForBots_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->leadCountsForBots( [ 5 ], 0 );
    }
```

- [ ] **Step 2: Correr, confirmar FAIL** (undefined method):
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BotManagerTest
```

- [ ] **Step 3: Agregar los 2 métodos** (antes del `}` de cierre de la clase, después de `validateOrigin`):

```php
    /**
     * Guarda el resultado del wizard (system_prompt + wizard_data) de un bot.
     * Absorbe el UPDATE duplicado en BotController::wizard y BotWizard. Scope key: tenant_id.
     *
     * @param array<string,mixed> $wizardData
     * @throws \Infouno\SaaS\Persistence\MissingTenantScopeException
     */
    public function saveWizardResult( int $botId, int $tenantId, string $systemPrompt, array $wizardData ): bool {
        $this->guardScope( $tenantId );

        $updated = $this->db->update(
            $this->table(),
            [
                'system_prompt' => $systemPrompt,
                'wizard_data'   => wp_json_encode( $wizardData ),
            ],
            [ 'id' => $botId, 'tenant_id' => $tenantId ],
            [ '%s', '%s' ],
            [ '%d', '%d' ]
        );

        return $updated !== false;
    }

    /**
     * Cuenta leads agrupados por bot para un conjunto de bots del tenant (una sola query).
     * Absorbe el GROUP BY de BotDashboard. Devuelve [bot_id => total]. Scope key: tenant_id.
     *
     * @param array<int> $botIds
     * @return array<int,int>
     * @throws \Infouno\SaaS\Persistence\MissingTenantScopeException
     */
    public function leadCountsForBots( array $botIds, int $tenantId ): array {
        $this->guardScope( $tenantId );

        $botIds = array_values( array_map( 'intval', $botIds ) );
        if ( ! $botIds ) {
            return [];
        }

        $leadsTable   = $this->db->prefix . 'infouno_leads';
        $placeholders = implode( ',', array_fill( 0, count( $botIds ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT bot_id, COUNT(*) AS total
                 FROM `{$leadsTable}`
                 WHERE bot_id IN ({$placeholders}) AND tenant_id = %d
                 GROUP BY bot_id",
                ...array_merge( $botIds, [ $tenantId ] )
            ),
            ARRAY_A
        );

        $counts = [];
        foreach ( $rows ?: [] as $row ) {
            $counts[ (int) $row['bot_id'] ] = (int) $row['total'];
        }

        return $counts;
    }
```

- [ ] **Step 4: Correr, confirmar PASS**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BotManagerTest
```

- [ ] **Step 5: Commit**
```bash
git add plugins/infouno-custom/src/Bot/BotManager.php plugins/infouno-custom/tests/Unit/Bot/BotManagerTest.php
git commit -m "feat(bots): saveWizardResult + leadCountsForBots en BotManager"
```

---

## Task 3: Migrar `BotController::wizard()` al manager

**Files:**
- Modify: `plugins/infouno-custom/src/API/BotController.php`
- Test: `plugins/infouno-custom/tests/Unit/API/BotControllerTest.php`

- [ ] **Step 1: Crear tests de caracterización** `tests/Unit/API/BotControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\API;

use Infouno\SaaS\API\BotController;
use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class BotControllerTest extends TestCase {

    private function botManagerReturningBot(): BotManager {
        $bm = $this->createMock( BotManager::class );
        $bm->method( 'getById' )->willReturn( [ 'id' => 9, 'tenant_id' => 3, 'bot_name' => 'X' ] );
        return $bm;
    }

    private function tenantManager(): TenantManager {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'getForCurrentUser' )->willReturn( [ 'id' => 3, 'status' => 'active' ] );
        return $tm;
    }

    private function request( array $params ): \WP_REST_Request {
        $req = new \WP_REST_Request();
        $req['id'] = 9;
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return $req;
    }

    /** wizard_data válido mínimo (ajustar si PromptBuilder::validate exige más campos). */
    private function validWizardData(): array {
        return [
            'business_name' => 'Acme',
            'industry'      => 'retail',
            'tone'          => 'profesional',
            'main_goal'     => 'vender',
        ];
    }

    public function test_wizard_with_save_true_persists_via_manager(): void {
        $bm = $this->botManagerReturningBot();
        $bm->expects( $this->once() )->method( 'saveWizardResult' )
            ->with( 9, 3, $this->isType( 'string' ), $this->isType( 'array' ) )
            ->willReturn( true );

        $ctrl = new BotController( $bm, $this->tenantManager() );
        $resp = $ctrl->wizard( $this->request( [ 'wizard_data' => $this->validWizardData(), 'save' => true ] ) );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertTrue( $resp->get_data()['saved'] );
    }

    public function test_wizard_with_save_false_does_not_persist(): void {
        $bm = $this->botManagerReturningBot();
        $bm->expects( $this->never() )->method( 'saveWizardResult' );

        $ctrl = new BotController( $bm, $this->tenantManager() );
        $resp = $ctrl->wizard( $this->request( [ 'wizard_data' => $this->validWizardData(), 'save' => false ] ) );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertFalse( $resp->get_data()['saved'] );
        $this->assertArrayHasKey( 'system_prompt', $resp->get_data() );
    }
}
```

> **Importante para el implementador:** Antes de correr, leé `src/Bot/PromptBuilder.php` (`validate()` y `fromWizardData()`) para confirmar qué campos exige `wizard_data`. Ajustá `validWizardData()` para que `PromptBuilder::validate()` devuelva `[]` (sin errores), si no los tests darían 422 en vez de 200. Esto es legítimo: el test necesita datos válidos.

- [ ] **Step 2: Correr, confirmar FAIL** (el método `wizard()` aún usa `global $wpdb`, el mock `saveWizardResult` no se invoca / o falla por datos):
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BotControllerTest
```

- [ ] **Step 3: Migrar `wizard()` en `src/API/BotController.php`**

Reemplazar el bloque `if ( $request->get_param( 'save' ) ) { ... }` (con `global $wpdb;` + `$wpdb->update(...)`) por:

```php
        if ( $request->get_param( 'save' ) ) {
            $tenant = $this->tenantManager->getForCurrentUser();
            $this->botManager->saveWizardResult(
                (int) $bot['id'],
                (int) $tenant['id'],
                $generatedPrompt,
                $wizardData,
            );
        }
```

Eliminar la línea `global $wpdb;` de ese bloque. No tocar el resto del método (validación, PromptBuilder, respuesta).

Verificar:
```bash
grep -n '\$wpdb' plugins/infouno-custom/src/API/BotController.php   # vacío
```

- [ ] **Step 4: Correr test + suite completa**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter BotControllerTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: BotControllerTest PASS; suite verde (el guard sigue pasando — BotController aún allowlisted ahora).

- [ ] **Step 5: Commit**
```bash
git add plugins/infouno-custom/src/API/BotController.php plugins/infouno-custom/tests/Unit/API/BotControllerTest.php
git commit -m "refactor(bots): BotController::wizard delega a saveWizardResult (SQL-free)"
```

---

## Task 4: Migrar `BotWizard` y `BotDashboard` al manager

**Files:**
- Modify: `plugins/infouno-custom/src/Admin/BotWizard.php`
- Modify: `plugins/infouno-custom/src/Admin/BotDashboard.php`

Ambos son admin handlers (sin tests unitarios). La verificación es: SQL-free + suite verde + guard de Task 5.

- [ ] **Step 1: Migrar `BotWizard`**

En `src/Admin/BotWizard.php`, reemplazar el bloque `global $wpdb;` + `$wpdb->update( $wpdb->prefix . 'infouno_bots', [ 'system_prompt' => ..., 'wizard_data' => ... ], [ 'id' => $botId, 'tenant_id' => ... ], ... );` por:

```php
        $this->botManager->saveWizardResult(
            $botId,
            (int) $tenant['id'],
            $generatedPrompt,
            $data,
        );
```

(Usar las variables ya presentes: `$botId`, `$tenant`, `$generatedPrompt`, `$data`.) Eliminar la línea `global $wpdb;`.

- [ ] **Step 2: Migrar `BotDashboard`**

En `src/Admin/BotDashboard.php`, reemplazar el bloque de contadores de leads (`global $wpdb;` + el `$placeholders`/`$wpdb->get_results(...)` con el GROUP BY + el `foreach` que arma `$leadCounts`) por:

```php
        // Contadores de leads por bot (una sola query, vía manager).
        $botIds     = array_map( static fn( $b ) => (int) $b['id'], $bots );
        $leadCounts = $this->botManager->leadCountsForBots( $botIds, $tenantId );
```

Eliminar la línea `global $wpdb;` y todo el bloque SQL que reemplaza. El resto del render (`foreach ( $bots as $bot )` usando `$leadCounts[$botId] ?? 0`) queda igual.

- [ ] **Step 3: Verificar SQL-free + suite**
```bash
grep -n '\$wpdb' plugins/infouno-custom/src/Admin/BotWizard.php plugins/infouno-custom/src/Admin/BotDashboard.php   # vacío
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: vacío el grep; suite verde.

- [ ] **Step 4: Commit**
```bash
git add plugins/infouno-custom/src/Admin/BotWizard.php plugins/infouno-custom/src/Admin/BotDashboard.php
git commit -m "refactor(bots): BotWizard + BotDashboard delegan al manager (SQL-free)"
```

---

## Task 5: Vaciar la ALLOWLIST — guard total (cierre Bloque D)

**Files:**
- Modify: `plugins/infouno-custom/tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php`

- [ ] **Step 1: Vaciar la ALLOWLIST**

Dejar la constante `ALLOWLIST` vacía (comentando las 3 entradas de Bots con su marca de incremento):
```php
    private const ALLOWLIST = [
        // ── Bloque D completo: allowlist vacía → guard total ──
        // 'API/ConsentController.php',     ← migrado en Increment 3
        // 'API/LeadController.php',         ← migrado en Increment 2
        // 'API/OpportunityController.php',  ← migrado en Increment 4
        // 'API/BotController.php',          ← migrado en Increment 5
        // 'Admin/LeadDashboard.php',        ← migrado en Increment 2
        // 'Admin/OpportunityDashboard.php', ← migrado en Increment 4
        // 'Admin/BotDashboard.php',         ← migrado en Increment 5
        // 'Admin/BotWizard.php',            ← migrado en Increment 5
        // 'Lead/LeadService.php',           ← migrado en Increment 2
        // 'Channel/ChannelConsentService.php', ← migrado en Increment 3
    ];
```

- [ ] **Step 2: Adaptar el self-test del caso allowlist-vacía**

El test `test_scanner_allows_allowlisted_files` usaba `'API/BotController.php'` (allowlisted). Con la allowlist vacía ya no hay archivo allowlisted real, y su lógica (`$wouldFail = $hasToken && ! $allowListed`) daría `true` → el `assertFalse` fallaría. Reemplazar ese test por uno que valide la MISMA lógica de exclusión con una allowlist **sintética** local (independiente del const real), y agregar un test que documente el cierre del Bloque D:

```php
    /**
     * Self-test de la lógica de exclusión: un archivo presente en una allowlist
     * NO se marca como violación. Usa una allowlist sintética porque la real ya
     * está vacía (Bloque D completo) — la lógica del guard sigue siendo válida.
     */
    public function test_scanner_exclusion_logic_skips_allowlisted_files(): void {
        $syntheticAllowlist = [ 'API/LegacyController.php' ];
        $fakeContent = '<?php $wpdb->get_results("SELECT 1");';
        $fakeRel     = 'API/LegacyController.php';

        $hasToken    = str_contains( $fakeContent, self::SQL_TOKEN );
        $allowListed = in_array( $fakeRel, $syntheticAllowlist, true );

        $wouldFail = $hasToken && ! $allowListed;
        $this->assertFalse( $wouldFail, 'Un archivo en la allowlist no debe marcarse como violación.' );
    }

    /**
     * Hito Bloque D: la ALLOWLIST quedó vacía → guard total, ningún archivo
     * de API/Admin/servicios puede tener SQL crudo. Si alguien re-agrega una
     * entrada, este test lo detecta.
     */
    public function test_allowlist_is_empty_guard_is_total(): void {
        $this->assertSame( [], self::ALLOWLIST, 'Bloque D completo: la allowlist debe estar vacía.' );
    }
```

(Eliminar el viejo `test_scanner_allows_allowlisted_files`.) El test `test_scanner_detects_unlisted_sql_usage` queda igual — sigue válido con allowlist vacía.

- [ ] **Step 3: Correr el guard + suite completa**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter NoRawSqlOutsidePersistenceTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: guard PASS (escanea los 3 archivos de Bots y los encuentra SQL-free; allowlist vacía); suite completa verde. Anotar el total.

- [ ] **Step 4: Commit**
```bash
git add plugins/infouno-custom/tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php
git commit -m "test(bots): ALLOWLIST vacía — guard total, cierre del Bloque D (3→0)"
```

---

## Self-Review: Cobertura vs objetivo (Bloque D, Increment 5 — cierre)

| Requisito | Task(s) | Estado |
|---|---|---|
| `BotManager` extiende `TenantScopedRepository` con `table()` | Task 1 | ✅ |
| Métodos tenant-scoped llaman `guardScope(tenant_id)`; `getByPublicToken` NO | Task 1 | ✅ (tests fail-closed + test de getByPublicToken sin guard) |
| Mover el UPDATE de `BotController::wizard` al manager | Tasks 2-3 | ✅ (`saveWizardResult`) |
| Mover el UPDATE de `BotWizard` al manager (DRY con el anterior) | Tasks 2, 4 | ✅ |
| Mover el GROUP BY de leads de `BotDashboard` al manager | Tasks 2, 4 | ✅ (`leadCountsForBots`) |
| Los 3 archivos quedan SQL-free | Tasks 3-4 | ✅ |
| ALLOWLIST vacía → guard total | Task 5 | ✅ (3→0 + test que lo bloquea a futuro) |
| Self-test adaptado al caso allowlist-vacía | Task 5 | ✅ |
| Sin cambios de esquema de BD | — ninguna task toca Migrator | ✅ |
| Wiring intacto (los 3 archivos ya tenían BotManager inyectado) | — | ✅ |

**Notas de riesgo:**
- `BotManager` es muy usado (cada request de widget llama `getByPublicToken`; admin/API llaman `getById`). El refactor `global $wpdb`→`$this->db` es behavior-preserving; `guardScope` es backstop (entry points ya garantizan tenant>0). `getByPublicToken` queda explícitamente sin guardScope (entrada pública).
- `saveWizardResult` DRYea un UPDATE que estaba duplicado byte-a-byte en 2 archivos.
- Con la allowlist vacía, este incremento **cierra el Bloque D**: ningún `$wpdb->` fuera de la capa de persistencia (repos + managers autorizados). El guard lo garantiza en CI desde ahora.
