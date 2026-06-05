<?php

declare(strict_types=1);

namespace Infouno\SaaS\Bot;

/**
 * Rate limiting en dos capas: por sesión (evasible por el cliente) y por IP (no evasible).
 * Ambas deben pasar para procesar un mensaje.
 *
 * Guardrail financiero: si se elimina alguna capa, emitir alerta.
 * Ver guardrails/api-protection.md.
 */
final class QuotaService {

    /** Capa 1 (por sesión): el cliente controla session_id — límite conservador. */
    private const MAX_PER_SESSION  = 5;
    /** Capa 2 (por IP): el cliente no controla su IP — límite más generoso. */
    private const MAX_PER_IP       = 30;
    private const WINDOW_SECONDS   = 60;

    /**
     * Verifica ambas capas de rate limit.
     * La capa de IP protege contra rotación de session_id.
     *
     * @param string      $sessionId     Clave de sesión (web: session_id; canal: "tg:<chatid>").
     * @param string|null $secondaryRaw  Clave secundaria explícita (canal: "tipo:external_user").
     *                                   null = flujo web → usa la IP del cliente (sin cambios).
     * @throws \RuntimeException con código 429 si algún límite está alcanzado.
     */
    public function checkRateLimit( string $sessionId, ?string $secondaryRaw = null ): void {
        $this->checkLayer( $this->sessionKey( $sessionId ), self::MAX_PER_SESSION );
        $this->checkLayer( $this->resolveSecondaryKey( $secondaryRaw ), self::MAX_PER_IP );
    }

    /**
     * Incrementa los contadores de ambas capas.
     * Llamar después de validar y antes de enviar al LLM.
     *
     * @param string      $sessionId     Clave de sesión.
     * @param string|null $secondaryRaw  Clave secundaria explícita (canal) o null (web → IP).
     */
    public function increment( string $sessionId, ?string $secondaryRaw = null ): void {
        $this->incrementKey( $this->sessionKey( $sessionId ) );
        $this->incrementKey( $this->resolveSecondaryKey( $secondaryRaw ) );
    }

    /** Segundos restantes de la ventana de sesión activa, o 0 si no hay ventana. */
    public function getRemainingWindow( string $sessionId ): int {
        return max( 0, $this->getTransientExpiry( $this->sessionKey( $sessionId ) ) );
    }

    private function checkLayer( string $key, int $limit ): void {
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            throw new \RuntimeException(
                'Límite de velocidad alcanzado. Por favor, espera un momento.',
                429
            );
        }
    }

    private function incrementKey( string $key ): void {
        $count = (int) get_transient( $key );
        if ( 0 === $count ) {
            set_transient( $key, 1, self::WINDOW_SECONDS );
        } else {
            set_transient( $key, $count + 1, self::WINDOW_SECONDS );
        }
    }

    private function sessionKey( string $sessionId ): string {
        return 'infouno_rl_s_' . substr( hash( 'sha256', $sessionId ), 0, 16 );
    }

    /**
     * Resuelve la IP real del cliente con orden de confianza decreciente.
     *
     * Prioridad:
     *   1. CF-Connecting-IP  (Cloudflare — no falsificable desde el cliente)
     *   2. X-Real-IP         (Nginx reverse proxy — configurado por el sysadmin)
     *   3. REMOTE_ADDR       (conexión TCP directa — siempre confiable)
     *
     * X-Forwarded-For NO se usa como fuente principal porque cualquier cliente
     * puede añadir IPs falsas al inicio de la cadena.
     */
    private function ipKey(): string {
        $ip = '';

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } else {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }

        return 'infouno_rl_ip_' . substr( hash( 'sha256', trim( (string) $ip ) ), 0, 16 );
    }

    private function getTransientExpiry( string $key ): int {
        $timeoutKey = '_transient_timeout_' . $key;
        $expiry     = (int) get_option( $timeoutKey, 0 );
        return max( 0, $expiry - time() );
    }

    /**
     * Clave de la capa 2: si no se provee una clave secundaria explícita (flujo web),
     * se usa la IP real. En canales se pasa "tipo:external_user" — no hay IP del usuario.
     */
    private function resolveSecondaryKey( ?string $secondaryRaw ): string {
        if ( null === $secondaryRaw || '' === $secondaryRaw ) {
            return $this->ipKey();
        }
        return 'infouno_rl_x_' . substr( hash( 'sha256', $secondaryRaw ), 0, 16 );
    }
}
