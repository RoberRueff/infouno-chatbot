<?php
declare(strict_types=1);

namespace Infouno\SaaS\Persistence;

/**
 * Clase base para repositorios de tablas tenant-scoped.
 *
 * Garantiza que ninguna operación corre sin un id de scope positivo.
 * La garantía se compone de dos hechos verificables:
 *   (a) todo el SQL vive en repos (guard estático en NoRawSqlOutsidePersistenceTest),
 *   (b) todo método de repo exige un scope positivo via guardScope() (runtime).
 *
 * Dos claves de scope usadas en el sistema:
 *   - tenant_id  → la mayoría de repos (leads, bots, opportunities, etc.)
 *   - bot_id     → LeadRepository::hasConsent() y getConsentsForSession()
 *                  (el bot pertenece a un tenant; el scope es el bot).
 *
 * Cada subclase documenta su clave de scope en su propio docblock.
 */
abstract class TenantScopedRepository {

    protected \wpdb $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * Nombre completo de la tabla (con prefijo wp_).
     * Implementado por cada subclase — p. ej. `$this->db->prefix . 'infouno_leads'`.
     */
    abstract protected function table(): string;

    /**
     * Fail-closed: lanza MissingTenantScopeException si el scope no es positivo.
     * Devuelve el id tal cual si es válido, para uso encadenado.
     *
     * @throws MissingTenantScopeException si $scopeId <= 0.
     */
    final protected function guardScope( int $scopeId ): int {
        if ( $scopeId <= 0 ) {
            throw new MissingTenantScopeException(
                static::class . ': operación sin scope de tenant válido (id=' . $scopeId . ').'
            );
        }
        return $scopeId;
    }
}
