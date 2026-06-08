# Bloque D Incremento 3 — Dominio Consents — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mover todo el SQL crudo de `API/ConsentController.php` (22 llamadas `$wpdb->`) y `Channel/ChannelConsentService.php` a un nuevo `Persistence\ConsentRepository` fail-closed, y sacar ambos archivos de la ALLOWLIST del guard estático.

**Architecture:** `ConsentRepository extends TenantScopedRepository` (la base creada en el incremento 1) absorbe las tres tablas del dominio consents: `wp_infouno_consents` (tabla principal, `table()`), `wp_infouno_lead_consents` y `wp_infouno_leads` (solo la anonimización del revoke). Cada método recibe su scope explícito (`bot_id` o `tenant_id` según la query original) y llama `guardScope()` antes de tocar SQL. Los controllers/servicios pasan a delegar: ninguno conserva `$wpdb->`. La ALLOWLIST del guard pierde 2 entradas (quedan 5).

**Tech Stack:** PHP 8.1+, PSR-4 `Infouno\SaaS\`, PHPUnit (corre en Docker `php:8.3-cli` — NO hay PHP local), WpdbStub de `tests/bootstrap.php`.

**Comando de tests (desde `plugins/infouno-custom/`):**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage [--filter <TestName>]
```

---

## Contexto crítico para el implementador

**No conocés el codebase. Leé esto antes de tocar nada:**

1. **El patrón ya existe.** `src/Lead/LeadRepository.php` es la referencia: extiende `TenantScopedRepository`, guarda tablas secundarias en props privadas en el constructor, y cada método llama `$this->guardScope($scope)` como primera línea. Copiá ese estilo (docblocks, `phpcs:ignore`, `$this->db->prepare(...)`).

2. **La clase base** (`src/Persistence/TenantScopedRepository.php`) provee:
   - `protected \wpdb $db;` (tomado de `global $wpdb` en el constructor)
   - `abstract protected function table(): string;`
   - `final protected function guardScope( int $scopeId ): int;` — lanza `MissingTenantScopeException` si `$scopeId <= 0`, si no devuelve el id.

3. **Dos claves de scope.** La mayoría de queries filtran por `tenant_id`. Pero el consentimiento es por **sesión+bot**, así que varias queries filtran por `bot_id` (el bot pertenece a un tenant). Cada método de abajo dice qué scope usa — **respetalo exactamente**, porque cambiarlo altera la semántica.

4. **El stub de BD** (`tests/bootstrap.php`, clase `WpdbStub`):
   - `get_var()` / `get_row()` devuelven `$wpdb->stub_get_var` / `stub_get_row` y setean `$wpdb->last_query`.
   - `insert($table, $data, $formats)` invoca el callback `$wpdb->onInsert($table, $data)` si está seteado, y setea `insert_id` a 1 (si era 0).
   - `update(...)` setea `$wpdb->last_update_data` y `$wpdb->last_update_where`.
   - `query($sql)` setea `$wpdb->last_query` **y** `$wpdb->last_write_query` (usá `last_write_query` para asserts sobre UPDATEs hechos vía `query()`).
   - `prepare()` sustituye `%s`→`'valor'` y `%d`→`valor`, así que las assertions pueden buscar substrings del SQL final.

5. **Esquema de columnas** (de `src/Core/Migrator.php`):
   - `wp_infouno_consents`: `id, bot_id, tenant_id, session_hash, consent_version, scope ('chat'|'lead_capture'|'consent_revoked'), channel (default 'web'), ip_hash, user_agent_hash, accepted_at (default CURRENT_TIMESTAMP)`.
   - `wp_infouno_lead_consents`: `id, tenant_id, bot_id, session_hash, can_capture_name, can_capture_phone, can_capture_email (TINYINT default 0), consent_version, ip_hash, user_agent_hash, accepted_at (default CURRENT_TIMESTAMP)`.
   - `wp_infouno_leads`: tiene `name, phone, email, session_hash, tenant_id` (la anonimización las pone en NULL).

6. **Guardrail (Ley 25.326 / tenant isolation):** Toda query DEBE filtrar por su scope (`bot_id` o `tenant_id`). La migración **no debe cambiar comportamiento observable** de los endpoints — es un refactor puro de "dónde vive el SQL". No agregues, quites ni reordenes inserts/updates.

---

## File Structure

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `src/Persistence/ConsentRepository.php` | **Crear** | Todo el SQL del dominio consents (3 tablas). Extiende `TenantScopedRepository`. |
| `tests/Unit/Persistence/ConsentRepositoryTest.php` | **Crear** | Unit tests de cada método: scope correcto, columnas/tabla correctas, fail-closed. |
| `src/API/ConsentController.php` | **Modificar** | Delegar los 3 endpoints (record/recordLead/revoke) al repo. Quitar todo `$wpdb->`. Ctor +1 dep. |
| `tests/Unit/API/ConsentControllerTest.php` | **Crear** | Tests de caracterización de los 3 endpoints (idempotencia + delegación correcta). |
| `src/API/RestRouter.php:44` | **Modificar** | Inyectar `ConsentRepository` al construir `ConsentController`. |
| `src/Channel/ChannelConsentService.php` | **Modificar** | Delegar `ensure()` al repo. Quitar `$wpdb->`. Ctor +1 dep. |
| `tests/Unit/Channel/ChannelConsentServiceTest.php` | **Modificar** | Actualizar para el nuevo ctor (sigue verde con el WpdbStub). |
| `src/Plugin.php:145` | **Modificar** | Construir `ChannelConsentService` con un `ConsentRepository`. |
| `tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php` | **Modificar** | Sacar `ConsentController` y `ChannelConsentService` de la ALLOWLIST. Arreglar el self-test que los usaba de ejemplo. |

