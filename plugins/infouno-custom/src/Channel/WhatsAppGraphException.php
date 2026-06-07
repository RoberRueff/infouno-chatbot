<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Excepción tipada para errores de la Graph API de Meta.
 * $retryable=true → Action Scheduler puede reintentar (re-lanzar).
 * $retryable=false → error permanente, abandonar y loguear.
 */
final class WhatsAppGraphException extends \RuntimeException {

    /**
     * Códigos de error de Meta clasificados como permanentes (no reintentables).
     * 131047 = mensaje fuera de ventana de 24h / re-engagement sin template.
     * 131026 = número no entregable (no existe, bloqueado, etc.).
     * 100    = parámetro inválido en la request.
     */
    private const PERMANENT_CODES = [ 131047, 131026, 100 ];

    public function __construct(
        string                $message,
        int                   $httpStatus,
        private readonly int  $graphErrorCode,
        private readonly bool $retryable,
    ) {
        parent::__construct( $message, $httpStatus );
    }

    public function graphCode(): int {
        return $this->graphErrorCode;
    }

    public function isRetryable(): bool {
        return $this->retryable;
    }

    /** @param array<string,mixed> $errorBody Body JSON ya decodificado. */
    public static function fromGraphError( int $httpStatus, array $errorBody ): self {
        $error     = is_array( $errorBody['error'] ?? null ) ? $errorBody['error'] : [];
        $graphCode = (int) ( $error['code'] ?? 0 );
        $message   = (string) ( $error['message'] ?? 'Graph API error' );

        // HTTP 429 o 5xx → siempre transitorio.
        if ( 429 === $httpStatus || $httpStatus >= 500 ) {
            return new self( $message, $httpStatus, $graphCode, true );
        }

        // Códigos permanentes conocidos → no reintentable.
        $permanent = in_array( $graphCode, self::PERMANENT_CODES, true );

        return new self( $message, $httpStatus, $graphCode, ! $permanent );
    }
}
