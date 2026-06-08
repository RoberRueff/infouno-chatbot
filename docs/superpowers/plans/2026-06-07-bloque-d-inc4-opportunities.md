# Bloque D Incremento 4 — Dominio Opportunities — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mover el SQL crudo de `API/OpportunityController.php` (3 lecturas a `infouno_leads`) y `Admin/OpportunityDashboard.php` (1 JOIN) al `Opportunity\OpportunityRepository` existente, hacer que ese repo extienda la base fail-closed (`TenantScopedRepository`), y sacar ambos archivos de la ALLOWLIST del guard (5→3).

**Architecture:** A diferencia del incremento 3 (Consents, repo nuevo), acá el `OpportunityRepository` **ya existe** con 9 métodos que usan `global $wpdb` y NO extiende la base. Este incremento: (1) lo hace extender `TenantScopedRepository`, cambia `global $wpdb`→`$this->db` y añade `guardScope()` a cada método; (2) le agrega 2 métodos nuevos que absorben el SQL del controller y del dashboard; (3) migra ambos a delegar. Los entry points ya garantizan tenant>0 (el controller via `permission_callback`/`requireActiveTenant`; el dashboard hace `wp_die(403)` si no hay tenant), así que `guardScope()` es un backstop defensivo, no cambia el comportamiento observable.

**Tech Stack:** PHP 8.1+, PSR-4 `Infouno\SaaS\`, PHPUnit en Docker `php:8.3-cli` (NO hay PHP local), WpdbStub de `tests/bootstrap.php`.

**Comando de tests (desde `plugins/infouno-custom/`):**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage [--filter <TestName>]
```

---

## Contexto crítico para el implementador

1. **El patrón fail-closed ya existe.** `src/Persistence/TenantScopedRepository.php` (base) provee `protected \wpdb $db` (de `global $wpdb` en su constructor sin args), `abstract protected function table(): string`, y `final protected function guardScope( int $scopeId ): int` (lanza `Infouno\SaaS\Persistence\MissingTenantScopeException` si `<= 0`, si no devuelve el id). Referencias ya migradas: `src/Lead/LeadRepository.php`, `src/Persistence/ConsentRepository.php`.

2. **`OpportunityRepository` ya existe** (`src/Opportunity/OpportunityRepository.php`), `final`, con const `STAGES` y `TERMINAL_STAGES` y 9 métodos públicos que usan `global $wpdb`: `create`, `getActiveByLead`, `getById`, `listForTenant`, `countForTenant`, `getPipelineMetrics`, `updateStage`, `updateValue`, `logAutomation`. Maneja 2 tablas: `infouno_opportunities` (principal) e `infouno_automation_logs` (solo `logAutomation`). Se instancia como `new OpportunityRepository()` (sin args) en `Plugin.php:94` — compatible con el ctor de la base.

3. **No hay tests del dominio Opportunity todavía.** Cero red de seguridad previa, pero tampoco hay tests que romper. Este plan agrega `OpportunityRepositoryTest` y `OpportunityControllerTest`.

4. **El stub de BD** (`tests/bootstrap.php`, `WpdbStub`):
   - `get_row()`/`get_var()`/`get_results()` devuelven `stub_get_row`/`stub_get_var`/`stub_get_results` y setean `$wpdb->last_query`.
   - `insert($table,$data,$formats)` invoca `$wpdb->onInsert($table,$data)` si está seteado; setea `insert_id`.
   - `update($table,$data,$where,...)` setea `$wpdb->last_update_data` y `$wpdb->last_update_where` (NO setea `last_query`). Para asserts sobre el WHERE de un UPDATE, usar `last_update_where`.
   - `prepare()` sustituye `%s`→`'valor'`, `%d`→`valor`.
   - `bypassFinals` está activo → PHPUnit puede mockear clases `final`.

5. **Guardrail:** toda query filtra por `tenant_id`. La migración NO debe cambiar comportamiento observable de los endpoints. La única optimización permitida y explícita: consolidar las 3 lecturas del controller a `infouno_leads` en una sola query (mismas decisiones, misma data).

---