---

## Task 1: `ConsentRepository` — tabla `consents` (existencia + insert)

**Files:**
- Create: `plugins/infouno-custom/src/Persistence/ConsentRepository.php`
- Test: `plugins/infouno-custom/tests/Unit/Persistence/ConsentRepositoryTest.php`

Esta tarea crea el esqueleto del repo (extiende la base, define `table()` y las props de tablas secundarias) y los **tres métodos de la tabla `consents`**: existencia por bot, existencia por tenant, e insert genérico de fila de evidencia.

- [ ] **Step 1: Escribir los tests que fallan**

Crear `tests/Unit/Persistence/ConsentRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Persistence;

use Infouno\SaaS\Persistence\ConsentRepository;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use PHPUnit\Framework\TestCase;

final class ConsentRepositoryTest extends TestCase {

    protected function setUp(): void {
        // Reset del stub entre tests.
        $GLOBALS['wpdb']->stub_get_var  = null;
        $GLOBALS['wpdb']->stub_get_row  = null;
        $GLOBALS['wpdb']->last_query    = '';
        $GLOBALS['wpdb']->last_write_query = '';
        $GLOBALS['wpdb']->onInsert      = null;
        $GLOBALS['wpdb']->insert_id     = 0;
    }

    // ── consentExistsByBot ────────────────────────────────────────────────

    public function test_consentExistsByBot_returns_true_when_row_present(): void {
        $GLOBALS['wpdb']->stub_get_var = '42';
        $repo = new ConsentRepository();

        $this->assertTrue( $repo->consentExistsByBot( 7, 'abc', 'chat' ) );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'bot_id = 7', $q );
        $this->assertStringContainsString( "scope = 'chat'", $q );
        $this->assertStringContainsString( "session_hash = 'abc'", $q );
    }

    public function test_consentExistsByBot_returns_false_when_absent(): void {
        $GLOBALS['wpdb']->stub_get_var = null;
        $repo = new ConsentRepository();
        $this->assertFalse( $repo->consentExistsByBot( 7, 'abc', 'chat' ) );
    }

    public function test_consentExistsByBot_fails_closed_on_zero_bot(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new ConsentRepository() )->consentExistsByBot( 0, 'abc', 'chat' );
    }

    // ── consentExistsByTenant ─────────────────────────────────────────────

    public function test_consentExistsByTenant_uses_tenant_filter_and_count(): void {
        $GLOBALS['wpdb']->stub_get_var = '1';
        $repo = new ConsentRepository();

        $this->assertTrue( $repo->consentExistsByTenant( 3, 'xyz', 'chat' ) );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'COUNT(*)', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
        $this->assertStringContainsString( "scope = 'chat'", $q );
    }

    public function test_consentExistsByTenant_false_when_count_zero(): void {
        $GLOBALS['wpdb']->stub_get_var = '0';
        $this->assertFalse( ( new ConsentRepository() )->consentExistsByTenant( 3, 'xyz', 'chat' ) );
    }

    public function test_consentExistsByTenant_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new ConsentRepository() )->consentExistsByTenant( 0, 'xyz', 'chat' );
    }

    // ── recordConsentRow (web: sin channel) ───────────────────────────────

    public function test_recordConsentRow_web_inserts_into_consents_with_scope(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$captured ) {
            $captured = [ 'table' => $table, 'data' => $data ];
        };
        $repo = new ConsentRepository();
        $repo->recordConsentRow( 3, 7, 'sess', 'lead_capture', '1.0', 'iphash', 'uahash' );

        $this->assertSame( 'wp_infouno_consents', $captured['table'] );
        $this->assertSame( 7, $captured['data']['bot_id'] );
        $this->assertSame( 3, $captured['data']['tenant_id'] );
        $this->assertSame( 'sess', $captured['data']['session_hash'] );
        $this->assertSame( 'lead_capture', $captured['data']['scope'] );
        $this->assertSame( 'iphash', $captured['data']['ip_hash'] );
        $this->assertSame( 'uahash', $captured['data']['user_agent_hash'] );
        // path web: NO setea channel ni accepted_at (quedan a default de la BD).
        $this->assertArrayNotHasKey( 'channel', $captured['data'] );
        $this->assertArrayNotHasKey( 'accepted_at', $captured['data'] );
    }

    // ── recordConsentRow (canal: con channel) ─────────────────────────────

    public function test_recordConsentRow_channel_sets_channel_and_timestamp(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$captured ) {
            $captured = $data;
        };
        $repo = new ConsentRepository();
        $repo->recordConsentRow( 3, 7, 'sess', 'chat', '1.0', '', '', 'telegram' );

        $this->assertSame( 'telegram', $captured['channel'] );
        $this->assertArrayHasKey( 'accepted_at', $captured );
        $this->assertSame( '', $captured['ip_hash'] );
    }

    public function test_recordConsentRow_fails_closed_on_zero_bot(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new ConsentRepository() )->recordConsentRow( 3, 0, 'sess', 'chat', '1.0', '', '' );
    }
}
```

- [ ] **Step 2: Correr los tests para verificar que fallan**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ConsentRepositoryTest
```
Esperado: FAIL — `Class "Infouno\SaaS\Persistence\ConsentRepository" not found`.

- [ ] **Step 3: Crear `ConsentRepository` con los métodos de la tabla `consents`**

Crear `src/Persistence/ConsentRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Persistence;

