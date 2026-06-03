<?php

declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Chat\ConversationRepository;

/**
 * Endpoints de gestión de sesión del usuario final del widget.
 *
 * DELETE /infouno/v1/session — Elimina todos los mensajes de la sesión.
 * Implementa el derecho de supresión de datos personales (Art. 16, Ley 25.326 Argentina).
 *
 * El endpoint es público (sin login WP) pero valida:
 *   - bot_token válido y activo
 *   - origin autorizado por el tenant
 *   - session_id bien formado
 */
final class SessionController {

    public function __construct(
        private readonly BotManager            $botManager,
        private readonly ConversationRepository $conversationRepo,
    ) {}

    public function registerRoutes( string $namespace ): void {
        register_rest_route( $namespace, '/session', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'delete' ],
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
            ],
        ] );
    }

    /**
     * Elimina todos los mensajes y conversaciones de una sesión específica.
     * Responde con el número de mensajes eliminados para confirmar la operación.
     */
    public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $botToken  = $request->get_param( 'bot_token' );
        $sessionId = $request->get_param( 'session_id' );
        $origin    = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ?? '' ) );

        // Validar bot y origen — misma lógica que el endpoint de chat
        $bot = $this->botManager->getByPublicToken( $botToken );
        if ( ! $bot ) {
            return new \WP_Error( 'bot_not_found', 'Bot no encontrado.', [ 'status' => 404 ] );
        }

        if ( ! $this->botManager->validateOrigin( $bot, $origin ) ) {
            return new \WP_Error( 'origin_not_allowed', 'Origen no autorizado.', [ 'status' => 403 ] );
        }

        $tenantId = (int) $bot['tenant_id'];
        $deleted  = $this->conversationRepo->deleteSession( $sessionId, $tenantId );

        return new \WP_REST_Response(
            [
                'deleted'  => $deleted,
                'message'  => 'Tus datos de conversación han sido eliminados conforme a la Ley 25.326.',
            ],
            200
        );
    }
}
