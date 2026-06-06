<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Value object de un recibo de estado de la Graph API de Meta.
 * Representa un item de `statuses[]` en el payload del webhook.
 */
final class WhatsAppStatusEvent {

    private const KNOWN = [ 'sent', 'delivered', 'read', 'failed' ];

    public function __construct(
        public readonly string  $wamid,
        public readonly string  $status,
        public readonly string  $recipientPhone,
        public readonly ?int    $errorCode,
    ) {}

    /** @param array<string,mixed> $raw Un item de statuses[]. */
    public static function fromStatusArray( array $raw ): self {
        $errorCode = null;
        $errors    = is_array( $raw['errors'] ?? null ) ? $raw['errors'] : [];
        if ( ! empty( $errors ) ) {
            $errorCode = isset( $errors[0]['code'] ) ? (int) $errors[0]['code'] : null;
        }

        return new self(
            wamid:          (string) ( $raw['id']           ?? '' ),
            status:         (string) ( $raw['status']       ?? '' ),
            recipientPhone: (string) ( $raw['recipient_id'] ?? '' ),
            errorCode:      $errorCode,
        );
    }

    public static function isKnownStatus( string $status ): bool {
        return in_array( $status, self::KNOWN, true );
    }
}