## File Structure

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `src/Opportunity/OpportunityRepository.php` | **Modificar** | Extender `TenantScopedRepository`; `global $wpdb`→`$this->db`; `guardScope()` en cada método; `table()`. + 2 métodos nuevos. |
| `tests/Unit/Opportunity/OpportunityRepositoryTest.php` | **Crear** | Fail-closed por método + SQL-shape de lecturas + los 2 métodos nuevos. |
| `src/API/OpportunityController.php` | **Modificar** | `store()` delega a `getLeadSnapshotForTenant`; quitar `global $wpdb` y las 3 queries. |
| `tests/Unit/API/OpportunityControllerTest.php` | **Crear** | Caracterización de `store()`: 404 / 422 / 200 (existente) / 201 (éxito). |
| `src/Admin/OpportunityDashboard.php` | **Modificar** | `getOpportunitiesWithLeadData` delega a `listWithLeadDataForTenant`; quitar `global $wpdb`. |
| `tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php` | **Modificar** | Sacar `OpportunityController` y `OpportunityDashboard` de la ALLOWLIST (5→3); arreglar el self-test que usa OpportunityController de ejemplo. |

---

## Task 1: `OpportunityRepository` extiende la base fail-closed

**Files:**
- Modify: `plugins/infouno-custom/src/Opportunity/OpportunityRepository.php`
- Test: `plugins/infouno-custom/tests/Unit/Opportunity/OpportunityRepositoryTest.php`

Hacer que el repo extienda `TenantScopedRepository`, añadir `table()`, cambiar `global $wpdb`→`$this->db` en los 9 métodos, y añadir `guardScope()` con la clave correcta al inicio de cada uno. Comportamiento preservado salvo el backstop fail-closed (scope ≤ 0 lanza).

- [ ] **Step 1: Escribir los tests que fallan**

Crear `tests/Unit/Opportunity/OpportunityRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Opportunity;

use Infouno\SaaS\Opportunity\OpportunityRepository;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use PHPUnit\Framework\TestCase;

final class OpportunityRepositoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->prefix          = 'wp_';
        $GLOBALS['wpdb']->stub_get_var    = null;
        $GLOBALS['wpdb']->stub_get_row    = null;
        $GLOBALS['wpdb']->stub_get_results = [];
        $GLOBALS['wpdb']->last_query      = '';
        $GLOBALS['wpdb']->last_update_where = [];
        $GLOBALS['wpdb']->onInsert        = null;
        $GLOBALS['wpdb']->insert_id       = 0;
    }

    // ── fail-closed: cada método lanza con scope <= 0 ─────────────────────

    public function test_create_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->create( [ 'tenant_id' => 0, 'lead_id' => 1, 'bot_id' => 1 ] );
    }

    public function test_getActiveByLead_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->getActiveByLead( 1, 0 );
    }

    public function test_getById_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->getById( 1, 0 );
    }

    public function test_listForTenant_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->listForTenant( 0 );
    }

    public function test_countForTenant_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->countForTenant( 0 );
    }

    public function test_getPipelineMetrics_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->getPipelineMetrics( 0 );
    }

    public function test_updateStage_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->updateStage( 1, 0, 'contacted' );
    }

    public function test_updateValue_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->updateValue( 1, 0, 100.0 );
    }

    public function test_logAutomation_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->logAutomation( 0, 'email' );
    }

    // ── SQL-shape: tenant_id presente en lecturas clave ───────────────────

    public function test_getById_query_includes_tenant_filter(): void {
        $GLOBALS['wpdb']->stub_get_row = [ 'id' => 5 ];
        $repo = new OpportunityRepository();
        $repo->getById( 5, 3 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'infouno_opportunities', $q );
        $this->assertStringContainsString( 'id = 5', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
    }

    public function test_getActiveByLead_excludes_terminal_stages(): void {
        $GLOBALS['wpdb']->stub_get_row = null;
        ( new OpportunityRepository() )->getActiveByLead( 7, 3 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'lead_id = 7', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
        $this->assertStringContainsString( "NOT IN ('won', 'lost')", $q );
    }

    public function test_countForTenant_with_stage_filters_both(): void {
        $GLOBALS['wpdb']->stub_get_var = '4';
        $count = ( new OpportunityRepository() )->countForTenant( 3, 'quoted' );
        $this->assertSame( 4, $count );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'tenant_id = 3', $q );
        $this->assertStringContainsString( "stage = 'quoted'", $q );
    }

    public function test_create_inserts_with_tenant_and_returns_id(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$captured ) {
            $captured = [ 'table' => $table, 'data' => $data ];
        };
        $GLOBALS['wpdb']->insert_id = 42;
        $id = ( new OpportunityRepository() )->create( [
            'tenant_id' => 3, 'lead_id' => 7, 'bot_id' => 5,
        ] );
        $this->assertSame( 42, $id );
        $this->assertSame( 'wp_infouno_opportunities', $captured['table'] );
        $this->assertSame( 3, $captured['data']['tenant_id'] );
        $this->assertSame( 'new', $captured['data']['stage'] );
    }

    public function test_updateValue_where_includes_tenant(): void {
        $GLOBALS['wpdb']->stub_query_result = 1;
        ( new OpportunityRepository() )->updateValue( 9, 3, 250.0, 'usd' );
        $this->assertSame( [ 'id' => 9, 'tenant_id' => 3 ], $GLOBALS['wpdb']->last_update_where );
    }
}
```

