<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Canales que requieren un handshake de verificación GET (p.ej. Meta/WhatsApp).
 * Interfaz segregada: solo la implementan los adapters que la necesitan; el
 * webhook controller la consulta vía instanceof. Telegram no la implementa.
 */
interface WebhookChallengeInterface {

    /**
     * Verifica el GET de suscripción del proveedor.
     * @param array<string,mixed> $channel Fila de wp_infouno_channels (con credentials_decrypted).
     * @return string|null El valor a devolver tal cual (challenge) si es válido; null si no.
     */
    public function verifyChallenge( \WP_REST_Request $request, array $channel ): ?string;
}