/**
 * Acceso a las tablas del dominio de consentimiento (Ley 25.326):
 *   - wp_infouno_consents       (tabla principal, evidencia legal por scope)
 *   - wp_infouno_lead_consents  (flags de captura PII granular)
 *   - wp_infouno_leads          (solo anonimización en revoke)
 *
 * Dos claves de scope, según la query original:
 *   - bot_id    → existencia/insert de consent web, lead_consents, revoke de flags.
 *   - tenant_id → existencia de consent de canal y anonimización de leads.
 *
 * Cada método llama guardScope() con su clave antes de ejecutar SQL.
 * El refactor preserva exactamente el comportamiento previo de ConsentController
 * y ChannelConsentService — no agrega, quita ni reordena operaciones.
 */
final class ConsentRepository extends TenantScopedRepository {

    private string $tableLeadConsents;
    private string $tableLeads;

    public function __construct() {
        parent::__construct();
        $this->tableLeadConsents = $this->db->prefix . 'infouno_lead_consents';
        $this->tableLeads        = $this->db->prefix . 'infouno_leads';
    }

    protected function table(): string {
        return $this->db->prefix . 'infouno_consents';
    }

    // ── tabla consents ────────────────────────────────────────────────────

    /**
     * ¿Existe una fila de consent con el scope dado para este bot + sesión?
     * Scope key: bot_id.
     *
     * @throws MissingTenantScopeException si $botId <= 0.
     */
    public function consentExistsByBot( int $botId, string $sessionHash, string $scope ): bool {
        $this->guardScope( $botId );
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $id = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM `{$table}` WHERE bot_id = %d AND session_hash = %s AND scope = %s LIMIT 1",
                $botId,
                $sessionHash,
                $scope
            )
        );

        return (bool) $id;
    }

    /**
     * Igual que consentExistsByBot pero filtra por tenant_id (path de canales sociales,
     * que históricamente cuenta filas por tenant). Scope key: tenant_id.
     *
     * @throws MissingTenantScopeException si $tenantId <= 0.
     */
    public function consentExistsByTenant( int $tenantId, string $sessionHash, string $scope ): bool {
        $this->guardScope( $tenantId );
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $count = (int) $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = %d AND session_hash = %s AND scope = %s",
                $tenantId,
                $sessionHash,
                $scope
            )
        );

        return $count > 0;
    }

    /**
     * Inserta una fila de evidencia en consents. Cubre los tres scopes
     * ('chat' | 'lead_capture' | 'consent_revoked') y ambos orígenes:
     *   - web   ($channel === null): no setea channel ni accepted_at (defaults de BD).
     *   - canal ($channel !== null): setea channel + accepted_at; ip/ua suelen ir vacíos.
     *
     * Scope key: bot_id.
     *
     * @throws MissingTenantScopeException si $botId <= 0.
     */
    public function recordConsentRow(
        int     $tenantId,
        int     $botId,
        string  $sessionHash,
        string  $scope,
        string  $version,
        string  $ipHash,
        string  $uaHash,
        ?string $channel = null,
    ): void {
        $this->guardScope( $botId );

        $data = [
            'bot_id'          => $botId,
            'tenant_id'       => $tenantId,
            'session_hash'    => $sessionHash,
            'consent_version' => $version,
            'scope'           => $scope,
            'ip_hash'         => $ipHash,
            'user_agent_hash' => $uaHash,
        ];
        $formats = [ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ];

        if ( $channel !== null ) {
            $data['channel']     = $channel;
            $data['accepted_at'] = gmdate( 'Y-m-d H:i:s' );
            $formats[]           = '%s';
            $formats[]           = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->insert( $this->table(), $data, $formats );
    }
}
```

- [ ] **Step 4: Correr los tests para verificar que pasan**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ConsentRepositoryTest
```
Esperado: PASS (8 tests, los de esta tarea).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Persistence/ConsentRepository.php plugins/infouno-custom/tests/Unit/Persistence/ConsentRepositoryTest.php
git commit -m "feat(consents): ConsentRepository — métodos de tabla consents (Bloque D inc3)"
```

---

## Task 2: `ConsentRepository` — tablas `lead_consents` y `leads`

**Files:**
- Modify: `plugins/infouno-custom/src/Persistence/ConsentRepository.php`
- Test: `plugins/infouno-custom/tests/Unit/Persistence/ConsentRepositoryTest.php`

Agrega los cuatro métodos restantes: existencia en lead_consents, insert de consent PII, anonimización de leads (revoke) y desactivación de flags de captura.

- [ ] **Step 1: Agregar los tests que fallan** (al final de la clase `ConsentRepositoryTest`, antes del `}` de cierre)

```php
    // ── leadConsentExists ─────────────────────────────────────────────────

    public function test_leadConsentExists_filters_by_bot_and_session(): void {
        $GLOBALS['wpdb']->stub_get_var = '5';
        $repo = new ConsentRepository();

        $this->assertTrue( $repo->leadConsentExists( 7, 'sess' ) );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'lead_consents', $q );
        $this->assertStringContainsString( 'bot_id = 7', $q );
        $this->assertStringContainsString( "session_hash = 'sess'", $q );
    }

    public function test_leadConsentExists_fails_closed_on_zero_bot(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new ConsentRepository() )->leadConsentExists( 0, 'sess' );
    }

    // ── recordLeadConsentRow ──────────────────────────────────────────────

    public function test_recordLeadConsentRow_maps_capture_flags(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$captured ) {
            $captured = [ 'table' => $table, 'data' => $data ];
        };
        $repo = new ConsentRepository();
        $repo->recordLeadConsentRow( 3, 7, 'sess', true, false, true, '1.0', 'ip', 'ua' );

        $this->assertSame( 'wp_infouno_lead_consents', $captured['table'] );
        $this->assertSame( 1, $captured['data']['can_capture_name'] );
        $this->assertSame( 0, $captured['data']['can_capture_phone'] );
        $this->assertSame( 1, $captured['data']['can_capture_email'] );
        $this->assertSame( 3, $captured['data']['tenant_id'] );
        $this->assertSame( 7, $captured['data']['bot_id'] );
        $this->assertArrayNotHasKey( 'accepted_at', $captured['data'] );
    }

    public function test_recordLeadConsentRow_with_timestamp_sets_accepted_at(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$captured ) {
            $captured = $data;
        };
        $repo = new ConsentRepository();
        $repo->recordLeadConsentRow( 3, 7, 'sess', true, true, true, '1.0', '', '', true );

        $this->assertArrayHasKey( 'accepted_at', $captured );
    }

    public function test_recordLeadConsentRow_fails_closed_on_zero_bot(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new ConsentRepository() )->recordLeadConsentRow( 3, 0, 'sess', true, true, true, '1.0', '', '' );
    }

    // ── anonymizeLeadPii ──────────────────────────────────────────────────

    public function test_anonymizeLeadPii_nulls_pii_scoped_by_tenant(): void {
        $repo = new ConsentRepository();
        $repo->anonymizeLeadPii( 3, 'sess' );

        $q = $GLOBALS['wpdb']->last_write_query;
        $this->assertStringContainsString( 'UPDATE', $q );
        $this->assertStringContainsString( 'infouno_leads', $q );
        $this->assertStringContainsString( 'name = NULL', $q );
        $this->assertStringContainsString( 'phone = NULL', $q );
        $this->assertStringContainsString( 'email = NULL', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
        $this->assertStringContainsString( "session_hash = 'sess'", $q );
    }

    public function test_anonymizeLeadPii_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new ConsentRepository() )->anonymizeLeadPii( 0, 'sess' );
    }

    // ── revokeCaptureFlags ────────────────────────────────────────────────

    public function test_revokeCaptureFlags_zeroes_flags_scoped_by_bot(): void {
        $repo = new ConsentRepository();
        $repo->revokeCaptureFlags( 7, 'sess' );

        $q = $GLOBALS['wpdb']->last_write_query;
        $this->assertStringContainsString( 'UPDATE', $q );
        $this->assertStringContainsString( 'lead_consents', $q );
        $this->assertStringContainsString( 'can_capture_name = 0', $q );
        $this->assertStringContainsString( 'can_capture_phone = 0', $q );
        $this->assertStringContainsString( 'can_capture_email = 0', $q );
        $this->assertStringContainsString( 'bot_id = 7', $q );
        $this->assertStringContainsString( "session_hash = 'sess'", $q );
    }

    public function test_revokeCaptureFlags_fails_closed_on_zero_bot(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new ConsentRepository() )->revokeCaptureFlags( 0, 'sess' );
    }
