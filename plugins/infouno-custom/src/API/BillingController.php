<?php
declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Billing\BillingConfig;
use Infouno\SaaS\Billing\SubscriptionService;
use Infouno\SaaS\Billing\WebhookSignatureVerifier;
use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Endpoints de billing (MercadoPago Suscripciones). Sin SQL crudo — delega.
 */
final class BillingController {

    public function __construct(
        private readonly TenantManager            $tenantManager,
        private readonly SubscriptionService      $service,
        private readonly WebhookSignatureVerifier $verifier,
        private readonly SubscriptionRepository   $repository,
        private readonly BillingConfig            $config,
    ) {}

    public function registerRoutes( string $namespace ): void {
        register_rest_route( $namespace, '/billing/subscribe', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'subscribe' ],
            'permission_callback' => [ $this, 'requireLogin' ],
        ] );
        register_rest_route( $namespace, '/billing/webhook', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'webhook' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $namespace, '/billing/subscription', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'subscription' ],
            'permission_callback' => [ $this, 'requireLogin' ],
        ] );
        register_rest_route( $namespace, '/billing/cancel', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'cancel' ],
            'permission_callback' => [ $this, 'requireLogin' ],
        ] );
    }

    public function requireLogin(): bool {
        return is_user_logged_in();
    }

    public function subscribe( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant = $this->tenantManager->requireForCurrentUser();
        $email  = $this->ownerEmail( (int) ( $tenant['user_id'] ?? 0 ) );

        try {
            $initPoint = $this->service->createSubscription( $tenant, $email, $this->config->premiumPriceArs() );
        } catch ( \RuntimeException $e ) {
            if ( 'already_subscribed' === $e->getMessage() ) {
                return new \WP_Error( 'already_subscribed', 'Ya tenés una suscripción activa.', [ 'status' => 409 ] );
            }
            return new \WP_Error( 'subscribe_failed', $e->getMessage(), [ 'status' => 500 ] );
        }

        return new \WP_REST_Response( [ 'init_point' => $initPoint ], 200 );
    }

    public function webhook( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params();
        if ( empty( $body ) ) {
            $body = [
                'type'  => $request->get_param( 'type' ),
                'topic' => $request->get_param( 'topic' ),
                'data'  => $request->get_param( 'data' ),
                'id'    => $request->get_param( 'id' ),
            ];
        }
        $type      = (string) ( $body['type'] ?? $body['topic'] ?? '' );
        $dataId    = (string) ( $body['data']['id'] ?? $body['id'] ?? '' );
        $signature = (string) $request->get_header( 'x-signature' );
        $requestId = (string) $request->get_header( 'x-request-id' );

        if ( '' === $dataId || ! $this->verifier->verify( $signature, $requestId, $dataId, time() ) ) {
            return new \WP_REST_Response( [ 'ok' => true ], 200 );
        }

        try {
            $this->service->reconcileFromNotification( $type, $dataId, time() );
        } catch ( \Throwable $e ) {
            error_log( '[INFOUNO] webhook reconcile error: ' . $e->getMessage() );
        }

        return new \WP_REST_Response( [ 'ok' => true ], 200 );
    }

    public function subscription( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant = $this->tenantManager->requireForCurrentUser();
        $sub    = $this->repository->findActiveForTenant( (int) $tenant['id'] );

        return new \WP_REST_Response( [
            'plan'          => $tenant['plan'] ?? 'free',
            'tenant_status' => $tenant['status'] ?? 'active',
            'subscription'  => $sub
                ? [ 'status' => $sub['status'], 'next_payment_at' => $sub['next_payment_at'] ?? null ]
                : null,
        ], 200 );
    }

    public function cancel( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant = $this->tenantManager->requireForCurrentUser();
        $sub    = $this->repository->findActiveForTenant( (int) $tenant['id'] );
        if ( null === $sub ) {
            return new \WP_Error( 'no_subscription', 'No hay suscripción activa.', [ 'status' => 404 ] );
        }

        $this->service->cancelSubscription( (string) $sub['mp_preapproval_id'] );
        return new \WP_REST_Response( [ 'cancelled' => true ], 200 );
    }

    private function ownerEmail( int $userId ): string {
        $user = $userId > 0 ? get_userdata( $userId ) : null;
        return $user && isset( $user->user_email ) ? (string) $user->user_email : '';
    }
}
