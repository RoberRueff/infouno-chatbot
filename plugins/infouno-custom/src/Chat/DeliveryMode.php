<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Resuelve el modo de entrega de la respuesta del chat web desde el request.
 * 'full' = respuesta JSON completa (fallback anti-buffering); 'sse' = streaming.
 */
final class DeliveryMode {

    public const SSE  = 'sse';
    public const FULL = 'full';

    public static function fromRequest( ?string $param ): string {
        return self::FULL === $param ? self::FULL : self::SSE;
    }
}
