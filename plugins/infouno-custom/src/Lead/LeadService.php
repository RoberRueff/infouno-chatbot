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
        $consents = $this->getConsents( $sessionId, $botId );

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

    /**
     * Recupera los flags de consentimiento granular de una sesión en una sola query.
     * Evita las 3 queries separadas de hasConsent() cuando se procesan mensajes.
     *
     * @return array{can_capture_name: int, can_capture_phone: int, can_capture_email: int}|array{}
     */
    private function getConsents( string $sessionId, int $botId ): array {
        global $wpdb;

        $sessionHash = hash( 'sha256', $sessionId );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT can_capture_name, can_capture_phone, can_capture_email
                 FROM `{$wpdb->prefix}infouno_lead_consents`
                 WHERE session_hash = %s AND bot_id = %d
                 ORDER BY accepted_at DESC
                 LIMIT 1",
                $sessionHash,
                $botId
            ),
            ARRAY_A
        );

        // Si no hay registro o ningún campo fue consentido, retorna array vacío
        if ( ! $row ) {
            return [];
        }

        $hasAny = (int) $row['can_capture_name']
                + (int) $row['can_capture_phone']
                + (int) $row['can_capture_email'];

        return $hasAny > 0 ? $row : [];
    }
}
