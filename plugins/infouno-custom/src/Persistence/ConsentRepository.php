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
     * Usa COUNT(*) en vez de SELECT id LIMIT 1 para preservar exactamente la query
     * original de ChannelConsentService (el resultado booleano es idéntico).
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
