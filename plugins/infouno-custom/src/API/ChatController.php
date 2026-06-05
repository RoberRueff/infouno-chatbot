<?php

declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Chat\ChatService;

/**
 * Endpoint SSE de chat — /infouno/v1/chat.
 *
 * Flujo de seguridad:
 *   1. WP REST valida tipos y longitud de args.
 *   2. preValidate() resuelve y verifica bot + origen ANTES de abrir SSE.
 *      → Devuelve el array del bot para evitar una segunda query en ChatService.
 *   3. Solo si pasa, se emiten headers SSE (HTTP 200).
 *   4. ChatService recibe el bot ya resuelto y completa el resto del pipeline.
 */
final class ChatController {

    private const MAX_MESSAGE_CHARS = 1000;

    private const ERROR_MESSAGES = [
        400 => 'Solicitud inválida.',
        402 => 'Cuota o límite de conversación agotado. Iniciá una nueva conversación o contactá al administrador.',
        403 => 'Acceso no autorizado.',
        404 => 'Bot no encontrado o inactivo.',
        422 => 'Mensaje no permitido por las políticas de uso.',
        429 => 'Demasiadas peticiones. Por favor, esperá un momento.',
        503 => 'Servicio de IA temporalmente no disponible.',
    ];

    public function __construct(
        private readonly ChatService $chatService,
        private readonly BotManager  $botManager,
    ) {}

    public function registerRoutes( string $namespace ): void {
        register_rest_route( $namespace, '/chat', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'stream' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'bot_token' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn( $v ) => strlen( $v ) === 64,
                ],
                'session_id' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn( $v ) => strlen( trim( $v ) ) >= 8,
                ],
                'message' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                    'validate_callback' => static fn( $v ) => (
                        strlen( trim( $v ) ) >= 1 &&
                        mb_strlen( $v ) <= self::MAX_MESSAGE_CHARS
                    ),
                ],
            ],
        ] );
    }

    public function stream( \WP_REST_Request $request ): ?\WP_Error {
        $botToken  = $request->get_param( 'bot_token' );
        $sessionId = $request->get_param( 'session_id' );
        $message   = $request->get_param( 'message' );
        $origin    = $this->resolveOrigin();

        // Pre-validación antes de abrir SSE.
        // Devuelve el bot resuelto para no repetir la query en ChatService.
        $result = $this->preValidate( $botToken, $origin );
        if ( $result instanceof \WP_Error ) {
            return $result;
        }

        $this->initSSE( $origin );

        try {
            $this->chatService->handle(
                $result,   // bot ya resuelto y validado
                $sessionId,
                $message,
                $origin,
                static function ( string $delta ) {
                    if ( connection_aborted() ) {
                        return;
                    }
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo 'data: ' . wp_json_encode( [ 'delta' => $delta ] ) . "\n\n";
                    ob_flush();
                    flush();
                }
            );

            $this->sendEvent( 'done', [ 'status' => 'complete' ] );

        } catch ( \RuntimeException $e ) {
            $this->logSecurityEvent( $e );
            $this->sendEvent( 'error', [
                'code'    => $e->getCode(),
                'message' => $this->safeErrorMessage( $e ),
            ] );
        }

        exit();
    }

    /**
     * Resuelve y valida el bot + origen antes de abrir el stream SSE.
     * Retorna el array del bot para reutilizarlo en ChatService sin query adicional.
     *
     * @return array<string,mixed>|\WP_Error
     */
    private function preValidate( string $botToken, string $origin ): array|\WP_Error {
        $bot = $this->botManager->getByPublicToken( $botToken );

        if ( ! $bot ) {
            return new \WP_Error( 'bot_not_found', 'Bot no encontrado o inactivo.', [ 'status' => 404 ] );
        }

        if ( ! $this->botManager->validateOrigin( $bot, $origin ) ) {
            error_log( sprintf(
                '[INFOUNO-SECURITY] Unauthorized origin "%s" for bot %d.',
                $origin,
                (int) ( $bot['id'] ?? 0 )
            ) );
            return new \WP_Error( 'origin_not_allowed', 'Origen no autorizado.', [ 'status' => 403 ] );
        }

        return $bot;
    }

    private function initSSE( string $origin = '' ): void {
        set_time_limit( 0 );

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        // El widget vive en el dominio del cliente: la respuesta SSE bypasea
        // el flujo normal de WP REST, por lo que las cabeceras CORS que WP
        // añadiría en rest_pre_serve_request nunca se emiten. Hay que enviarlas
        // aquí o el navegador bloquea la lectura del stream cross-origin.
        // La autorización real del origen ya la hizo preValidate()/validateOrigin;
        // esta cabecera solo concede al navegador permiso de lectura.
        if ( '' !== $origin ) {
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Vary: Origin' );
        } else {
            header( 'Access-Control-Allow-Origin: *' );
        }

        header( 'Content-Type: text/event-stream; charset=UTF-8' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' );
        header( 'Connection: keep-alive' );

        echo ": stream-start\n\n";
        ob_flush();
        flush();
    }

    private function sendEvent( string $event, array $data ): void {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo 'event: ' . $event . "\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo 'data: ' . wp_json_encode( $data ) . "\n\n";
        ob_flush();
        flush();
    }

    private function safeErrorMessage( \RuntimeException $e ): string {
        return self::ERROR_MESSAGES[ $e->getCode() ] ?? 'Error interno. Por favor, intentalo de nuevo.';
    }

    private function logSecurityEvent( \RuntimeException $e ): void {
        if ( ! array_key_exists( $e->getCode(), self::ERROR_MESSAGES ) ) {
            error_log( sprintf(
                '[INFOUNO-ERROR] Unexpected chat error. Code: %d, Message: %s',
                $e->getCode(),
                $e->getMessage()
            ) );
        }
    }

    private function resolveOrigin(): string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        return sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ?? '' ) );
    }
}
