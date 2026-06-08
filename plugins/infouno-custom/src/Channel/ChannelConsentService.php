<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

use Infouno\SaaS\Persistence\ConsentRepository;

/**
 * Consentimiento por primer mensaje (Ley 25.326) para canales sociales.
 *
 * En el primer contacto de un usuario de canal registra evidencia server-side
 * en consents (uso del chat) y lead_consents (captura PII granular). El modelo
 * legal elegido es "continuar la conversación = aceptación": el dispatcher envía
 * el aviso legal + link a la política como mensaje de bienvenida.
 *
 * Todo el SQL vive en ConsentRepository (capa de persistencia fail-closed).
 */
final class ChannelConsentService {

    private const CONSENT_VERSION = '1.0';

    public function __construct(
        private readonly ConsentRepository $consentRepo,
    ) {}

    /**
     * Asegura el consentimiento para una sesión de canal.
     * @return bool true si es primer contacto (recién registrado) — el caller debe enviar la bienvenida legal.
     */
    public function ensure( int $tenantId, int $botId, string $channel, string $conversationKey ): bool {
        $sessionHash = hash( 'sha256', $conversationKey );

        // ¿Ya existe consentimiento de chat para esta sesión de canal? (scope por tenant).
        if ( $this->consentRepo->consentExistsByTenant( $tenantId, $sessionHash, 'chat' ) ) {
            return false;
        }

        // Evidencia de consentimiento de uso del chat (sin IP/UA en canales).
        $this->consentRepo->recordConsentRow(
            $tenantId,
            $botId,
            $sessionHash,
            'chat',
            self::CONSENT_VERSION,
            '',
            '',
            $channel,
        );

        // Consentimiento granular PII (por primer mensaje habilita los 3 campos).
        $this->consentRepo->recordLeadConsentRow(
            $tenantId,
            $botId,
            $sessionHash,
            true,
            true,
            true,
            self::CONSENT_VERSION,
            '',
            '',
            true,
        );

        return true;
    }
}
