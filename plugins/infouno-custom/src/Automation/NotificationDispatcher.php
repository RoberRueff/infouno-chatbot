<?php

declare(strict_types=1);

namespace Infouno\SaaS\Automation;

use Infouno\SaaS\Opportunity\OpportunityRepository;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Entrega notificaciones del pipeline: emails vía wp_mail y webhooks vía wp_remote_post.
 *
 * Reglas:
 *   - Toda acción (email o webhook) se registra en wp_infouno_automation_logs.
 *   - Webhooks: timeout 5s, fire-and-forget, status 'ok'/'failed' según código HTTP.
 *   - PII del lead NUNCA incluida en el payload de webhook (Ley 25.326 guardrail).
 *   - recipient_hash (MD5 parcial) en logs — no el email en claro.
 */
final class NotificationDispatcher {

    public function __construct(
        private readonly TenantManager         $tenantManager,
        private readonly OpportunityRepository $opportunityRepo,
    ) {}

    /** Email al tenant cuando se crea una nueva oportunidad en su pipeline. */
    public function sendOpportunityCreatedEmail( int $tenantId, array $opp ): void {
        $recipient = $this->resolveTenantEmail( $tenantId );
        if ( ! $recipient ) {
            return;
        }

        $oppId    = (int) $opp['id'];
        $currency = $opp['currency'] ?? 'ARS';
        $valueLine = isset( $opp['estimated_value'] )
            ? '  Valor estimado:  $ ' . number_format( (float) $opp['estimated_value'], 2, ',', '.' ) . " {$currency}\n"
            : '';

        $subject = sprintf( '[InfoUno] 🚀 Nueva oportunidad #%d en tu pipeline', $oppId );

        $body = sprintf(
            "Se creó una nueva oportunidad desde un lead calificado.\n\n" .
            "──────────────────────────────\n" .
            "OPORTUNIDAD #%d\n" .
            "──────────────────────────────\n" .
            "  Stage:           nuevo\n" .
            "%s" .
            "──────────────────────────────\n\n" .
            "Gestionala desde tu panel:\n%s\n\n" .
            "— InfoUno\nhttps://infouno.com",
            $oppId,
            $valueLine,
            admin_url( 'admin.php?page=infouno-dashboard' )
        );

        $sent = wp_mail( $recipient, $subject, $body );

        $this->opportunityRepo->logAutomation(
            $tenantId,
            'email_opportunity_created',
            $sent ? 'ok' : 'failed',
            $oppId,
            isset( $opp['lead_id'] ) ? (int) $opp['lead_id'] : null,
            [ 'recipient_hash' => substr( md5( $recipient ), 0, 8 ) ]
        );
    }

    /** Email al tenant cuando cierra una venta (deal won). */
    public function sendDealWonEmail( int $tenantId, array $opp, ?float $confirmedValue ): void {
        $recipient = $this->resolveTenantEmail( $tenantId );
        if ( ! $recipient ) {
            return;
        }

        $oppId    = (int) $opp['id'];
        $currency = $opp['currency'] ?? 'ARS';
        $value    = $confirmedValue ?? ( isset( $opp['estimated_value'] ) ? (float) $opp['estimated_value'] : null );

        $valueLine = $value !== null
            ? '  Valor confirmado: $ ' . number_format( $value, 2, ',', '.' ) . " {$currency}\n"
            : '';

        $subject = sprintf( '[InfoUno] 🎉 ¡Deal ganado! Oportunidad #%d cerrada', $oppId );

        $body = sprintf(
            "¡Felicitaciones! Cerraste una venta a través de InfoUno.\n\n" .
            "──────────────────────────────\n" .
            "DEAL WON — OPORTUNIDAD #%d\n" .
            "──────────────────────────────\n" .
            "%s" .
            "──────────────────────────────\n\n" .
            "Revisá el pipeline en tu panel:\n%s\n\n" .
            "— InfoUno\nhttps://infouno.com",
            $oppId,
            $valueLine,
            admin_url( 'admin.php?page=infouno-dashboard' )
        );

        $sent = wp_mail( $recipient, $subject, $body );

        $this->opportunityRepo->logAutomation(
            $tenantId,
            'email_deal_won',
            $sent ? 'ok' : 'failed',
            $oppId,
            isset( $opp['lead_id'] ) ? (int) $opp['lead_id'] : null,
            [ 'confirmed_value' => $value, 'recipient_hash' => substr( md5( $recipient ), 0, 8 ) ]
        );
    }

    /**
     * Dispara un webhook HTTP al CRM del tenant.
     *
     * Timeout de 5s — fire-and-forget, no bloquea el pipeline.
     * El resultado (ok/failed + código HTTP) se registra en automation_logs.
     *
     * @param array<string, mixed> $payload  Solo datos de oportunidad — SIN PII del lead.
     */
    public function fireWebhook(
        string $url,
        array  $payload,
        int    $tenantId,
        ?int   $oppId,
        ?int   $leadId,
        string $actionType
    ): bool {
        $response = wp_remote_post( $url, [
            'timeout'  => 5,
            'blocking' => true,
            'headers'  => [
                'Content-Type'     => 'application/json',
                'X-Infouno-Event'  => $payload['event'] ?? 'unknown',
                'X-Infouno-Tenant' => (string) $tenantId,
            ],
            'body'     => wp_json_encode( $payload ),
        ] );

        $isError      = is_wp_error( $response );
        $responseCode = $isError ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $ok           = ! $isError && $responseCode >= 200 && $responseCode < 400;

        $this->opportunityRepo->logAutomation(
            $tenantId,
            $actionType,
            $ok ? 'ok' : 'failed',
            $oppId,
            $leadId,
            [
                'url'           => $url,
                'response_code' => $responseCode,
                'error'         => $isError ? $response->get_error_message() : null,
            ]
        );

        return $ok;
    }

    /** Resuelve el email del owner del tenant. Retorna '' si no está disponible. */
    private function resolveTenantEmail( int $tenantId ): string {
        $tenant = $this->tenantManager->getById( $tenantId );
        if ( ! $tenant ) {
            return '';
        }

        $user = get_userdata( (int) $tenant['user_id'] );

        return ( $user && $user->user_email ) ? $user->user_email : '';
    }
}
