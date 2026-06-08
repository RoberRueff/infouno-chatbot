<?php

declare(strict_types=1);

namespace Infouno\SaaS\Lead;

/**
 * Orquesta el pipeline de captura de leads:
 *   1. Verifica consentimiento granular por campo PII (Ley 25.326).
 *   2. Analiza el mensaje con LeadScorer (extracción + score).
 *   3. Guarda o actualiza el lead en LeadRepository.
 *   4. Despacha el hook 'infouno_lead_captured' para notificaciones externas.
 *
 * Todo SQL delega a LeadRepository — este servicio no usa $wpdb directamente.
 * Este servicio es no-crítico: cualquier fallo debe silenciarse en el caller
 * para no interrumpir el flujo del chat.
 */
final class LeadService {

    public function __construct(
        private readonly LeadScorer     $scorer,
        private readonly LeadRepository $repository,
    ) {}

    /**
     * Procesa un mensaje de chat en busca de datos de lead.
     * Solo actúa si el usuario consintió la captura de al menos un campo PII.
     *
     * @param array<array{role:string,content:string}> $conversationHistory
     */
    public function processMessage(
        int    $tenantId,
        int    $botId,
        string $sessionId,
        int    $conversationId,
        string $userMessage,
        array  $conversationHistory = []
    ): void {
        $consents = $this->repository->getConsentsForSession( $sessionId, $botId );

        if ( empty( $consents ) ) {
            return;
        }

        $result    = $this->scorer->analyze( $userMessage, $conversationHistory );
        $extracted = $result['extracted'];

        // No guardar si no hay score relevante ni ningún dato PII extraído
        $hasPii = $extracted['email'] || $extracted['phone'] || $extracted['name'];
        if ( ! $result['is_qualified'] && ! $hasPii ) {
            return;
        }

        $toSave = [
            'tenant_id'       => $tenantId,
            'bot_id'          => $botId,
            'conversation_id' => $conversationId,
            'session_hash'    => hash( 'sha256', $sessionId ),
            'interest'        => $extracted['interest'],
            'score'           => $result['score'],
            'temperature'     => $result['temperature'],
            'intent_signals'  => $result['intent_signals'],
            'source'          => 'chat',
        ];

        // Solo incluye cada campo si el usuario consintió ese tipo de dato
        if ( $extracted['name'] && ! empty( $consents['can_capture_name'] ) ) {
            $toSave['name'] = $extracted['name'];
        }
        if ( $extracted['phone'] && ! empty( $consents['can_capture_phone'] ) ) {
            $toSave['phone'] = $extracted['phone'];
        }
        if ( $extracted['email'] && ! empty( $consents['can_capture_email'] ) ) {
            $toSave['email'] = $extracted['email'];
        }

        $leadId = $this->repository->save( $toSave );

        // Notifica solo en leads calificados (score >= 60)
        if ( $result['is_qualified'] && $leadId > 0 ) {
            do_action( 'infouno_lead_captured', $leadId, $tenantId, $botId, $result );
        }
    }
}
