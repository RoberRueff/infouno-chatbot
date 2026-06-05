<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Mensaje entrante normalizado, independiente del proveedor.
 * conversationKey() produce el session_id sintético usado por ConversationRepository.
 */
final class InboundMessage {

    /** Prefijos cortos de session_id por canal. */
    private const PREFIX = [
        'telegram'  => 'tg',
        'whatsapp'  => 'wa',
        'instagram' => 'ig',
        'messenger' => 'fb',
    ];

    public function __construct(
        public readonly string $channelType,
        public readonly string $externalUser,
        public readonly string $text,
        public readonly string $externalMsgId,
    ) {}

    public function conversationKey(): string {
        $prefix = self::PREFIX[ $this->channelType ] ?? $this->channelType;
        return $prefix . ':' . $this->externalUser;
    }
}
