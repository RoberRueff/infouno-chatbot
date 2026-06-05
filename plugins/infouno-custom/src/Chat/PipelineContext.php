<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Contexto de transporte para una ejecución del pipeline.
 * 'web' usa la IP como clave secundaria de rate-limit; los canales usan external_user.
 */
final class PipelineContext {

    public function __construct(
        public readonly string  $channel = 'web',
        public readonly ?string $externalUser = null,
        public readonly ?string $rateLimitSecondaryKey = null,
    ) {}

    public static function web(): self {
        return new self( 'web', null, null );
    }

    /** secondaryKey = external_user para que un usuario de canal no evada el rate limit. */
    public static function forChannel( string $channel, string $externalUser ): self {
        return new self( $channel, $externalUser, $channel . ':' . $externalUser );
    }
}
