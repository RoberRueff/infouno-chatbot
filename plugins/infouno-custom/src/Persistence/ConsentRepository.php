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
}