- [ ] **Step 2: Correr, confirmar FAIL** (los fail-closed fallan porque aún no hay guardScope; algunos pasarían por casualidad — el objetivo es ver rojo en los `*_fails_closed_*`):

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter OpportunityRepositoryTest
```
Esperado: varios FAIL — los `test_*_fails_closed_on_zero_tenant` no lanzan todavía.

- [ ] **Step 3: Refactorizar `OpportunityRepository`**

3a. **Header** — agregar import y extender la base. Cambiar:
```php
namespace Infouno\SaaS\Opportunity;

/**
 * Acceso a wp_infouno_opportunities.
 * ...
 */
final class OpportunityRepository {
```
por:
```php
namespace Infouno\SaaS\Opportunity;

use Infouno\SaaS\Persistence\TenantScopedRepository;

/**
 * Acceso a wp_infouno_opportunities (+ wp_infouno_automation_logs en logAutomation).
 *
 * Extiende TenantScopedRepository: toda operación exige un tenant_id positivo
 * (guardScope) y usa $this->db. Scope key: tenant_id en todos los métodos.
 */
final class OpportunityRepository extends TenantScopedRepository {
```

3b. **Añadir `table()`** justo después de las constantes `STAGES`/`TERMINAL_STAGES`:
```php
    protected function table(): string {
        return $this->db->prefix . 'infouno_opportunities';
    }
```

3c. **En CADA uno de los 9 métodos:** eliminar la línea `global $wpdb;`, reemplazar `$wpdb` por `$this->db`, reemplazar `$wpdb->prefix . 'infouno_opportunities'` por `$this->table()` (dejar `$this->db->prefix . 'infouno_automation_logs'` en `logAutomation`), y añadir el `guardScope()` correcto como PRIMERA línea del cuerpo:

| Método | Primera línea a añadir |
|---|---|
| `create( array $data )` | `$this->guardScope( (int) $data['tenant_id'] );` |
| `getActiveByLead( $leadId, $tenantId )` | `$this->guardScope( $tenantId );` |
| `getById( $id, $tenantId )` | `$this->guardScope( $tenantId );` |
| `listForTenant( $tenantId, ... )` | `$this->guardScope( $tenantId );` |
| `countForTenant( $tenantId, ... )` | `$this->guardScope( $tenantId );` |
| `getPipelineMetrics( $tenantId )` | `$this->guardScope( $tenantId );` |
| `updateStage( $id, $tenantId, ... )` | `$this->guardScope( $tenantId );` |
| `updateValue( $id, $tenantId, ... )` | `$this->guardScope( $tenantId );` |
| `logAutomation( $tenantId, ... )` | `$this->guardScope( $tenantId );` |

Ejemplo concreto — `getById` queda así:
```php
    public function getById( int $id, int $tenantId ): ?array {
        $this->guardScope( $tenantId );

        $table = $this->table();

        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM `{$table}` WHERE id = %d AND tenant_id = %d LIMIT 1",
                $id,
                $tenantId
            ),
            ARRAY_A
        );

        return $row ?: null;
    }
