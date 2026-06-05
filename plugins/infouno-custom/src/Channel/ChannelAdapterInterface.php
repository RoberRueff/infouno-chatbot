<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Contrato de un canal social. Cada proveedor (Telegram, WhatsApp, Meta)
 * implementa esta interfaz; el resto del sistema es agnóstico del proveedor.
 */
interface ChannelAdapterInterface {

    /** Identificador del canal: 'telegram' | 'whatsapp' | 'instagram' | 'messenger'. */
    public function type(): string;

    /**
     * Verifica que el webhook entrante es legítimo (firma/secreto del proveedor).
     * @param array<string,mixed> $channel Fila de wp_infouno_channels.
     */
    public function verifyWebhook( \WP_REST_Request $request, array $channel ): bool;

    /**
     * Normaliza el payload del webhook a un InboundMessage, o null si no es
     * un mensaje de texto que debamos procesar.
     * @param array<string,mixed> $payload
     */
    public function parseInbound( array $payload ): ?InboundMessage;

    /**
     * Envía un mensaje de texto al usuario del canal.
     * @param array<string,mixed> $channel Fila de wp_infouno_channels (credentials cifradas).
     */
    public function send( array $channel, string $externalUser, string $text ): void;

    /** Trocea el texto respetando el límite de caracteres del canal. @return string[] */
    public function splitMessage( string $text ): array;
}
