<?php

declare(strict_types=1);

namespace Infouno\SaaS\Opportunity;

use Infouno\SaaS\Persistence\TenantScopedRepository;

/**
 * Acceso a wp_infouno_opportunities.
 *
 * Extiende TenantScopedRepository: usa $this->db (wpdb inyectado por el ctor base)
 * y exige un scope positivo via guardScope($tenantId) como primera línea de cada
 * método — fail-closed. Toda query incluye tenant_id (guardrail de aislamiento
 * multitenant). No se declaran FKs en la tabla; integridad garantizada por la
 * capa de aplicación.
 *
 * Scope key: tenant_id.
 */
final class OpportunityRepository extends TenantScopedRepository {

    /** Stages válidos en orden de pipeline. */
    public const STAGES = [ 'new', 'contacted', 'interested', 'quoted', 'won', 'lost' ];

    /** Estados terminales — no pueden avanzar a otro stage. */
    public const TERMINAL_STAGES = [ 'won', 'lost' ];

    protected function table(): string {
        return $this->db->prefix . 'infouno_opportunities';
    }

    /**
     * Crea una nueva oportunidad y retorna su ID.
     */
    public function create( array $data ): int {
        $this->guardScope( (int) $data['tenant_id'] );

        $table = $this->table();

        $this->db->insert(
            $table,
            [
                'tenant_id'       => (int) $data['tenant_id'],
                'lead_id'         => (int) $data['lead_id'],
                'bot_id'          => (int) $data['bot_id'],
                'stage'           => 'new',
                'estimated_value' => isset( $data['estimated_value'] ) ? (float) $data['estimated_value'] : null,
                'currency'        => $data['currency'] ?? 'ARS',
                'assigned_to'     => isset( $data['assigned_to'] ) ? (int) $data['assigned_to'] : null,
                'notes'           => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : null,
                'stage_changed_at' => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $this->db->insert_id;
    }

    /**
     * Retorna la oportunidad activa (no won/lost) de un lead dentro del tenant.
     * "Activa" = stage NOT IN ('won', 'lost').
     */
    public function getActiveByLead( int $leadId, int $tenantId ): ?array {
        $this->guardScope( $tenantId );

        $table = $this->table();

        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM `{$table}`
                 WHERE lead_id = %d AND tenant_id = %d
                   AND stage NOT IN ('won', 'lost')
                 ORDER BY created_at DESC
                 LIMIT 1",
                $leadId,
                $tenantId
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Retorna una oportunidad por ID verificando ownership del tenant.
     */
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

    /**
     * Lista las oportunidades de un tenant con filtros opcionales.
     * Orden: oportunidades activas primero (FIELD sort), luego por created_at DESC.
     */
    public function listForTenant(
        int     $tenantId,
        ?string $stage  = null,
        int     $limit  = 50,
        int     $offset = 0
    ): array {
        $this->guardScope( $tenantId );

        $table = $this->table();

        $where = $this->db->prepare( 'WHERE tenant_id = %d', $tenantId );

        if ( $stage !== null && in_array( $stage, self::STAGES, true ) ) {
            $where  .= $this->db->prepare( ' AND stage = %s', $stage );
        }

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM `{$table}` {$where}
                 ORDER BY FIELD(stage,'new','contacted','interested','quoted','lost','won'), created_at DESC
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Cuenta las oportunidades del tenant por stage (para paginación y métricas).
     */
    public function countForTenant( int $tenantId, ?string $stage = null ): int {
        $this->guardScope( $tenantId );

        $table = $this->table();

        if ( $stage !== null && in_array( $stage, self::STAGES, true ) ) {
            return (int) $this->db->get_var(
                $this->db->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = %d AND stage = %s",
                    $tenantId,
                    $stage
                )
            );
        }

        return (int) $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = %d",
                $tenantId
            )
        );
    }

    /**
     * Métricas de pipeline para el tenant: conteos por stage y valor total activo.
     * Calculado en tiempo real — no cachear para no mostrar datos desactualizados.
     *
     * @return array{total: int, by_stage: array<string,int>, pipeline_value: float, won_count: int, lost_count: int}
     */
    public function getPipelineMetrics( int $tenantId ): array {
        $this->guardScope( $tenantId );

        $table   = $this->table();
        $byStage = [];
        $total   = 0;

        foreach ( self::STAGES as $stage ) {
            $count             = $this->countForTenant( $tenantId, $stage );
            $byStage[ $stage ] = $count;
            $total            += $count;
        }

        $pipelineValue = (float) $this->db->get_var(
            $this->db->prepare(
                "SELECT COALESCE(SUM(estimated_value), 0)
                 FROM `{$table}`
                 WHERE tenant_id = %d
                   AND stage NOT IN ('won', 'lost')
                   AND estimated_value IS NOT NULL",
                $tenantId
            )
        );

        return [
            'total'          => $total,
            'by_stage'       => $byStage,
            'pipeline_value' => $pipelineValue,
            'won_count'      => $byStage['won']  ?? 0,
            'lost_count'     => $byStage['lost'] ?? 0,
        ];
    }

    /**
     * Cambia el stage de una oportunidad.
     *
     * Guardrail: won/lost son terminales — no se pueden cambiar una vez alcanzados.
     * Retorna true si el UPDATE afectó filas, false si ya estaba en ese stage o estaba bloqueado.
     */
    public function updateStage(
        int    $id,
        int    $tenantId,
        string $newStage,
        string $lostReason = ''
    ): bool {
        $this->guardScope( $tenantId );

        $table = $this->table();
        $now   = gmdate( 'Y-m-d H:i:s' );

        $current = $this->getById( $id, $tenantId );
        if ( ! $current ) {
            return false;
        }

        // Guardrail comercial (R8 en commercial-data-integrity.md): won/lost son terminales.
        if ( in_array( $current['stage'], self::TERMINAL_STAGES, true ) ) {
            return false;
        }

        $fields = [
            'stage'           => $newStage,
            'stage_changed_at' => $now,
        ];
        $formats = [ '%s', '%s' ];

        if ( 'won' === $newStage ) {
            $fields['won_at'] = $now;
            $formats[]        = '%s';
        }

        if ( 'lost' === $newStage ) {
            $fields['lost_at'] = $now;
            $formats[]         = '%s';
            if ( $lostReason ) {
                $fields['lost_reason'] = sanitize_text_field( $lostReason );
                $formats[]             = '%s';
            }
        }

        $updated = $this->db->update(
            $table,
            $fields,
            [ 'id' => $id, 'tenant_id' => $tenantId ],
            $formats,
            [ '%d', '%d' ]
        );

        return (bool) $updated;
    }

    /**
     * Actualiza el valor estimado de la oportunidad.
     */
    public function updateValue( int $id, int $tenantId, float $value, string $currency = 'ARS' ): bool {
        $this->guardScope( $tenantId );

        $table   = $this->table();
        $updated = $this->db->update(
            $table,
            [ 'estimated_value' => $value, 'currency' => strtoupper( substr( $currency, 0, 3 ) ) ],
            [ 'id' => $id, 'tenant_id' => $tenantId ],
            [ '%s', '%s' ],
            [ '%d', '%d' ]
        );

        return (bool) $updated;
    }

    /**
     * Registra un intento de automatización (email, webhook, WhatsApp, reminder).
     * No previene duplicados aquí — idempotencia responsabilidad del llamador.
     */
    public function logAutomation(
        int    $tenantId,
        string $actionType,
        string $status    = 'ok',
        ?int   $opportunityId = null,
        ?int   $leadId        = null,
        ?array $metadata      = null
    ): int {
        $this->guardScope( $tenantId );

        $table = $this->db->prefix . 'infouno_automation_logs';

        $this->db->insert(
            $table,
            [
                'tenant_id'      => $tenantId,
                'opportunity_id' => $opportunityId,
                'lead_id'        => $leadId,
                'action_type'    => sanitize_text_field( $actionType ),
                'status'         => sanitize_text_field( $status ),
                'metadata'       => $metadata ? wp_json_encode( $metadata ) : null,
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s' ]
        );

        return (int) $this->db->insert_id;
    }

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
}
