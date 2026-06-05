<?php
declare(strict_types=1);

namespace Infouno\SaaS\LLM;

/**
 * Estimación heurística de tokens (chars/4, redondeo hacia arriba).
 * Conservadora: se usa para reservar presupuesto de cuota y como respaldo
 * cuando el proveedor no devuelve el conteo real de uso.
 */
final class TokenEstimator {

    private const CHARS_PER_TOKEN = 4;

    /** @return int >= 1 para texto no vacío; 1 para vacío. */
    public static function estimate( string $text ): int {
        $len = mb_strlen( $text, 'UTF-8' );
        return (int) max( 1, (int) ceil( $len / self::CHARS_PER_TOKEN ) );
    }

    /**
     * Suma la estimación del `content` de cada mensaje.
     * @param array<array{role?:string,content?:string}> $messages
     */
    public static function estimateMessages( array $messages ): int {
        $total = 0;
        foreach ( $messages as $m ) {
            $content = (string) ( $m['content'] ?? '' );
            if ( '' !== $content ) {
                $total += self::estimate( $content );
            }
        }
        return $total;
    }
}