```

- [ ] **Step 2: Correr los tests para verificar que fallan**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ConsentRepositoryTest
```
Esperado: FAIL — `Call to undefined method ...::leadConsentExists()`.

- [ ] **Step 3: Agregar los métodos al final de `ConsentRepository` (antes del `}` de cierre de la clase)**

```php
    // ── tabla lead_consents ───────────────────────────────────────────────

    /**
     * ¿Existe ya un registro de consentimiento PII para este bot + sesión?
     * Scope key: bot_id.
     *
     * @throws MissingTenantScopeException si $botId <= 0.
     */
    public function leadConsentExists( int $botId, string $sessionHash ): bool {
        $this->guardScope( $botId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $id = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM `{$this->tableLeadConsents}` WHERE bot_id = %d AND session_hash = %s LIMIT 1",
                $botId,
                $sessionHash
            )
        );

        return (bool) $id;
    }

    /**
     * Inserta el consentimiento granular PII (flags name/phone/email).
     * Scope key: bot_id. Si $withTimestamp, setea accepted_at (path canal);
     * si no, lo deja a default de BD (path web).
     *
     * @throws MissingTenantScopeException si $botId <= 0.
     */
    public function recordLeadConsentRow(
        int    $tenantId,
        int    $botId,
        string $sessionHash,
        bool   $canName,
        bool   $canPhone,
        bool   $canEmail,
        string $version,
        string $ipHash,
        string $uaHash,
        bool   $withTimestamp = false,
    ): void {
        $this->guardScope( $botId );

        $data = [
            'tenant_id'         => $tenantId,
            'bot_id'            => $botId,
            'session_hash'      => $sessionHash,
            'can_capture_name'  => (int) $canName,
            'can_capture_phone' => (int) $canPhone,
            'can_capture_email' => (int) $canEmail,
            'consent_version'   => $version,
            'ip_hash'           => $ipHash,
            'user_agent_hash'   => $uaHash,
        ];
        $formats = [ '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ];

        if ( $withTimestamp ) {
            $data['accepted_at'] = gmdate( 'Y-m-d H:i:s' );
            $formats[]           = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->insert( $this->tableLeadConsents, $data, $formats );
    }

    // ── tabla leads (solo revoke) ─────────────────────────────────────────

    /**
     * Anonimiza la PII de un lead (name/phone/email → NULL) — Art. 16 Ley 25.326.
     * Scope key: tenant_id.
     *
     * @throws MissingTenantScopeException si $tenantId <= 0.
     */
    public function anonymizeLeadPii( int $tenantId, string $sessionHash ): void {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $this->db->query(
            $this->db->prepare(
                "UPDATE `{$this->tableLeads}`
                 SET name = NULL, phone = NULL, email = NULL
                 WHERE session_hash = %s AND tenant_id = %d",
                $sessionHash,
                $tenantId
            )
        );
    }

    /**
     * Desactiva los flags de captura futura de una sesión+bot — tras revocar,
     * el usuario no puede ser recapturado sin un nuevo consentimiento explícito.
     * Scope key: bot_id.
     *
     * @throws MissingTenantScopeException si $botId <= 0.
     */
    public function revokeCaptureFlags( int $botId, string $sessionHash ): void {
        $this->guardScope( $botId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $this->db->query(
            $this->db->prepare(
                "UPDATE `{$this->tableLeadConsents}`
                 SET can_capture_name = 0, can_capture_phone = 0, can_capture_email = 0
                 WHERE session_hash = %s AND bot_id = %d",
                $sessionHash,
                $botId
            )
        );
    }
```

