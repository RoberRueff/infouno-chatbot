<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Telemetría de modo de entrega web. Solo se loguean los requests servidos en
 * modo 'full' (fallback): un pico de 'full' desde un origen señala que ese
 * hosting bufferea SSE. Insumo para decidir si el polling async vale la pena.
 */
final class DeliveryTelemetry {

    public static function logLine( string $mode, int $botId ): string {
        return sprintf( '[INFOUNO-DELIVERY] mode=%s bot=%d', $mode, $botId );
    }
}
