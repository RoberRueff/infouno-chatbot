<?php
declare(strict_types=1);

namespace Infouno\SaaS\Lead;

use Infouno\SaaS\Persistence\TenantScopedRepository;

/**
 * Acceso a las tablas wp_infouno_leads y wp_infouno_lead_consents.
 *
 * Scope key principal: tenant_id (para leads).
 * Scope key secundario: bot_id (para lead_consents — el consentimiento es por
 * sesión+bot, y el bot pertenece a un tenant).
 *
 * Todo método que accede a leads recibe int $tenantId explícito y llama
 * guardScope($tenantId) antes de ejecutar SQL. Nunca devuelve datos de otro tenant.
 */
final class LeadRepository extends TenantScopedRepository {

    private string $tableLeadConsents;

    public function __construct() {
        parent::__construct();
        $this->tableLeadConsents = $this->db->prefix . 'infouno_lead_consents';
    }

    protected function table(): string {
        return $this->db->prefix . 'infouno_leads';
    }

    /**
     * Lista leads paginados del tenant, opcionalmente filtrados por estado.
     * Incluye JOIN con bots para obtener bot_name y campo de prioridad calculado.
     *
     * @param int    $tenantId  Scope obligatorio — guardScope lanza si <= 0.
     * @param ?string $status   Filtro de estado (null = todos).
     * @param int    $limit     Filas a retornar (50 en API, 100 en dashboard).
     * @param int    $offset    Offset de paginación (0 para dashboard sin paginación).
     * @return array<int, array<string, mixed>>
     * @throws \Infouno\SaaS\Persistence\MissingTenantScopeException
     */
    public function listForTenant( int $tenantId, ?string $status, int $limit, int $offset ): array {
        $this->guardScope( $tenantId );

        $leadsTable = $this->table();
        $botsTable  = $this->db->prefix . 'infouno_bots';

        if ( $status !== null && '' !== $status ) {
            $sql = $this->db->prepare(
                "SELECT l.id, l.name, l.email, l.phone, l.interest,
                        l.score, l.status, l.source, l.notes, l.created_at,
                        b.bot_name,
                        CASE WHEN l.score >= 80 THEN 'Alta'
                             WHEN l.score >= 60 THEN 'Media'
                             ELSE 'Baja' END AS prioridad
                 FROM `{$leadsTable}` l
                 INNER JOIN `{$botsTable}` b ON b.id = l.bot_id AND b.tenant_id = l.tenant_id
                 WHERE l.tenant_id = %d AND l.status = %s
                 ORDER BY l.score DESC, l.created_at DESC
                 LIMIT %d OFFSET %d",
                $tenantId,
                $status,
                $limit,
                $offset
            );
        } else {
            $sql = $this->db->prepare(
                "SELECT l.id, l.name, l.email, l.phone, l.interest,
                        l.score, l.status, l.source, l.notes, l.created_at,
                        b.bot_name,
                        CASE WHEN l.score >= 80 THEN 'Alta'
                             WHEN l.score >= 60 THEN 'Media'
                             ELSE 'Baja' END AS prioridad
                 FROM `{$leadsTable}` l
                 INNER JOIN `{$botsTable}` b ON b.id = l.bot_id AND b.tenant_id = l.tenant_id
                 WHERE l.tenant_id = %d
                 ORDER BY l.score DESC, l.created_at DESC
                 LIMIT %d OFFSET %d",
                $tenantId,
                $limit,
                $offset
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $rows = $this->db->get_results( $sql, ARRAY_A );

        return $rows ?: [];
    }

    /**
     * Verifica que un lead pertenece al tenant dado.
     * Guardrail de aislamiento: nunca asumir propiedad por existencia del ID.
     *
     * @throws \Infouno\SaaS\Persistence\MissingTenantScopeException
     */
    public function verifyOwnership( int $leadId, int $tenantId ): bool {
        $this->guardScope( $tenantId );

        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $exists = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM `{$table}` WHERE id = %d AND tenant_id = %d LIMIT 1",
                $leadId,
                $tenantId
            )
        );

        return (bool) $exists;
    }