- [ ] **Step 4: Correr los tests para verificar que pasan**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ConsentRepositoryTest
```
Esperado: PASS (todos, ~18 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Persistence/ConsentRepository.php plugins/infouno-custom/tests/Unit/Persistence/ConsentRepositoryTest.php
git commit -m "feat(consents): ConsentRepository — lead_consents + anonimización + revoke flags"
```

---

## Task 3: Migrar `ConsentController` al repositorio

**Files:**
- Modify: `plugins/infouno-custom/src/API/ConsentController.php`
- Modify: `plugins/infouno-custom/src/API/RestRouter.php` (línea ~44)
- Test: `plugins/infouno-custom/tests/Unit/API/ConsentControllerTest.php` (crear)

El controller actualmente NO tiene tests. Primero escribimos tests de caracterización (delegando al repo vía mock), luego refactorizamos. La firma pública de los 3 endpoints y sus respuestas HTTP **no cambian**.

- [ ] **Step 1: Escribir los tests de caracterización**

Crear `tests/Unit/API/ConsentControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\API;

use Infouno\SaaS\API\ConsentController;
use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Chat\ConversationRepository;
use Infouno\SaaS\Persistence\ConsentRepository;
use PHPUnit\Framework\TestCase;

final class ConsentControllerTest extends TestCase {

    private function bot(): array {
        return [ 'id' => 7, 'tenant_id' => 3 ];
    }

    private function makeRequest( array $params ): \WP_REST_Request {
        $req = new \WP_REST_Request();
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return $req;
    }

    public function test_record_returns_already_consented_when_chat_consent_exists(): void {
        $botMgr = $this->createMock( BotManager::class );
        $botMgr->method( 'getByPublicToken' )->willReturn( $this->bot() );
        $botMgr->method( 'validateOrigin' )->willReturn( true );

        $repo = $this->createMock( ConsentRepository::class );
        $repo->method( 'consentExistsByBot' )->willReturn( true );
        $repo->expects( $this->never() )->method( 'recordConsentRow' );

        $ctrl = new ConsentController( $botMgr, $this->createMock( ConversationRepository::class ), $repo );
        $resp = $ctrl->record( $this->makeRequest( [ 'bot_token' => str_repeat( 'a', 64 ), 'session_id' => 'session1' ] ) );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertFalse( $resp->get_data()['recorded'] );
    }

    public function test_record_inserts_chat_consent_when_absent(): void {
        $botMgr = $this->createMock( BotManager::class );
        $botMgr->method( 'getByPublicToken' )->willReturn( $this->bot() );
        $botMgr->method( 'validateOrigin' )->willReturn( true );

        $repo = $this->createMock( ConsentRepository::class );
        $repo->method( 'consentExistsByBot' )->willReturn( false );
        $repo->expects( $this->once() )
            ->method( 'recordConsentRow' )
            ->with( 3, 7, $this->anything(), 'chat', $this->anything(), $this->anything(), $this->anything() );

        $ctrl = new ConsentController( $botMgr, $this->createMock( ConversationRepository::class ), $repo );
        $resp = $ctrl->record( $this->makeRequest( [ 'bot_token' => str_repeat( 'a', 64 ), 'session_id' => 'session1' ] ) );

        $this->assertSame( 201, $resp->get_status() );
        $this->assertTrue( $resp->get_data()['recorded'] );
    }

    public function test_record_returns_404_when_bot_missing(): void {
        $botMgr = $this->createMock( BotManager::class );
        $botMgr->method( 'getByPublicToken' )->willReturn( null );

        $ctrl = new ConsentController(
            $botMgr,
            $this->createMock( ConversationRepository::class ),
            $this->createMock( ConsentRepository::class )
        );
        $resp = $ctrl->record( $this->makeRequest( [ 'bot_token' => str_repeat( 'a', 64 ), 'session_id' => 'session1' ] ) );

        $this->assertInstanceOf( \WP_Error::class, $resp );
    }

    public function test_revoke_anonymizes_pii_and_flags_and_records_audit(): void {
        $botMgr = $this->createMock( BotManager::class );
        $botMgr->method( 'getByPublicToken' )->willReturn( $this->bot() );
        $botMgr->method( 'validateOrigin' )->willReturn( true );

        $conv = $this->createMock( ConversationRepository::class );
        $conv->method( 'deleteSession' )->willReturn( 4 );

        $repo = $this->createMock( ConsentRepository::class );
        $repo->expects( $this->once() )->method( 'anonymizeLeadPii' )->with( 3, $this->anything() );
        $repo->expects( $this->once() )->method( 'revokeCaptureFlags' )->with( 7, $this->anything() );
        $repo->method( 'consentExistsByBot' )->willReturn( false );
        $repo->expects( $this->once() )
            ->method( 'recordConsentRow' )
            ->with( 3, 7, $this->anything(), 'consent_revoked', $this->anything(), $this->anything(), $this->anything() );

        $ctrl = new ConsentController( $botMgr, $conv, $repo );
        $resp = $ctrl->revoke( $this->makeRequest( [ 'bot_token' => str_repeat( 'a', 64 ), 'session_id' => 'session1' ] ) );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertTrue( $resp->get_data()['revoked'] );
        $this->assertSame( 4, $resp->get_data()['messages_processed'] );
    }
}
```

