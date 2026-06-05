<?php

declare(strict_types=1);

namespace Infouno\SaaS\Security;

/**
 * Capa de defensa del input del usuario final.
 *
 * Responsabilidades:
 *   - Bloquear prompt injection antes de que el mensaje llegue al LLM.
 *   - Rechazar mensajes que superen el límite de caracteres.
 *   - Registrar intentos de ataque (sin datos personales identificables).
 *
 * Guardrail: llm-safety-output.md — "El código del backend debe inspeccionar el prompt entrante".
 */
final class InputGuard {

    public const MAX_MESSAGE_CHARS = 1000;

    /**
     * Patrones de prompt injection de alta confianza en español e inglés.
     * Orientados a chatbots de soporte para PYMEs: priorizan precisión sobre cobertura
     * para evitar falsos positivos con usuarios legítimos.
     *
     * Principio: solo bloquear cuando hay clara intención de manipular las instrucciones
     * del sistema, no frases ambiguas de usuario normal.
     */
    private const INJECTION_PATTERNS = [
        // --- Alta confianza (EN) ---
        '/ignore\s+(all\s+)?(previous|prior|above)\s+instructions?/i',
        '/forget\s+(your|all)\s+(previous\s+)?(instructions?|rules?|system\s+prompt)/i',
        '/\bjailbreak\b/i',
        '/\bDAN\s*(mode|prompt)?\b/i',
        '/show\s+me\s+your\s+(system\s+prompt|instructions?|hidden)/i',
        '/reveal\s+your\s+(system\s+prompt|instructions?|true\s+purpose)/i',
        '/override\s+your\s+(instructions?|programming|safety\s+rules?)/i',
        '/do\s+anything\s+now/i',
        '/disregard\s+(your\s+)?(previous\s+)?(instructions?|training)/i',

        // --- Alta confianza (ES) ---
        '/olvida\s+tus\s+(instrucciones?|reglas?|restricciones?)/i',
        '/ignora\s+(tus\s+|las\s+)?(instrucciones?\s+)?(anteriores?|previas?|del\s+sistema)/i',
        '/(?:mu[eé]stra|mostr[aá])(?:me)?\s+tu\s+(prompt|instrucciones?\s+del?\s+sistema?)/iu',
        '/revela\s+tu\s+(prompt|instrucciones?|configuraci[oó]n\s+interna)/i',
        '/modo\s+(sin\s+restricciones?|sin\s+filtros?|desarrollador)/i',
        '/act[uú]a\s+sin\s+(restricciones?|reglas?|l[ií]mites?)/i',
    ];

    /**
     * Valida y sanitiza el mensaje del usuario.
     * Retorna el mensaje limpio si pasa todas las comprobaciones.
     *
     * @throws \RuntimeException código 400 si el mensaje es inválido.
     * @throws \RuntimeException código 422 si se detecta prompt injection.
     */
    public static function validateMessage( string $message ): string {
        $trimmed = trim( $message );

        if ( '' === $trimmed ) {
            throw new \RuntimeException( 'El mensaje no puede estar vacío.', 400 );
        }

        if ( mb_strlen( $trimmed, 'UTF-8' ) > self::MAX_MESSAGE_CHARS ) {
            throw new \RuntimeException(
                sprintf( 'El mensaje supera el límite de %d caracteres.', self::MAX_MESSAGE_CHARS ),
                400
            );
        }

        if ( self::looksLikeInjection( $trimmed ) ) {
            self::logInjectionAttempt( $trimmed );
            throw new \RuntimeException( 'Mensaje no permitido por las políticas de uso.', 422 );
        }

        return $trimmed;
    }

    private static function looksLikeInjection( string $text ): bool {
        foreach ( self::INJECTION_PATTERNS as $pattern ) {
            if ( preg_match( $pattern, $text ) ) {
                return true;
            }
        }
        return false;
    }

    /** Registra sin datos personales: solo longitud y hash corto para correlacionar patrones. */
    private static function logInjectionAttempt( string $text ): void {
        error_log( sprintf(
            '[INFOUNO-SECURITY] Prompt injection blocked. Length: %d, Hash: %s',
            mb_strlen( $text, 'UTF-8' ),
            substr( hash( 'sha256', $text ), 0, 12 )
        ) );
    }
}
