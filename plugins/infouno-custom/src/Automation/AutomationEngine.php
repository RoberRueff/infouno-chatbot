<?php

declare(strict_types=1);

namespace Infouno\SaaS\Automation;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Opportunity\OpportunityRepository;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Evalúa y ejecuta automatizaciones cuando cambia el estado del pipeline.
 *
 * Escucha:
 *   - infouno_opportunity_created       → email al tenant + webhook al CRM
 *   - infouno_opportunity_stage_changed → webhook al CRM (sin email por cada cambio)
 *   - infouno_deal_won                  → email de cierre + webhook al CRM
 *
 * Reglas (ia/rules/opportunity-engine.md):
 *   - Toda acción se registra en wp_infouno_automation_logs.
 *   - Idempotencia: transient de 1h previene ejecuciones duplicadas por oportunidad.
 *   - Fallback silencioso: catch(\Throwable) nunca interrumpe el pipeline.
 *   - webhook_url se lee desde bot['settings']['webhook_url'] — configurado por el tenant.
 */
final class AutomationEngine {

    public function __construct(
        private readonly OpportunityRepository  $opportunityRepo,
        private readonly TenantManager          $tenantManager,
        private readonly BotManager             $botManager,
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    /**
     * Listener de infouno_opportunity_created.
     * Registrado en Plugin::boot() con add_action(..., 10, 4).
     */
    public function onOpportunityCreated( int $oppId, int $tenantId, string $stage, ?float $estimatedValue ): void {
        try {
            $transientKey = "infouno_auto_opp_created_{$oppId}";
            if ( get_transient( $transientKey ) ) {
                return;
            }
            set_transient( $transientKey, 1, HOUR_IN_SECONDS );

            $opp = $this->opportunityRepo->getById( $oppId, $tenantId );
            if ( ! $opp ) {
                return;
            }

            $this->dispatcher->sendOpportunityCreatedEmail( $tenantId, $opp );

            $webhookUrl = $this->resolveWebhookUrl( (int) $opp['bot_id'], $tenantId );
            if ( $webhookUrl ) {
                $this->dispatcher->fireWebhook(
                    $webhookUrl,
                    [
                        'event'           => 'opportunity_created',
                        'opportunity_id'  => $oppId,
                        'tenant_id'       => $tenantId,
                        'stage'           => $stage,
                        'estimated_value' => $estimatedValue,
                    ],
                    $tenantId,
                    $oppId,
                    isset( $opp['lead_id'] ) ? (int) $opp['lead_id'] : null,
                    'webhook_opportunity_created'
                );
            }
        } catch ( \Throwable $e ) {
            error_log( sprintf(
                '[INFOUNO] AutomationEngine::onOpportunityCreated error. Opp: %d, Tenant: %d. %s',
                $oppId,
                $tenantId,
                $e->getMessage()
            ) );
        }
    }

    /**
     * Listener de infouno_opportunity_stage_changed.
     * Solo dispara webhook — no email en cada cambio de stage para no spamear al tenant.
     * Registrado en Plugin::boot() con add_action(..., 10, 4).
     */
    public function onStageChanged( int $oppId, int $tenantId, string $fromStage, string $toStage ): void {
        try {
            $opp = $this->opportunityRepo->getById( $oppId, $tenantId );
            if ( ! $opp ) {
                return;
            }

            $webhookUrl = $this->resolveWebhookUrl( (int) $opp['bot_id'], $tenantId );
            if ( ! $webhookUrl ) {
                return;
            }

            $this->dispatcher->fireWebhook(
                $webhookUrl,
                [
                    'event'          => 'opportunity_stage_changed',
                    'opportunity_id' => $oppId,
                    'tenant_id'      => $tenantId,
                    'from_stage'     => $fromStage,
                    'to_stage'       => $toStage,
                ],
                $tenantId,
                $oppId,
                isset( $opp['lead_id'] ) ? (int) $opp['lead_id'] : null,
                'webhook_stage_changed'
            );
        } catch ( \Throwable $e ) {
            error_log( sprintf(
                '[INFOUNO] AutomationEngine::onStageChanged error. Opp: %d, Tenant: %d. %s',
                $oppId,
                $tenantId,
                $e->getMessage()
            ) );
        }
    }

    /**
     * Listener de infouno_deal_won.
     * Email de celebración al tenant + webhook al CRM.
     * Registrado en Plugin::boot() con add_action(..., 10, 3).
     */
    public function onDealWon( int $oppId, int $tenantId, ?float $confirmedValue ): void {
        try {
            $transientKey = "infouno_auto_deal_won_{$oppId}";
            if ( get_transient( $transientKey ) ) {
                return;
            }
            set_transient( $transientKey, 1, HOUR_IN_SECONDS );

            $opp = $this->opportunityRepo->getById( $oppId, $tenantId );
            if ( ! $opp ) {
                return;
            }

            $this->dispatcher->sendDealWonEmail( $tenantId, $opp, $confirmedValue );

            $webhookUrl = $this->resolveWebhookUrl( (int) $opp['bot_id'], $tenantId );
            if ( $webhookUrl ) {
                $this->dispatcher->fireWebhook(
                    $webhookUrl,
                    [
                        'event'           => 'deal_won',
                        'opportunity_id'  => $oppId,
                        'tenant_id'       => $tenantId,
                        'confirmed_value' => $confirmedValue,
                        'currency'        => $opp['currency'] ?? 'ARS',
                    ],
                    $tenantId,
                    $oppId,
                    isset( $opp['lead_id'] ) ? (int) $opp['lead_id'] : null,
                    'webhook_deal_won'
                );
            }
        } catch ( \Throwable $e ) {
            error_log( sprintf(
                '[INFOUNO] AutomationEngine::onDealWon error. Opp: %d, Tenant: %d. %s',
                $oppId,
                $tenantId,
                $e->getMessage()
            ) );
        }
    }

    /**
     * Lee y valida webhook_url desde la configuración del bot (settings JSON).
     * Retorna '' si no está configurada o no es una URL válida.
     */
    private function resolveWebhookUrl( int $botId, int $tenantId ): string {
        $bot = $this->botManager->getById( $botId, $tenantId );
        if ( ! $bot ) {
            return '';
        }

        $url = (string) ( $bot['settings']['webhook_url'] ?? '' );

        return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
    }
}
