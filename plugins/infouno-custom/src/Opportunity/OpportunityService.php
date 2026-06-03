<?php

declare(strict_types=1);

namespace Infouno\SaaS\Opportunity;

/**
 * Orquestador del Opportunity Engine.
 *
 * Escucha el hook infouno_lead_captured y convierte leads calificados
 * en oportunidades con pipeline stage y valor estimado.
 *
 * Reglas de negocio (ia/rules/opportunity-engine.md):
 *   - Solo crea oportunidades para leads con score ≥ 60.
 *   - Una sola oportunidad activa por lead (no won/lost). Si ya existe, no duplicar.
 *   - Estados terminales (won/lost) no pueden cambiar de stage.
 *   - Cada cambio de stage dispara do_action('infouno_opportunity_stage_changed').
 *   - La creación dispara do_action('infouno_opportunity_created').
 *
 * Guardrail: cualquier error en este service se captura con catch(\Throwable) —
 * nunca interrumpe el pipeline de chat ni el Lead Engine.
 */
final class OpportunityService {

    /** Score mínimo para crear una oportunidad desde un lead. */
    public const QUALIFIED_THRESHOLD = 60;

    public function __construct(
        private readonly OpportunityRepository $repository,
    ) {}

    /**
     * Listener del hook infouno_lead_captured.
     *
     * Se registra en Plugin::boot() como:
     *   add_action('infouno_lead_captured', [$this->opportunityService, 'onLeadCaptured'], 20, 4)
     *
     * Prioridad 20 (posterior al email de notificación en prioridad 10).
     *
     * @param int   $leadId   ID del lead recién capturado.
     * @param int   $tenantId ID del tenant.
     * @param int   $botId    ID del bot.
     * @param array $result   Resultado del LeadScorer: score, extracted, temperature, intent_signals.
     */
    public function onLeadCaptured( int $leadId, int $tenantId, int $botId, array $result ): void {
        try {
            $score = (int) ( $result['score'] ?? 0 );

            if ( $score < self::QUALIFIED_THRESHOLD ) {
                return;
            }

            $this->createFromLead( $leadId, $tenantId, $botId, $result );
        } catch ( \Throwable $e ) {
            error_log( sprintf(
                '[INFOUNO] OpportunityService::onLeadCaptured error. Lead: %d, Tenant: %d. %s',
                $leadId,
                $tenantId,
                $e->getMessage()
            ) );
        }
    }

    /**
     * Crea una oportunidad desde un lead calificado, respetando la regla de unicidad:
     * si ya existe una oportunidad activa (no won/lost) para el lead, no duplicar.
     *
     * @return int opportunity_id (nuevo o existente).
     */
    public function createFromLead( int $leadId, int $tenantId, int $botId, array $result = [] ): int {
        $existing = $this->repository->getActiveByLead( $leadId, $tenantId );

        if ( $existing ) {
            return (int) $existing['id'];
        }

        $opportunityId = $this->repository->create( [
            'tenant_id' => $tenantId,
            'lead_id'   => $leadId,
            'bot_id'    => $botId,
        ] );

        if ( $opportunityId > 0 ) {
            $this->repository->logAutomation(
                $tenantId,
                'opportunity_created',
                'ok',
                $opportunityId,
                $leadId,
                [ 'score' => $result['score'] ?? 0, 'temperature' => $result['temperature'] ?? 'cold' ]
            );

            do_action( 'infouno_opportunity_created', $opportunityId, $tenantId, 'new', null );
        }

        return $opportunityId;
    }

    /**
     * Cambia el stage de una oportunidad.
     *
     * Guardrail: won/lost son terminales. El repository rechaza el cambio silenciosamente.
     * Si el stage pasa a 'won', dispara infouno_deal_won para Revenue Attribution (Fase 3).
     *
     * @param string $lostReason Obligatorio si $newStage === 'lost'. Opcional en los demás casos.
     * @return bool true si el stage cambió, false si fue rechazado (terminal o inexistente).
     */
    public function updateStage(
        int    $opportunityId,
        int    $tenantId,
        string $newStage,
        string $lostReason = ''
    ): bool {
        if ( ! in_array( $newStage, OpportunityRepository::STAGES, true ) ) {
            return false;
        }

        $current = $this->repository->getById( $opportunityId, $tenantId );
        if ( ! $current ) {
            return false;
        }

        $fromStage = $current['stage'];
        $changed   = $this->repository->updateStage( $opportunityId, $tenantId, $newStage, $lostReason );

        if ( ! $changed ) {
            return false;
        }

        do_action( 'infouno_opportunity_stage_changed', $opportunityId, $tenantId, $fromStage, $newStage );

        if ( 'won' === $newStage ) {
            $updated = $this->repository->getById( $opportunityId, $tenantId );
            $value   = isset( $updated['estimated_value'] ) ? (float) $updated['estimated_value'] : null;

            do_action( 'infouno_deal_won', $opportunityId, $tenantId, $value );

            $this->repository->logAutomation(
                $tenantId,
                'deal_won',
                'ok',
                $opportunityId,
                (int) $current['lead_id'],
                [ 'confirmed_value' => $value, 'currency' => $updated['currency'] ?? 'ARS' ]
            );
        }

        return true;
    }

    /**
     * Actualiza el valor estimado de la oportunidad.
     */
    public function updateValue( int $opportunityId, int $tenantId, float $value, string $currency = 'ARS' ): bool {
        return $this->repository->updateValue( $opportunityId, $tenantId, $value, $currency );
    }

    /**
     * Retorna las métricas de pipeline para el tenant.
     * Calcula en tiempo real desde la BD (R14 de commercial-data-integrity.md).
     */
    public function getPipelineMetrics( int $tenantId ): array {
        $metrics = [
            'total'         => 0,
            'by_stage'      => [],
            'pipeline_value' => 0.0,
            'won_count'     => 0,
            'lost_count'    => 0,
        ];

        foreach ( OpportunityRepository::STAGES as $stage ) {
            $count = $this->repository->countForTenant( $tenantId, $stage );
            $metrics['by_stage'][ $stage ] = $count;
            $metrics['total']             += $count;
        }

        $metrics['won_count']  = $metrics['by_stage']['won']  ?? 0;
        $metrics['lost_count'] = $metrics['by_stage']['lost'] ?? 0;

        // Valor de pipeline = suma de estimated_value en stages activos (excluye won y lost)
        global $wpdb;
        $table = $wpdb->prefix . 'infouno_opportunities';
        $metrics['pipeline_value'] = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(estimated_value), 0)
                 FROM `{$table}`
                 WHERE tenant_id = %d
                   AND stage NOT IN ('won', 'lost')
                   AND estimated_value IS NOT NULL",
                $tenantId
            )
        );

        return $metrics;
    }
}
