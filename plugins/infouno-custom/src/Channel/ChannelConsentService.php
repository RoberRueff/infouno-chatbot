<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Consentimiento por primer mensaje (Ley 25.326) para canales sociales.
 *
 * En el primer contacto de un usuario de canal registra evidencia server-side
 * en consents (uso del chat) y lead_consents (captura PII granular). El modelo
 * legal elegido es "continuar la conversación = aceptación": el dispatcher envía
 * el aviso legal + link a la política como mensaje de bienvenida.
 */
final class ChannelConsentService {

    private const CONSENT_VERSION = '1.0';

    /** Para canales el consentimiento por primer mensaje habilita los 3 campos PII. */
    private const CAPTURE_NAME  = 1;
    private const CAPTURE_PHONE = 1;
    private const CAPTURE_EMAIL = 1;

    /**
     * Asegura el consentimiento para una sesión de canal.
     * @return bool true si es primer contacto (recién registrado) — el caller debe enviar la bienvenida legal.
     */
    public function ensure( int $tenantId, int $botId, string $channel, string $conversationKey ): bool {
        global $wpdb;

        $sessionHash    = hash( 'sha256', $conversationKey );
        $consentsTable  = $wpdb->prefix . 'infouno_consents';
        $leadConsents   = $wpdb->prefix . 'infouno_lead_consents';

        // ¿Ya existe consentimiento de chat para esta sesión de canal?
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$consentsTable}`
                 WHERE session_hash = %s AND tenant_id = %d AND scope = 'chat'",
                $sessionHash,
                $tenantId
            )
        );

        if ( $existing > 0 ) {
            return false;
        }

        $now = gmdate( 'Y-m-d H:i:s' );

        // Evidencia de consentimiento de uso del chat (sin IP en canales).
        $wpdb->insert(
            $consentsTable,
            [
                'bot_id'          => $botId,
                'tenant_id'       => $tenantId,
                'session_hash'    => $sessionHash,
                'consent_version' => self::CONSENT_VERSION,
                'scope'           => 'chat',
                'channel'         => $channel,
                'ip_hash'         => '',
                'user_agent_hash' => '',
                'accepted_at'     => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        // Consentimiento granular PII (por primer mensaje).
        $wpdb->insert(
            $leadConsents,
            [
                'tenant_id'         => $tenantId,
                'bot_id'            => $botId,
                'session_hash'      => $sessionHash,
                'can_capture_name'  => self::CAPTURE_NAME,
                'can_capture_phone' => self::CAPTURE_PHONE,
                'can_capture_email' => self::CAPTURE_EMAIL,
                'consent_version'   => self::CONSENT_VERSION,
                'ip_hash'           => '',
                'user_agent_hash'   => '',
                'accepted_at'       => $now,
            ],
            [ '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        return true;
    }
}
