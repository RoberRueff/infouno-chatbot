<?php
declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Channel\ChannelEventRepository;
use Infouno\SaaS\Channel\ChannelRegistry;
use Infouno\SaaS\Channel\ChannelRepository;

/**
 * Endpoint de webhooks de canales: /infouno/v1/channels/{type}/{key}.
 *
 * permission_callback __return_true (igual que /chat): la autorización real es la
 * verificación de firma del canal dentro del handler. Responde 200 de inmediato y
 * delega el procesamiento a Action Scheduler para no exceder el timeout del proveedor.
 */
final class ChannelWebhookController {

    public function __construct(
        private readonly ChannelRegistry        $registry,
        private readonly ChannelRepository      $channelRepo,
        private readonly ChannelEventRepository $eventRepo,
    ) {}

    public function registerRoutes( string $namespace ): void {
        register_rest_route( $namespace, '/channels/(?P<type>[a-z]+)/(?P<key>[A-Za-z0-9_\-]+)', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => '__return_true',
        ] );

        // Handshake GET de verificación (Meta/WhatsApp). Mismo patrón, método READABLE.
        register_rest_route( $namespace, '/channels/(?P<type>[a-z]+)/(?P<key>[A-Za-z0-9_\-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handleChallenge' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handleChallenge( \WP_REST_Request $request ): \WP_REST_Response {
        $type       = (string) $request->get_param( 'type' );
        $routingKey = (string) $request->get_param( 'key' );

        if ( ! $this->registry->has( $type ) ) {
            return new \WP_REST_Response( [ 'ok' => false ], 404 );
        }
        $channel = $this->channelRepo->resolveByRoutingKey( $routingKey );
        if ( null === $channel || (string) $channel['channel_type'] !== $type ) {
            return new \WP_REST_Response( [ 'ok' => false ], 404 );
        }

        $adapter = $this->registry->get( $type );
        if ( ! $adapter instanceof \Infouno\SaaS\Channel\WebhookChallengeInterface ) {
            return new \WP_REST_Response( [ 'ok' => false ], 404 );
        }

        $challenge = $adapter->verifyChallenge( $request, $channel );
        if ( null === $challenge ) {
            return new \WP_REST_Response( [ 'ok' => false ], 403 );
        }

        // Meta espera el challenge crudo como body. WP_REST_Response con un string
        // lo serializa como JSON ("C-99"); Meta acepta el match exacto del valor.
        $response = new \WP_REST_Response( $challenge, 200 );
        $response->header( 'Content-Type', 'text/plain; charset=UTF-8' );
        return $response;
    }

    public function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $type       = (string) $request->get_param( 'type' );
        $routingKey = (string) $request->get_param( 'key' );

        if ( ! $this->registry->has( $type ) ) {
            return new \WP_REST_Response( [ 'ok' => false ], 404 );
        }

        $channel = $this->channelRepo->resolveByRoutingKey( $routingKey );
        if ( null === $channel || (string) $channel['channel_type'] !== $type ) {
            return new \WP_REST_Response( [ 'ok' => false ], 404 );
        }

        $adapter = $this->registry->get( $type );

        // Verificación de firma del proveedor — autorización real del endpoint.
        if ( ! $adapter->verifyWebhook( $request, $channel ) ) {
            error_log( sprintf( '[INFOUNO-CHANNEL] Firma inválida en webhook %s (canal %d).', $type, (int) $channel['id'] ) );
            return new \WP_REST_Response( [ 'ok' => false ], 403 );
        }

        $payload = (array) $request->get_json_params();
        $inbound = $adapter->parseInbound( $payload );

        // Sin mensaje procesable (status, receipts, eventos no-mensaje): nada que encolar.
        if ( null === $inbound ) {
            return new \WP_REST_Response( [ 'ok' => true, 'ignored' => true ], 200 );
        }

        // Idempotencia: descartar retries del proveedor.
        if ( ! $this->eventRepo->markIfNew( (int) $channel['id'], $type, $inbound->externalMsgId ) ) {
            return new \WP_REST_Response( [ 'ok' => true, 'dup' => true ], 200 );
        }

        // Encolar el procesamiento en background. Ack inmediato al proveedor.
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            // Args POSICIONALES: Action Scheduler los expande como parámetros del hook
            // (do_action_ref_array). Un array asociativo se rompería en PHP 8 (named args).
            as_enqueue_async_action(
                'infouno_process_inbound',
                [ (int) $channel['id'], $payload ],
                'infouno-channels'
            );
        }

        return new \WP_REST_Response( [ 'ok' => true ], 200 );
    }
}