```
Y `create` (nota: guardScope sobre el tenant_id del array; `$table` pasa a `$this->table()`):
```php
    public function create( array $data ): int {
        $this->guardScope( (int) $data['tenant_id'] );

        $table = $this->table();

        $this->db->insert(
            $table,
            [
                'tenant_id'       => (int) $data['tenant_id'],
                // ...resto idéntico...
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $this->db->insert_id;
    }
```
Y `logAutomation` (la tabla NO es la principal — mantener prefijo inline):
```php
    public function logAutomation( ... ): int {
        $this->guardScope( $tenantId );

        $table = $this->db->prefix . 'infouno_automation_logs';
        // ...resto idéntico con $this->db...
    }
```

**No cambiar** la lógica interna (FIELD sort, terminal-stage guard de updateStage, loop de getPipelineMetrics, etc.). Solo `global $wpdb`→`$this->db`, `table()`, y `guardScope()`.

Verificar que no queda `global $wpdb` ni `$wpdb` suelto:
```bash
grep -n 'global \$wpdb\|[^>]\$wpdb' plugins/infouno-custom/src/Opportunity/OpportunityRepository.php   # sin resultados
```

- [ ] **Step 4: Correr, confirmar PASS**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter OpportunityRepositoryTest
```
Esperado: PASS (15 tests).

- [ ] **Step 5: Commit**
```bash
cd /Users/Rober/Desktop/Proyectos/infouno-chatbot/infouno-chatbot
git add plugins/infouno-custom/src/Opportunity/OpportunityRepository.php plugins/infouno-custom/tests/Unit/Opportunity/OpportunityRepositoryTest.php
git commit -m "refactor(opportunities): OpportunityRepository extiende TenantScopedRepository (fail-closed)"
```

---

## Task 2: Métodos nuevos — lead snapshot + listado con JOIN

**Files:**
- Modify: `plugins/infouno-custom/src/Opportunity/OpportunityRepository.php`
- Test: `plugins/infouno-custom/tests/Unit/Opportunity/OpportunityRepositoryTest.php`

Agregar `getLeadSnapshotForTenant` (absorbe las 3 lecturas a `infouno_leads` del controller, consolidadas en 1) y `listWithLeadDataForTenant` (absorbe el JOIN del dashboard).

- [ ] **Step 1: Agregar tests** (antes del `}` de cierre de `OpportunityRepositoryTest`)

```php
    // ── getLeadSnapshotForTenant ──────────────────────────────────────────

    public function test_getLeadSnapshot_returns_score_and_bot_scoped_by_tenant(): void {
        $GLOBALS['wpdb']->stub_get_row = [ 'score' => '85', 'bot_id' => '4' ];
        $snap = ( new OpportunityRepository() )->getLeadSnapshotForTenant( 7, 3 );
        $this->assertSame( 85, $snap['score'] );
        $this->assertSame( 4, $snap['bot_id'] );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'infouno_leads', $q );
        $this->assertStringContainsString( 'id = 7', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
    }

    public function test_getLeadSnapshot_returns_null_when_absent(): void {
        $GLOBALS['wpdb']->stub_get_row = null;
        $this->assertNull( ( new OpportunityRepository() )->getLeadSnapshotForTenant( 7, 3 ) );
    }

    public function test_getLeadSnapshot_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->getLeadSnapshotForTenant( 7, 0 );
    }

    // ── listWithLeadDataForTenant ─────────────────────────────────────────

    public function test_listWithLeadData_joins_leads_and_bots_scoped_by_tenant(): void {
        $GLOBALS['wpdb']->stub_get_results = [ [ 'id' => 1 ] ];
        $rows = ( new OpportunityRepository() )->listWithLeadDataForTenant( 3, null );
        $this->assertSame( [ [ 'id' => 1 ] ], $rows );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'infouno_opportunities', $q );
        $this->assertStringContainsString( 'infouno_leads', $q );
        $this->assertStringContainsString( 'infouno_bots', $q );
        $this->assertStringContainsString( 'o.tenant_id = 3', $q );
        $this->assertStringContainsString( 'lead_name', $q );
    }

    public function test_listWithLeadData_applies_stage_filter(): void {
        $GLOBALS['wpdb']->stub_get_results = [];
        ( new OpportunityRepository() )->listWithLeadDataForTenant( 3, 'quoted' );
        $this->assertStringContainsString( "o.stage = 'quoted'", $GLOBALS['wpdb']->last_query );
    }

    public function test_listWithLeadData_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->listWithLeadDataForTenant( 0, null );
    }
```

- [ ] **Step 2: Correr, confirmar FAIL** (undefined method):
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter OpportunityRepositoryTest
```

- [ ] **Step 3: Agregar los 2 métodos** (antes del `}` de cierre de la clase `OpportunityRepository`):

```php
    /**
     * Lee el lead de origen (score + bot_id) para sembrar una oportunidad.
     * Consolida en una query las 3 lecturas que el controller hacía por separado
     * (existencia, score, bot_id). Devuelve null si el lead no existe en el tenant.
     * Scope key: tenant_id.
     *
     * @return array{score:int, bot_id:int}|null
     * @throws \Infouno\SaaS\Persistence\MissingTenantScopeException
     */
    public function getLeadSnapshotForTenant( int $leadId, int $tenantId ): ?array {
        $this->guardScope( $tenantId );

        $leadsTable = $this->db->prefix . 'infouno_leads';

        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT score, bot_id FROM `{$leadsTable}` WHERE id = %d AND tenant_id = %d LIMIT 1",
                $leadId,
                $tenantId
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        return [ 'score' => (int) $row['score'], 'bot_id' => (int) $row['bot_id'] ];
    }

    /**
     * Lista oportunidades del tenant con datos del lead y bot en una sola query (JOIN).
     * Absorbe OpportunityDashboard::getOpportunitiesWithLeadData. Scope key: tenant_id.
     *
     * @return array<int, array<string, mixed>>
     * @throws \Infouno\SaaS\Persistence\MissingTenantScopeException
     */
    public function listWithLeadDataForTenant( int $tenantId, ?string $stage = null, int $limit = 100 ): array {
        $this->guardScope( $tenantId );

        $oppTable   = $this->table();
        $leadsTable = $this->db->prefix . 'infouno_leads';
        $botsTable  = $this->db->prefix . 'infouno_bots';

        $where = $this->db->prepare( 'WHERE o.tenant_id = %d', $tenantId );
        if ( $stage !== null && in_array( $stage, self::STAGES, true ) ) {
            $where .= $this->db->prepare( ' AND o.stage = %s', $stage );
        }

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT o.id, o.lead_id, o.bot_id, o.stage, o.estimated_value, o.currency,
                        o.lost_reason, o.stage_changed_at, o.won_at, o.lost_at, o.created_at,
                        l.name  AS lead_name,
                        l.email AS lead_email,
                        l.phone AS lead_phone,
                        b.bot_name
                 FROM `{$oppTable}` o
                 LEFT JOIN `{$leadsTable}` l ON l.id = o.lead_id AND l.tenant_id = o.tenant_id
                 LEFT JOIN `{$botsTable}`  b ON b.id = o.bot_id  AND b.tenant_id = o.tenant_id
                 {$where}
                 ORDER BY FIELD(o.stage,'new','contacted','interested','quoted','lost','won'),
                          o.created_at DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }
```

- [ ] **Step 4: Correr, confirmar PASS**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter OpportunityRepositoryTest
```
Esperado: PASS (21 tests).

- [ ] **Step 5: Commit**
```bash
git add plugins/infouno-custom/src/Opportunity/OpportunityRepository.php plugins/infouno-custom/tests/Unit/Opportunity/OpportunityRepositoryTest.php
git commit -m "feat(opportunities): getLeadSnapshotForTenant + listWithLeadDataForTenant en el repo"
```

---

## Task 3: Migrar `OpportunityController::store()` al repo

**Files:**
- Modify: `plugins/infouno-custom/src/API/OpportunityController.php`
- Test: `plugins/infouno-custom/tests/Unit/API/OpportunityControllerTest.php`

`store()` es el único método con `$wpdb`. Pasa a leer el snapshot del lead vía `getLeadSnapshotForTenant`. Comportamiento idéntico: 404 si el lead no existe, 422 si score < umbral, 200 si ya hay oportunidad activa, 201 al crear.

- [ ] **Step 1: Escribir tests de caracterización** — crear `tests/Unit/API/OpportunityControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\API;

use Infouno\SaaS\API\OpportunityController;
use Infouno\SaaS\Opportunity\OpportunityRepository;
use Infouno\SaaS\Opportunity\OpportunityService;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class OpportunityControllerTest extends TestCase {

    /** Fila completa de oportunidad (todas las claves que sanitizeOutput lee). */
    private function fullOpp( int $id = 99 ): array {
        return [
            'id' => $id, 'tenant_id' => 3, 'lead_id' => 7, 'bot_id' => 5,
            'stage' => 'new', 'estimated_value' => 100.0, 'currency' => 'ARS',
            'assigned_to' => null, 'notes' => null, 'lost_reason' => null,
            'stage_changed_at' => '2026-06-07 00:00:00', 'won_at' => null,
            'lost_at' => null, 'created_at' => '2026-06-07 00:00:00',
            'updated_at' => '2026-06-07 00:00:00',
        ];
    }

    private function tenantManager(): TenantManager {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'getForCurrentUser' )->willReturn( [ 'id' => 3, 'status' => 'active' ] );
        return $tm;
    }

    private function request( array $params ): \WP_REST_Request {
        $req = new \WP_REST_Request();
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return $req;
    }

    public function test_store_returns_404_when_lead_absent(): void {
        $repo = $this->createMock( OpportunityRepository::class );
        $repo->method( 'getLeadSnapshotForTenant' )->willReturn( null );
        $repo->expects( $this->never() )->method( 'create' );

        $ctrl = new OpportunityController( $this->createMock( OpportunityService::class ), $repo, $this->tenantManager() );
        $resp = $ctrl->store( $this->request( [ 'lead_id' => 7 ] ) );

        $this->assertInstanceOf( \WP_Error::class, $resp );
        $this->assertSame( 404, $resp->get_error_data()['status'] );
    }

    public function test_store_returns_422_when_score_below_threshold(): void {
        $repo = $this->createMock( OpportunityRepository::class );
        $repo->method( 'getLeadSnapshotForTenant' )->willReturn( [
            'score'  => OpportunityService::QUALIFIED_THRESHOLD - 1,
            'bot_id' => 5,
        ] );
        $repo->expects( $this->never() )->method( 'create' );

        $ctrl = new OpportunityController( $this->createMock( OpportunityService::class ), $repo, $this->tenantManager() );
        $resp = $ctrl->store( $this->request( [ 'lead_id' => 7 ] ) );

        $this->assertInstanceOf( \WP_Error::class, $resp );
        $this->assertSame( 422, $resp->get_error_data()['status'] );
    }

    public function test_store_returns_existing_active_opportunity_200(): void {
        $repo = $this->createMock( OpportunityRepository::class );
        $repo->method( 'getLeadSnapshotForTenant' )->willReturn( [
            'score'  => OpportunityService::QUALIFIED_THRESHOLD,
            'bot_id' => 5,
        ] );
        $repo->method( 'getActiveByLead' )->willReturn( $this->fullOpp( 12 ) );
        $repo->expects( $this->never() )->method( 'create' );

        $ctrl = new OpportunityController( $this->createMock( OpportunityService::class ), $repo, $this->tenantManager() );
        $resp = $ctrl->store( $this->request( [ 'lead_id' => 7 ] ) );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertSame( 12, $resp->get_data()['id'] );
    }

    public function test_store_creates_opportunity_201(): void {
        $repo = $this->createMock( OpportunityRepository::class );
        $repo->method( 'getLeadSnapshotForTenant' )->willReturn( [
            'score'  => OpportunityService::QUALIFIED_THRESHOLD,
            'bot_id' => 5,
        ] );
        $repo->method( 'getActiveByLead' )->willReturn( null );
        $repo->expects( $this->once() )->method( 'create' )
            ->with( $this->callback( static fn( $d ) => $d['tenant_id'] === 3 && $d['lead_id'] === 7 && $d['bot_id'] === 5 ) )
            ->willReturn( 99 );
        $repo->method( 'getById' )->willReturn( $this->fullOpp( 99 ) );

        $ctrl = new OpportunityController( $this->createMock( OpportunityService::class ), $repo, $this->tenantManager() );
        $resp = $ctrl->store( $this->request( [ 'lead_id' => 7 ] ) );

        $this->assertSame( 201, $resp->get_status() );
        $this->assertSame( 99, $resp->get_data()['id'] );
    }
}
```

- [ ] **Step 2: Correr, confirmar FAIL** (`store()` aún llama `$wpdb` → en el mock no existe `getLeadSnapshotForTenant` invocado, y el `global $wpdb` real corre sobre el stub; lo más probable es error por `getLeadSnapshotForTenant` no llamado / queries reales). El objetivo es ver rojo antes de migrar:
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter OpportunityControllerTest
```

- [ ] **Step 3: Migrar `store()`** en `src/API/OpportunityController.php`. Reemplazar el cuerpo completo del método `store()` (desde `$tenant = ...` hasta el `return ... 201`) por:

```php
    public function store( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant   = $this->tenantManager->getForCurrentUser();
        $tenantId = (int) $tenant['id'];
        $leadId   = (int) $request->get_param( 'lead_id' );

        // Leer el lead de origen (existencia + score + bot_id) en una sola query.
        $snapshot = $this->repository->getLeadSnapshotForTenant( $leadId, $tenantId );

        if ( $snapshot === null ) {
            return new \WP_Error( 'lead_not_found', 'Lead no encontrado.', [ 'status' => 404 ] );
        }

        // Verificar que el lead tiene score suficiente (R1 del opportunity-engine.md).
        if ( $snapshot['score'] < OpportunityService::QUALIFIED_THRESHOLD ) {
            return new \WP_Error(
                'lead_not_qualified',
                sprintf( 'El lead tiene score %d. Se requiere score ≥ %d para crear una oportunidad.', $snapshot['score'], OpportunityService::QUALIFIED_THRESHOLD ),
                [ 'status' => 422 ]
            );
        }

        $existing = $this->repository->getActiveByLead( $leadId, $tenantId );
        if ( $existing ) {
            return new \WP_REST_Response( $this->sanitizeOutput( $existing ), 200 );
        }

        $oppId = $this->repository->create( [
            'tenant_id'       => $tenantId,
            'lead_id'         => $leadId,
            'bot_id'          => $snapshot['bot_id'],
            'estimated_value' => $request->get_param( 'estimated_value' ),
            'currency'        => $request->get_param( 'currency' ) ?? 'ARS',
            'notes'           => $request->get_param( 'notes' ),
        ] );

        if ( ! $oppId ) {
            return new \WP_Error( 'create_failed', 'No se pudo crear la oportunidad.', [ 'status' => 500 ] );
        }

        do_action( 'infouno_opportunity_created', $oppId, $tenantId, 'new', $request->get_param( 'estimated_value' ) );

        $opp = $this->repository->getById( $oppId, $tenantId );
        return new \WP_REST_Response( $this->sanitizeOutput( $opp ), 201 );
    }
```

Verificar que no queda `$wpdb` en el archivo:
```bash
grep -n '\$wpdb' plugins/infouno-custom/src/API/OpportunityController.php   # sin resultados
```

- [ ] **Step 4: Correr test del controller + suite completa**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter OpportunityControllerTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: OpportunityControllerTest PASS (4 tests). Suite completa verde salvo el guard estático (se ajusta en Task 5) — que igual debería pasar porque OpportunityController sigue allowlisted ahora.

- [ ] **Step 5: Commit**
```bash
git add plugins/infouno-custom/src/API/OpportunityController.php plugins/infouno-custom/tests/Unit/API/OpportunityControllerTest.php
git commit -m "refactor(opportunities): OpportunityController::store delega lead snapshot al repo (SQL-free)"
```

---

## Task 4: Migrar `OpportunityDashboard` al repo

**Files:**
- Modify: `plugins/infouno-custom/src/Admin/OpportunityDashboard.php`

El dashboard ya tiene `OpportunityRepository` inyectado (`$this->opportunityRepo`). Su método privado `getOpportunitiesWithLeadData` se reemplaza por una delegación.

- [ ] **Step 1: Reemplazar el método privado**

En `src/Admin/OpportunityDashboard.php`, reemplazar todo el método `getOpportunitiesWithLeadData` (con `global $wpdb` y la query) por una delegación:

```php
    /**
     * Obtiene oportunidades con datos del lead (nombre, contacto, bot) en una sola query.
     */
    private function getOpportunitiesWithLeadData( int $tenantId, ?string $stage ): array {
        return $this->opportunityRepo->listWithLeadDataForTenant( $tenantId, $stage );
    }
```

(El `LIMIT 100` ahora vive en el default del método del repo, preservando el comportamiento.)

Verificar que no queda `$wpdb` en el archivo:
```bash
grep -n '\$wpdb' plugins/infouno-custom/src/Admin/OpportunityDashboard.php   # sin resultados
```

- [ ] **Step 2: Correr la suite completa** (no hay test unitario del dashboard; el render usa WP admin. La verificación es: SQL-free + suite verde + el guard de Task 5):
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: verde (208+ tests; sin regresiones).

- [ ] **Step 3: Commit**
```bash
git add plugins/infouno-custom/src/Admin/OpportunityDashboard.php
git commit -m "refactor(opportunities): OpportunityDashboard delega el JOIN a listWithLeadDataForTenant"
```

---

## Task 5: Reducir la ALLOWLIST — guard prueba Opportunities SQL-free

**Files:**
- Modify: `plugins/infouno-custom/tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php`

- [ ] **Step 1: Quitar las 2 entradas de la ALLOWLIST**

En la constante `ALLOWLIST`, eliminar/comentar (estilo de las entradas ya migradas):
```php
        // 'API/OpportunityController.php',   ← migrado en Increment 4 (Bloque D)
        // 'Admin/OpportunityDashboard.php',  ← migrado en Increment 4 (Bloque D)
```
Quedan solo: `'API/BotController.php'`, `'Admin/BotDashboard.php'`, `'Admin/BotWizard.php'` (3 entradas).

- [ ] **Step 2: Arreglar el self-test que usa OpportunityController de ejemplo**

El test `test_scanner_allows_allowlisted_files` usa `'API/OpportunityController.php'` como ejemplo de archivo allowlisted (lo dejó el Increment 3). Como ahora se migró, cambiar el ejemplo a uno que SIGA en la allowlist:
```php
        $fakeContent = '<?php $wpdb->get_results("SELECT * FROM wp_bots");';
        $fakeRel     = 'API/BotController.php'; // sí está en ALLOWLIST (legacy aún no migrado)
```

- [ ] **Step 3: Correr el guard + la suite completa**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter NoRawSqlOutsidePersistenceTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: guard PASS (escanea OpportunityController + OpportunityDashboard y los encuentra SQL-free); suite completa verde. Anotar el total.

- [ ] **Step 4: Commit**
```bash
git add plugins/infouno-custom/tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php
git commit -m "test(opportunities): guard verifica OpportunityController + Dashboard SQL-free (allowlist 5→3)"
```

---

## Self-Review: Cobertura vs objetivo (Bloque D, Increment 4)

| Requisito | Task(s) | Estado |
|---|---|---|
| `OpportunityRepository` extiende `TenantScopedRepository` con `table()` | Task 1 | ✅ |
| Los 9 métodos existentes usan `$this->db` y llaman `guardScope(tenant_id)` | Task 1 | ✅ (tests fail-closed por método) |
| Mover las 3 lecturas a `infouno_leads` de `OpportunityController::store` al repo | Tasks 2-3 | ✅ (consolidadas en `getLeadSnapshotForTenant`) |
| Mover el JOIN de `OpportunityDashboard` al repo | Tasks 2, 4 | ✅ (`listWithLeadDataForTenant`) |
| Comportamiento de `store()` preservado (404/422/200/201) | Task 3 | ✅ (caracterización) |
| Comportamiento del dashboard preservado (mismo JOIN, LIMIT 100) | Tasks 2, 4 | ✅ |
| Sacar ambos de la ALLOWLIST y verificar SQL-free | Task 5 | ✅ (5→3) |
| Sin cambios de esquema de BD | — ninguna task toca Migrator | ✅ |
| Wiring intacto (Plugin/RestRouter no rompen activación) | — el repo se sigue instanciando sin args; controller/dashboard ya tienen el repo inyectado | ✅ |

**Notas de riesgo:**
- `OpportunityRepository` no tenía tests previos → Task 1 agrega fail-closed por método + SQL-shape antes/después del refactor (red de seguridad nueva).
- `guardScope()` cambia el comportamiento ante `tenant_id ≤ 0`: pasa de query vacía a excepción. Los entry points ya garantizan tenant>0 (controller `permission_callback`/`requireActiveTenant`, dashboard `wp_die(403)`), así que no hay ruta observable que lance en producción — es backstop defensivo.
- Consolidación de 3 queries→1 en `store()`: optimización explícita, observ­ablemente equivalente (mismas decisiones, misma data del mismo row).
- Tras este incremento la ALLOWLIST queda en 3 (solo Bots) — objetivo del incremento 5 (allowlist vacía → guard total).