    /**
     * Actualiza el estado de un lead verificado por ownership.
     * Registra timestamps de contacto/conversión según el estado.
     * Solo actualiza si el lead pertenece al tenant (WHERE incluye tenant_id).
     *
     * @throws \Infouno\SaaS\Persistence\MissingTenantScopeException
     */
    public function updateStatusForTenant(
        int     $leadId,
        int     $tenantId,
        string  $status,
        ?string $notes,
    ): void {
        $this->guardScope( $tenantId );

        $table         = $this->table();
        $updateData    = [ 'status' => $status ];
        $updateFormats = [ '%s' ];

        if ( $notes !== null ) {
            $updateData['notes'] = $notes;
            $updateFormats[]     = '%s';
        }

        if ( 'contacted' === $status ) {
            $updateData['contacted_at'] = gmdate( 'Y-m-d H:i:s' );
            $updateFormats[]            = '%s';
        }

        if ( 'converted' === $status ) {
            $updateData['converted_at'] = gmdate( 'Y-m-d H:i:s' );
            $updateFormats[]            = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->update(
            $table,
            $updateData,
            [ 'id' => $leadId, 'tenant_id' => $tenantId ],
            $updateFormats,
            [ '%d', '%d' ]
        );
    }

    /**
     * Retorna todos los leads del tenant para exportar como CSV.
     * Sin LIMIT — exportación completa. Incluye JOIN con bots para bot_name.
     *
     * @return array<int, array<string, mixed>>
     * @throws \Infouno\SaaS\Persistence\MissingTenantScopeException
     */
    public function listForCsv( int $tenantId ): array {
        $this->guardScope( $tenantId );

        $leadsTable = $this->table();
        $botsTable  = $this->db->prefix . 'infouno_bots';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT l.created_at, b.bot_name, l.name, l.email, l.phone,
                        l.interest, l.score, l.status, l.notes
                 FROM `{$leadsTable}` l
                 INNER JOIN `{$botsTable}` b
                    ON b.id = l.bot_id AND b.tenant_id = l.tenant_id
                 WHERE l.tenant_id = %d
                 ORDER BY l.score DESC, l.created_at DESC",
                $tenantId
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Recupera los flags de consentimiento granular de una sesión+bot en una query.
     *
     * Scope key: bot_id (no tenant_id). El bot pertenece a un tenant; si
     * el bot_id es 0 o negativo, guardScope lanza igual que para tenant_id.
     *
     * @return array{can_capture_name:int, can_capture_phone:int, can_capture_email:int}|array{}
     * @throws \Infouno\SaaS\Persistence\MissingTenantScopeException si botId <= 0.
     */
    public function getConsentsForSession( string $sessionId, int $botId ): array {
        $this->guardScope( $botId );

        $sessionHash = hash( 'sha256', $sessionId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT can_capture_name, can_capture_phone, can_capture_email
                 FROM `{$this->tableLeadConsents}`
                 WHERE session_hash = %s AND bot_id = %d
                 ORDER BY accepted_at DESC
                 LIMIT 1",
                $sessionHash,
                $botId
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return [];
        }

        $hasAny = (int) $row['can_capture_name']
                + (int) $row['can_capture_phone']
                + (int) $row['can_capture_email'];

        return $hasAny > 0 ? $row : [];
    }

    /**
     * Guarda o actualiza un lead garantizando aislamiento por tenant y bot.
     *
     * En actualización solo sobreescribe campos que vengan con valor no nulo,
     * preservando datos ya capturados frente a mensajes posteriores.
     *
     * @param array<string, mixed> $leadData
     * @throws \InvalidArgumentException Si faltan campos obligatorios.
     */
    public function save( array $leadData ): int {
        foreach ( [ 'tenant_id', 'bot_id', 'session_hash' ] as $required ) {
            if ( empty( $leadData[ $required ] ) ) {
                throw new \InvalidArgumentException( "{$required} es requerido." );
            }
        }

        $table    = $this->table();
        $existing = $this->db->get_row(
            $this->db->prepare(
                "SELECT id FROM `{$table}` WHERE session_hash = %s AND tenant_id = %d AND bot_id = %d LIMIT 1",
                $leadData['session_hash'],
                (int) $leadData['tenant_id'],
                (int) $leadData['bot_id']
            )
        );

        if ( $existing ) {
            $updateData = array_filter(
                [
                    'name'           => $leadData['name']        ?? null,
                    'phone'          => $leadData['phone']       ?? null,
                    'email'          => $leadData['email']       ?? null,
                    'interest'       => $leadData['interest']    ?? null,
                    'score'          => $leadData['score']       ?? null,
                    'temperature'    => $leadData['temperature'] ?? null,
                    'intent_signals' => isset( $leadData['intent_signals'] )
                        ? wp_json_encode( $leadData['intent_signals'] )
                        : null,
                ],
                static fn( $v ) => $v !== null
            );

            if ( ! empty( $updateData ) ) {
                $formats = array_map(
                    static fn( $k ) => $k === 'score' ? '%d' : '%s',
                    array_keys( $updateData )
                );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $this->db->update(
                    $table,
                    $updateData,
                    [ 'id' => (int) $existing->id ],
                    $formats,
                    [ '%d' ]
                );
            }

            return (int) $existing->id;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->insert(
            $table,
            [
                'tenant_id'       => (int) $leadData['tenant_id'],
                'bot_id'          => (int) $leadData['bot_id'],
                'conversation_id' => isset( $leadData['conversation_id'] ) ? (int) $leadData['conversation_id'] : null,
                'session_hash'    => $leadData['session_hash'],
                'name'            => $leadData['name']        ?? null,
                'phone'           => $leadData['phone']       ?? null,
                'email'           => $leadData['email']       ?? null,
                'interest'        => $leadData['interest']    ?? null,
                'score'           => (int) ( $leadData['score'] ?? 0 ),
                'temperature'     => $leadData['temperature'] ?? 'cold',
                'intent_signals'  => isset( $leadData['intent_signals'] )
                    ? wp_json_encode( $leadData['intent_signals'] )
                    : null,
                'source'          => $leadData['source']      ?? 'chat',
                'status'          => 'new',
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        return (int) $this->db->insert_id;
    }

    /**
     * Verifica si una sesión de un bot específico consintió la captura de un campo PII.
     *
     * @param string $dataType 'name' | 'phone' | 'email'
     */
    public function hasConsent( string $sessionId, int $botId, string $dataType ): bool {
        $columnMap = [
            'name'  => 'can_capture_name',
            'phone' => 'can_capture_phone',
            'email' => 'can_capture_email',
        ];

        if ( ! isset( $columnMap[ $dataType ] ) ) {
            return false;
        }

        $column      = $columnMap[ $dataType ];
        $sessionHash = hash( 'sha256', $sessionId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $value = $this->db->get_var(
            $this->db->prepare(
                "SELECT `{$column}` FROM `{$this->tableLeadConsents}`
                 WHERE session_hash = %s AND bot_id = %d
                 ORDER BY accepted_at DESC
                 LIMIT 1",
                $sessionHash,
                $botId
            )
        );

        return (int) $value === 1;
    }
}
