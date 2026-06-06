# Bloque D — Aislamiento de tenant fail-closed — Diseño

**Fecha:** 2026-06-05
**Alcance:** Hacer estructuralmente imposible una fuga cross-tenant: centralizar el acceso a tablas tenant-scoped en repositorios, blindar un repo base fail-closed, y agregar un guard estático que bloquee en CI cualquier SQL crudo fuera de la capa de persistencia.
**Fuera de alcance:** Cambios funcionales de negocio. Rediseño del modelo de datos (las claves de scope existentes —`tenant_id` y, para `lead_consents`, `bot_id`/`session_hash`— se respetan tal cual). Bloques A/B/C.

---

## 1. Problema (con evidencia del código)

El aislamiento por tenant hoy descansa en **disciplina humana**, no en diseño. Evidencia:

- **SQL crudo desparramado fuera de repos:** `~15` archivos en `src/API/`, `src/Admin/` y servicios tocan tablas `wp_infouno_*` directamente con `$wpdb`. Ejemplos: `API/ConsentController.php` (22 llamadas `$wpdb`), `API/LeadController.php` (arma el JOIN `leads`+`bots` a mano), `Admin/LeadDashboard.php`, `Admin/OpportunityDashboard.php`, `Channel/ChannelConsentService.php`, `Lead/LeadService.php`.
- **Resolución fail-OPEN del tenant:** `API/LeadController.php::getTenantId()` devuelve `0` si no hay tenant en vez de fallar:
  ```php
  private function getTenantId(): int {
      $tenant = $this->tenantManager->getForCurrentUser();
      return $tenant ? (int) $tenant['id'] : 0; // ← fail-open
  }
  ```
  Hoy lo cubre el `permission_callback`, pero la primitiva es insegura: un llamador futuro sin ese guard filtra `tenant_id = 0`.

Un solo `WHERE tenant_id` olvidado = Cliente A ve datos de Cliente B = incidente SaaS crítico. La solución no es "tener más cuidado": es que el **diseño impida el error**.

## 2. Principios

1. **El SQL vive solo en la capa de persistencia.** Controllers, admin y servicios no tocan `$wpdb`.
2. **Fail-closed:** sin un tenant válido (> 0), la operación lanza excepción. Nunca "mostrar todo", nunca `tenant_id = 0` silencioso.
3. **Sin estado ambient para correctitud.** El tenant se pasa explícito a cada método del repo. (Decisión: NO se construye `TenantContext` estático — evita el footgun de que el contexto de un tenant se filtre entre acciones del mismo proceso PHP en jobs de Action Scheduler.)
4. **Guard estático en CI:** una violación (SQL crudo fuera de persistencia) **falla el build**, no se descubre en producción.
5. **Migración incremental:** el guard arranca con una allowlist de legacy conocido y bloquea toda violación NUEVA de inmediato. La allowlist se achica migrando tabla por tabla.

---

## 3. Componentes

### 3.1 `Persistence\TenantScopedRepository` (clase base abstracta)

Namespace nuevo: `Infouno\SaaS\Persistence`.

Responsabilidad: ser el único lugar autorizado a ejecutar SQL sobre una tabla tenant-scoped, garantizando que **ninguna operación corre sin un id de scope positivo**.

```php
abstract class TenantScopedRepository {
    protected \wpdb $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    /** Nombre completo de la tabla (con prefijo). */
    abstract protected function table(): string;

    /**
     * Fail-closed: lanza si el id de scope no es positivo.
     * @throws MissingTenantScopeException
     */
    final protected function guardScope( int $scopeId ): int {
        if ( $scopeId <= 0 ) {
            throw new MissingTenantScopeException(
                static::class . ': operación sin scope de tenant válido.'
            );
        }
        return $scopeId;
    }
}
```