- [ ] **Step 2: Correr para verificar que fallan**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ConsentControllerTest
```
Esperado: FAIL — el ctor actual de `ConsentController` toma 2 args, no 3 (`ArgumentCountError`).

- [ ] **Step 3: Refactorizar `ConsentController`**

3a. **Constructor** — agregar la dependencia (línea ~24-27):

```php
    public function __construct(
        private readonly BotManager             $botManager,
        private readonly ConversationRepository $conversationRepo,
        private readonly ConsentRepository      $consentRepo,
    ) {}
```

3b. **Import** — agregar bajo los `use` existentes (línea ~8):

```php
use Infouno\SaaS\Persistence\ConsentRepository;
```

3c. **`record()`** — reemplazar el cuerpo desde `$botId = (int) $bot['id'];` hasta el `return` final (líneas ~124-156) por:

```php
        $botId    = (int) $bot['id'];
        $tenantId = (int) $bot['tenant_id'];
        $hashes   = $this->buildHashes( $sessionId );
        $version  = defined( 'INFOUNO_CONSENT_VERSION' ) ? INFOUNO_CONSENT_VERSION : '1.0';

        // Filtra por scope='chat' para no confundir con filas de lead_capture.
        if ( $this->consentRepo->consentExistsByBot( $botId, $hashes['session'], 'chat' ) ) {
            return new \WP_REST_Response( [ 'recorded' => false, 'reason' => 'already_consented' ], 200 );
        }

        $this->consentRepo->recordConsentRow(
            $tenantId,
            $botId,
            $hashes['session'],
            'chat',
            $version,
            $hashes['ip'],
            $hashes['ua'],
        );

        return new \WP_REST_Response( [ 'recorded' => true ], 201 );
```

Eliminar la línea `global $wpdb;` al inicio de `record()`.

3d. **`recordLead()`** — reemplazar el cuerpo desde `$botId = (int) $bot['id'];` hasta el `return` final (líneas ~185-247) por:

```php
        $botId    = (int) $bot['id'];
        $tenantId = (int) $bot['tenant_id'];
        $version  = defined( 'INFOUNO_CONSENT_VERSION' ) ? INFOUNO_CONSENT_VERSION : '1.0';
        $hashes   = $this->buildHashes( $sessionId );

        if ( $this->consentRepo->leadConsentExists( $botId, $hashes['session'] ) ) {
            return new \WP_REST_Response( [ 'recorded' => false, 'reason' => 'already_consented' ], 200 );
        }

        $this->consentRepo->recordLeadConsentRow(
            $tenantId,
            $botId,
            $hashes['session'],
            ! empty( $scopes['name'] ),
            ! empty( $scopes['phone'] ),
            ! empty( $scopes['email'] ),
            $version,
            $hashes['ip'],
            $hashes['ua'],
        );

        // Evidencia legal independiente en wp_infouno_consents con scope='lead_capture'.
        if ( ! $this->consentRepo->consentExistsByBot( $botId, $hashes['session'], 'lead_capture' ) ) {
            $this->consentRepo->recordConsentRow(
                $tenantId,
                $botId,
                $hashes['session'],
                'lead_capture',
                $version,
                $hashes['ip'],
                $hashes['ua'],
            );
        }

        return new \WP_REST_Response( [ 'recorded' => true ], 201 );
```

Eliminar la línea `global $wpdb;` al inicio de `recordLead()`.

3e. **`revoke()`** — reemplazar el cuerpo desde `$botId = (int) $bot['id'];` hasta el `error_log(...)` (líneas ~283-343, dejando el `error_log` y el `return` finales) por:

```php
        $botId    = (int) $bot['id'];
        $tenantId = (int) $bot['tenant_id'];
        $hashes   = $this->buildHashes( $sessionId );
        $version  = defined( 'INFOUNO_CONSENT_VERSION' ) ? INFOUNO_CONSENT_VERSION : '1.0';

        // 1. Anonimizar mensajes y conversaciones.
        $messagesProcessed = $this->conversationRepo->deleteSession( $sessionId, $tenantId );

        // 2. Anonimizar PII en leads — supresión de datos, núcleo del Art. 16.
        $this->consentRepo->anonymizeLeadPii( $tenantId, $hashes['session'] );

        // 3. Desactivar flags de captura futura.
        $this->consentRepo->revokeCaptureFlags( $botId, $hashes['session'] );

        // 4. Audit trail inmutable de la revocación (el registro original se preserva).
        if ( ! $this->consentRepo->consentExistsByBot( $botId, $hashes['session'], 'consent_revoked' ) ) {
            $this->consentRepo->recordConsentRow(
                $tenantId,
                $botId,
                $hashes['session'],
                'consent_revoked',
                $version,
                $hashes['ip'],
                $hashes['ua'],
            );
        }
