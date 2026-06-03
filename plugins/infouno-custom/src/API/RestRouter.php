<?php

declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Bot\QuotaService;
use Infouno\SaaS\Chat\ChatService;
use Infouno\SaaS\Chat\ConversationRepository;
use Infouno\SaaS\Tenant\TenantManager;
use Infouno\SaaS\API\OpportunityController;

/**
 * Registra todos los endpoints REST del SaaS bajo /infouno/v1/.
 * Los hooks de WP solo llaman a register(); la lógica vive en los controladores.
 */
final class RestRouter {

    private const NAMESPACE = 'infouno/v1';

    private BotController         $botController;
    private ChatController        $chatController;
    private SessionController     $sessionController;
    private ConsentController     $consentController;
    private LeadController        $leadController;
    private OpportunityController $opportunityController;

    public function __construct(
        private readonly TenantManager          $tenantManager,
        private readonly BotManager             $botManager,
        private readonly QuotaService           $quotaService,
        private readonly ChatService            $chatService,
        private readonly ConversationRepository $conversationRepo,
        LeadController                          $leadController,
        OpportunityController                   $opportunityController,
    ) {
        $this->botController         = new BotController( $this->botManager, $this->tenantManager );
        $this->chatController        = new ChatController( $this->chatService, $this->botManager );
        $this->sessionController     = new SessionController( $this->botManager, $this->conversationRepo );
        $this->consentController     = new ConsentController( $this->botManager, $this->conversationRepo );
        $this->leadController        = $leadController;
        $this->opportunityController = $opportunityController;
    }

    public function register(): void {
        register_rest_route( self::NAMESPACE, '/health', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'health' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/tenant', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'getTenant' ],
            'permission_callback' => [ $this, 'requireTenantAuth' ],
        ] );

        register_rest_route( self::NAMESPACE, '/tenant', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'createTenant' ],
            'permission_callback' => static fn() => is_user_logged_in(),
            'args'                => [
                'plan' => [
                    'type'              => 'string',
                    'enum'              => TenantManager::SELF_SERVICE_PLANS,
                    'default'           => 'free',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        $this->botController->registerRoutes( self::NAMESPACE );
        $this->chatController->registerRoutes( self::NAMESPACE );
        $this->sessionController->registerRoutes( self::NAMESPACE );
        $this->consentController->registerRoutes( self::NAMESPACE );
        $this->leadController->registerRoutes( self::NAMESPACE );
        $this->opportunityController->registerRoutes( self::NAMESPACE );
    }

    public function health( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }

    public function getTenant( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant = $this->tenantManager->getForCurrentUser();

        if ( ! $tenant ) {
            return new \WP_Error( 'no_tenant', 'No existe un tenant para este usuario.', [ 'status' => 404 ] );
        }

        return new \WP_REST_Response( $this->sanitizeTenantOutput( $tenant ), 200 );
    }

    public function createTenant( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $userId = get_current_user_id();

        if ( $this->tenantManager->getByUserId( $userId ) ) {
            return new \WP_Error( 'tenant_exists', 'El usuario ya tiene un tenant registrado.', [ 'status' => 409 ] );
        }

        try {
            $tenantId = $this->tenantManager->create( $userId, $request->get_param( 'plan' ) );
            $tenant   = $this->tenantManager->getById( $tenantId );
            return new \WP_REST_Response( $this->sanitizeTenantOutput( $tenant ), 201 );
        } catch ( \RuntimeException $e ) {
            return new \WP_Error( 'create_failed', $e->getMessage(), [ 'status' => $e->getCode() ?: 500 ] );
        }
    }

    /**
     * Permission callback para rutas que requieren un tenant activo del usuario logueado.
     */
    public function requireTenantAuth( \WP_REST_Request $request ): bool|\WP_Error {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'not_authenticated', 'Autenticación requerida.', [ 'status' => 401 ] );
        }

        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            return new \WP_Error( 'no_tenant', 'Sin tenant asociado.', [ 'status' => 403 ] );
        }

        return true;
    }

    /** Expone solo los campos públicos del tenant — nunca las API keys. */
    private function sanitizeTenantOutput( array $tenant ): array {
        return [
            'id'          => (int) $tenant['id'],
            'uuid'        => $tenant['uuid'],
            'status'      => $tenant['status'],
            'plan'        => $tenant['plan'],
            'quota_limit' => (int) $tenant['quota_limit'],
            'quota_used'  => (int) $tenant['quota_used'],
            'created_at'  => $tenant['created_at'],
        ];
    }
}