- La base **no** intenta validar que el string SQL contenga `tenant_id` (imposible de forma confiable con JOINs/alias). La garantía se compone de dos hechos verificables: (a) todo el SQL vive en repos (guard estático §3.3), y (b) todo método de repo exige un scope positivo vía `guardScope()` (runtime).
- **Dos claves de scope:** `guardScope()` es genérico. La mayoría de los repos pasan `tenant_id`; el repo de consents granular (`lead_consents`) pasa `bot_id` (un bot pertenece a un tenant). Cada subclase documenta su clave de scope.

### 3.2 `Persistence\MissingTenantScopeException`

```php
final class MissingTenantScopeException extends \RuntimeException {}
```

Se mapea a HTTP 500 en controllers (es un error de programación, no de input): nunca debería llegar al usuario; si llega, es un bug que el guard estático + tests debieron atrapar.

### 3.3 Guard estático — test PHPUnit que escanea `src/`

`tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php`.

- Recorre los archivos de capas que **no** deben tener SQL: `src/API/`, `src/Admin/`, y servicios no-persistencia explícitos (`src/Lead/LeadService.php`, `src/Channel/ChannelConsentService.php`, …).
- Falla si encuentra el token `$wpdb->` en alguno de esos archivos, **salvo** que esté en la `ALLOWLIST` (legacy conocido aún no migrado).
- **Permitidos siempre** (no escaneados): la capa de persistencia (`*Repository.php`, los managers de datos `BotManager`/`TenantManager`, y todo lo que viva en `src/Persistence/`) y `src/Core/Migrator.php` (DDL).
- La `ALLOWLIST` es un array explícito en el test. Cada migración de un archivo lo **borra** de la allowlist. El estado final: allowlist vacía.

```php
private const ALLOWLIST = [
    'API/ConsentController.php',
    'API/LeadController.php',
    'API/OpportunityController.php',
    'API/BotController.php',
    'Admin/LeadDashboard.php',
    'Admin/OpportunityDashboard.php',
    'Admin/BotDashboard.php',
    'Admin/BotWizard.php',
    'Lead/LeadService.php',
    'Channel/ChannelConsentService.php',
];
```

> El test bloquea **toda violación nueva** desde el día 1: si alguien agrega `$wpdb` a un archivo de API/Admin no allowlisted, el build falla. La allowlist solo tolera lo que ya existía.

### 3.4 Resolución de tenant fail-closed (reemplazo del fail-open)

- Reemplazar `getTenantId(): int { ... : 0 }` por un resolver que **lanza** si no hay tenant. Como el `permission_callback` ya garantiza tenant activo, en la práctica nunca lanza — pero deja de ser fail-open:

```php
private function requireTenantId(): int {
    $tenant = $this->tenantManager->getForCurrentUser();
    if ( ! $tenant || (int) $tenant['id'] <= 0 ) {
        throw new MissingTenantScopeException( 'Sin tenant activo en contexto autenticado.' );
    }
    return (int) $tenant['id'];
}
```

- Patrón reutilizable: un trait `Persistence\RequiresTenant` o un helper en `TenantManager` (`requireForCurrentUser(): array`). Se decide en el plan; preferencia: método `TenantManager::requireForCurrentUser()` que lanza, para no duplicar el patrón en cada controller.

### 3.5 Repos nuevos / extendidos

| Tabla(s) | Repo destino | Estado |
|---|---|---|
| `leads` | `Lead\LeadRepository` (extender la base) | existe, agregar `listForTenant`, `findForTenant`, `updateStatusForTenant`, métricas |
| `consents`, `lead_consents` | `Persistence\ConsentRepository` (nuevo) | hoy el SQL vive en `ConsentController` y `ChannelConsentService` — sin home |
| `opportunities` | `Opportunity\OpportunityRepository` (extender la base) | existe, absorber queries de `OpportunityController`/dashboard |
| `bots` | `Bot\BotManager` (extender la base) | existe, absorber queries de `BotController`/`BotWizard`/`BotDashboard` |

---

## 4. Estrategia incremental (orden de migración)