```

Eliminar la línea `global $wpdb;` al inicio de `revoke()`. Dejar intactos el `error_log(...)` y el `return new \WP_REST_Response(...)` que siguen.

Verificar al final que **no queda ningún `$wpdb`** en el archivo:
```bash
grep -n '\$wpdb' plugins/infouno-custom/src/API/ConsentController.php   # debe no devolver nada
```

- [ ] **Step 4: Actualizar `RestRouter` (instanciación)**

En `src/API/RestRouter.php` línea ~44, cambiar:

```php
        $this->consentController        = new ConsentController( $this->botManager, $this->conversationRepo );
```

por:

```php
        $this->consentController        = new ConsentController( $this->botManager, $this->conversationRepo, new \Infouno\SaaS\Persistence\ConsentRepository() );
```

(Si en la cabecera de `RestRouter.php` ya hay un bloque `use`, podés agregar `use Infouno\SaaS\Persistence\ConsentRepository;` y usar `new ConsentRepository()` en su lugar — seguí el estilo del archivo.)

- [ ] **Step 5: Correr tests del controller + suite completa**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ConsentControllerTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: ConsentControllerTest PASS; suite completa sin regresiones (salvo el guard estático, que se ajusta en Task 5).

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/API/ConsentController.php plugins/infouno-custom/src/API/RestRouter.php plugins/infouno-custom/tests/Unit/API/ConsentControllerTest.php
git commit -m "refactor(consents): ConsentController delega SQL a ConsentRepository (fail-closed)"
```

---

## Task 4: Migrar `ChannelConsentService` al repositorio

**Files:**
- Modify: `plugins/infouno-custom/src/Channel/ChannelConsentService.php`
- Modify: `plugins/infouno-custom/src/Plugin.php` (línea ~145)
- Modify: `plugins/infouno-custom/tests/Unit/Channel/ChannelConsentServiceTest.php`

- [ ] **Step 1: Actualizar el test existente para el nuevo ctor**

En `tests/Unit/Channel/ChannelConsentServiceTest.php`:

1a. Agregar el import bajo el `use` existente:
```php
use Infouno\SaaS\Persistence\ConsentRepository;
```

1b. Cambiar las dos instanciaciones `new ChannelConsentService()` por `new ChannelConsentService( new ConsentRepository() )`. (El repo usa el `global $wpdb` = WpdbStub, así que los inserts siguen fluyendo por `onInsert` y los asserts existentes sobre `wp_infouno_consents` / `wp_infouno_lead_consents` quedan válidos.)

- [ ] **Step 2: Correr para verificar que falla**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ChannelConsentServiceTest
```
Esperado: FAIL — `ArgumentCountError` (el ctor todavía no acepta el repo).

- [ ] **Step 3: Refactorizar `ChannelConsentService`**

Reemplazar el archivo completo por:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

use Infouno\SaaS\Persistence\ConsentRepository;

/**
 * Consentimiento por primer mensaje (Ley 25.326) para canales sociales.
 *
 * En el primer contacto de un usuario de canal registra evidencia server-side
 * en consents (uso del chat) y lead_consents (captura PII granular). El modelo
 * legal elegido es "continuar la conversación = aceptación": el dispatcher envía
 * el aviso legal + link a la política como mensaje de bienvenida.
 *
 * Todo el SQL vive en ConsentRepository (capa de persistencia fail-closed).
 */
final class ChannelConsentService {

    private const CONSENT_VERSION = '1.0';

    public function __construct(
        private readonly ConsentRepository $consentRepo,
    ) {}

    /**
     * Asegura el consentimiento para una sesión de canal.
     * @return bool true si es primer contacto (recién registrado) — el caller debe enviar la bienvenida legal.
     */
    public function ensure( int $tenantId, int $botId, string $channel, string $conversationKey ): bool {
        $sessionHash = hash( 'sha256', $conversationKey );

        // ¿Ya existe consentimiento de chat para esta sesión de canal? (scope por tenant).
        if ( $this->consentRepo->consentExistsByTenant( $tenantId, $sessionHash, 'chat' ) ) {
            return false;
        }

        // Evidencia de consentimiento de uso del chat (sin IP/UA en canales).
        $this->consentRepo->recordConsentRow(
            $tenantId,
            $botId,
            $sessionHash,
            'chat',
            self::CONSENT_VERSION,
            '',
            '',
            $channel,
        );

        // Consentimiento granular PII (por primer mensaje habilita los 3 campos).
        $this->consentRepo->recordLeadConsentRow(
            $tenantId,
            $botId,
            $sessionHash,
            true,
            true,
            true,
            self::CONSENT_VERSION,
            '',
            '',
            true,
        );

        return true;
    }
}
```

Verificar que no queda `$wpdb`:
```bash
grep -n '\$wpdb' plugins/infouno-custom/src/Channel/ChannelConsentService.php   # sin resultados
```

- [ ] **Step 4: Actualizar `Plugin.php` (instanciación)**

En `src/Plugin.php` línea ~145, cambiar:

```php
                new ChannelConsentService(),
```

por:

```php
                new ChannelConsentService( new \Infouno\SaaS\Persistence\ConsentRepository() ),
```