Cada incremento es un PR chico, verde, que **achica la allowlist**. Orden por riesgo (PII/comercial primero):

1. **Fundación (sin migrar nada todavía):** `TenantScopedRepository` + `MissingTenantScopeException` + el test guard con la allowlist completa + `TenantManager::requireForCurrentUser()`. El build queda verde (allowlist cubre todo el legacy).
2. **Dominio Leads:** mover SQL de `LeadController` + `Admin/LeadDashboard` + `Lead/LeadService` a `LeadRepository`. Sacar esos 3 de la allowlist. Reemplazar `getTenantId()` fail-open por `requireTenantId()`.
3. **Dominio Consents:** crear `ConsentRepository`, mover SQL de `ConsentController` + `ChannelConsentService`. Sacarlos de la allowlist.
4. **Dominio Opportunities:** mover SQL de `OpportunityController` + `Admin/OpportunityDashboard` a `OpportunityRepository`. Sacarlos.
5. **Dominio Bots:** mover SQL de `BotController` + `Admin/BotWizard` + `Admin/BotDashboard` a `BotManager`. Sacarlos. **Allowlist vacía → guard total.**

> Cada incremento es independiente y deja el sistema funcionando. Se pueden ejecutar en sesiones separadas. El **plan de implementación cubre los incrementos 1 y 2** (fundación + dominio Leads); los demás se planifican igual después.

---

## 5. Error handling

- `MissingTenantScopeException` en un controller → `WP_Error`/`WP_REST_Response` 500 con mensaje genérico (es un bug, no input del usuario). Se loguea estructurado.
- En jobs de Action Scheduler (canales), el tenant viene del canal (`channel['tenant_id']`), explícito; si faltara, la excepción aborta esa acción sin afectar otras.

## 6. Testing

- **Unit del repo base:** `guardScope()` lanza `MissingTenantScopeException` con `0` y negativos; devuelve el id con positivos.
- **Guard estático:** el test escanea y pasa con la allowlist actual; un fixture que simula `$wpdb` en un archivo no-allowlisted hace fallar el test (se verifica la lógica del scanner con un caso controlado).
- **Repos migrados:** cada método nuevo (`listForTenant`, etc.) con test que verifica que el `tenant_id` se aplica (mock de `$wpdb` o assert sobre el SQL preparado), siguiendo el patrón de los tests existentes.
- **`requireTenantId()`:** lanza sin tenant; devuelve el id con tenant.

## 7. Reglas no negociables respetadas

- Toda query a `wp_infouno_*` incluye filtro de scope (ahora garantizado por diseño, no por disciplina).
- `tenant_id` siempre del servidor (sesión autenticada o dueño del bot), nunca del request body.
- Sin `DROP`. Este bloque no toca el esquema (no hay migración de BD).
- Lead Engine sigue best-effort.

## 8. Resumen de archivos

| Archivo | Cambio |
|---|---|
| `src/Persistence/TenantScopedRepository.php` | Crear (base fail-closed). |
| `src/Persistence/MissingTenantScopeException.php` | Crear. |
| `src/Persistence/ConsentRepository.php` | Crear (incremento 3). |
| `src/Tenant/TenantManager.php` | Agregar `requireForCurrentUser()` fail-closed. |
| `src/Lead/LeadRepository.php` | Extender base; absorber queries de Lead (incremento 2). |
| `src/Opportunity/OpportunityRepository.php` | Extender base; absorber queries (incremento 4). |
| `src/Bot/BotManager.php` | Extender base; absorber queries (incremento 5). |
| `src/API/*Controller.php`, `src/Admin/*.php`, servicios | Quitar SQL crudo; delegar al repo. |
| `tests/Unit/Architecture/NoRawSqlOutsidePersistenceTest.php` | Crear (guard estático + allowlist). |
| `tests/Unit/Persistence/TenantScopedRepositoryTest.php` | Crear. |