(O agregá `use Infouno\SaaS\Persistence\ConsentRepository;` en la cabecera y usá `new ConsentRepository()`, según el estilo de imports del archivo.)

- [ ] **Step 5: Correr tests del canal + suite**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ChannelConsentServiceTest
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: ChannelConsentServiceTest PASS; suite sin regresiones (salvo el guard, Task 5).

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/Channel/ChannelConsentService.php plugins/infouno-custom/src/Plugin.php plugins/infouno-custom/tests/Unit/Channel/ChannelConsentServiceTest.php
git commit -m "refactor(consents): ChannelConsentService delega SQL a ConsentRepository"
```

---

## Task 5: Reducir la ALLOWLIST — guard prueba que Consents es SQL-free

**Files:**
- Modify: `plugins/infouno-custom/tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php`

Ahora que ambos archivos delegan, sacarlos de la ALLOWLIST hace que el guard estático los escanee y verifique que ya no tienen `$wpdb->`.

- [ ] **Step 1: Quitar las 2 entradas de la ALLOWLIST**

En `NoRawSqlOutsidePersistenceTest.php`, en la constante `ALLOWLIST`, eliminar (o comentar, siguiendo el estilo de las entradas ya migradas de Leads) estas dos líneas:

```php
        'API/ConsentController.php',
        ...
        'Channel/ChannelConsentService.php',
```

Dejar el resto: `OpportunityController`, `BotController`, `OpportunityDashboard`, `BotDashboard`, `BotWizard` (5 entradas restantes). Estilo sugerido (consistente con las marcas de Increment 2):

```php
        // 'API/ConsentController.php',         ← migrado en Increment 3 (Consents)
        // 'Channel/ChannelConsentService.php', ← migrado en Increment 3 (Consents)
```

**Importante:** `ChannelConsentService.php` debe SEGUIR en el set de archivos escaneados (la lista explícita de servicios, junto a `Lead/LeadService.php`). Solo se quita de la ALLOWLIST, no del escaneo — así el guard confirma que quedó SQL-free.

- [ ] **Step 2: Arreglar el self-test que usaba ConsentController de ejemplo**

Buscar en el archivo el test que verifica que un archivo allowlisted es reconocido como tal (usa `'API/ConsentController.php'` como ejemplo de "sí está en ALLOWLIST"):

```bash
grep -n "ConsentController" plugins/infouno-custom/tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php
```

En el test `test_scanner_allows_allowlisted_files` (o equivalente), cambiar el archivo de ejemplo de `'API/ConsentController.php'` a uno que SIGA en la allowlist, p. ej.:

```php
        $fakeRel = 'API/OpportunityController.php'; // sí está en ALLOWLIST (legacy aún no migrado)
```

- [ ] **Step 3: Correr el guard estático**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter NoRawSqlOutsidePersistenceTest
```
Esperado: PASS. Si falla con "archivos con SQL crudo no allowlisted", revisar que Tasks 3 y 4 no dejaron ningún `$wpdb->` en los dos archivos migrados.

- [ ] **Step 4: Correr la suite COMPLETA**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Esperado: TODO verde, sin regresiones. Anotá el total de tests.

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php
git commit -m "test(consents): guard estático verifica ConsentController + ChannelConsentService SQL-free (allowlist 7→5)"
```

---

## Self-Review: Cobertura vs Spec (Bloque D, Increment 3)

| Requisito | Task(s) | Estado |
|---|---|---|
| `ConsentRepository extends TenantScopedRepository` con `table()` = consents | Task 1 | ✅ |
| Mover las 22 llamadas `$wpdb->` de `ConsentController` al repo | Tasks 1-3 | ✅ (record, recordLead, revoke) |
| Mover el SQL de `ChannelConsentService::ensure()` al repo | Tasks 1-2, 4 | ✅ (existsByTenant + recordConsentRow + recordLeadConsentRow) |
| Cada método del repo llama `guardScope()` con su scope correcto (bot_id/tenant_id) | Tasks 1-2 | ✅ (tests fail-closed por método) |
| Preservar idempotencia de los 3 endpoints (already_consented) | Task 3 | ✅ (consentExistsByBot / leadConsentExists) |
| Preservar la doble evidencia (lead_consents + scope='lead_capture') de recordLead | Task 3 | ✅ |
| Preservar las 4 operaciones de revoke (deleteSession + anonymize + flags + audit) | Tasks 2-3 | ✅ |
| Preservar comportamiento de canal (existencia por tenant, channel + accepted_at, flags=1) | Tasks 1-2, 4 | ✅ |
| Sacar ambos archivos de la ALLOWLIST y verificar SQL-free | Task 5 | ✅ |
| `ChannelConsentService` sigue escaneado (no solo quitado de allowlist) | Task 5 — nota explícita | ✅ |
| Sin cambios de esquema de BD (ninguna migración) | — ninguna task toca Migrator | ✅ |
| Actualizar instanciaciones (RestRouter, Plugin) para no romper wp-load CI | Tasks 3-4 | ✅ |

**Notas de riesgo:**
- `ConsentController` no tenía tests previos → Task 3 agrega caracterización antes de refactorizar (red de seguridad).
- El orden de columnas en los INSERT del repo difiere del original (channel/accepted_at al final) pero es irrelevante: son inserts por nombre de columna; lo único que importa es la alineación data↔formats, verificada en los tests.
- Tras este incremento la ALLOWLIST queda en 5 (Opportunities + Bots), objetivo de los incrementos 4-5.
