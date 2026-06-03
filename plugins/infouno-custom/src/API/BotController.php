<?php

declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Bot\PromptBuilder;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Endpoints REST para gestión de bots.
 * Todas las rutas validan ownership tenant antes de operar — guardrail de aislamiento.
 *
 * GET    /infouno/v1/bots
 * POST   /infouno/v1/bots
 * GET    /infouno/v1/bots/{id}
 * PUT    /infouno/v1/bots/{id}
 * DELETE /infouno/v1/bots/{id}
 * POST   /infouno/v1/bots/{id}/wizard  — genera y guarda system_prompt desde wizard data
 */
final class BotController {

    public function __construct(
        private readonly BotManager    $botManager,
        private readonly TenantManager $tenantManager
    ) {}

    public function registerRoutes( string $namespace ): void {
        register_rest_route( $namespace, '/bots', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'index' ],
                'permission_callback' => [ $this, 'requireActiveTenant' ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'store' ],
                'permission_callback' => [ $this, 'requireActiveTenant' ],
                'args'                => $this->botArgs(),
            ],
        ] );

        register_rest_route( $namespace, '/bots/(?P<id>\d+)/wizard', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'wizard' ],
            'permission_callback' => [ $this, 'requireActiveTenant' ],
            'args'                => [
                'wizard_data' => [
                    'type'     => 'object',
                    'required' => true,
                ],
                'save' => [
                    'type'    => 'boolean',
                    'default' => true,
                ],
            ],
        ] );

        register_rest_route( $namespace, '/bots/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'show' ],
                'permission_callback' => [ $this, 'requireActiveTenant' ],
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update' ],
                'permission_callback' => [ $this, 'requireActiveTenant' ],
                'args'                => $this->botArgs( required: false ),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'destroy' ],
                'permission_callback' => [ $this, 'requireActiveTenant' ],
            ],
        ] );
    }

    public function index( \WP_REST_Request $request ): \WP_REST_Response {
        $tenant = $this->tenantManager->getForCurrentUser();
        $bots   = $this->botManager->getAllForTenant( (int) $tenant['id'] );

        return new \WP_REST_Response( array_map( [ $this, 'sanitizeOutput' ], $bots ), 200 );
    }

    public function store( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant    = $this->tenantManager->getForCurrentUser();
        $tenantId  = (int) $tenant['id'];
        $plan      = $tenant['plan'] ?? 'free';
        $limit     = BotManager::PLAN_BOT_LIMITS[ $plan ] ?? 1;
        $current   = $this->botManager->countForTenant( $tenantId );

        if ( $current >= $limit ) {
            return new \WP_Error(
                'bot_limit_reached',
                sprintf( 'Tu plan %s permite un máximo de %d bot(s). Actualizá tu plan para crear más.', $plan, $limit ),
                [ 'status' => 402 ]
            );
        }

        try {
            $botId = $this->botManager->create( $tenantId, $request->get_params() );
            $bot   = $this->botManager->getById( $botId, $tenantId );
            return new \WP_REST_Response( $this->sanitizeOutput( $bot ), 201 );
        } catch ( \RuntimeException $e ) {
            return new \WP_Error( 'bot_create_failed', $e->getMessage(), [ 'status' => $e->getCode() ?: 500 ] );
        }
    }

    public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $bot = $this->resolveBotForCurrentTenant( (int) $request['id'] );

        if ( is_wp_error( $bot ) ) {
            return $bot;
        }

        return new \WP_REST_Response( $this->sanitizeOutput( $bot ), 200 );
    }

    public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant = $this->tenantManager->getForCurrentUser();
        $botId  = (int) $request['id'];

        $existing = $this->resolveBotForCurrentTenant( $botId );
        if ( is_wp_error( $existing ) ) {
            return $existing;
        }

        $updated = $this->botManager->update( $botId, (int) $tenant['id'], $request->get_params() );

        if ( ! $updated ) {
            return new \WP_Error( 'bot_update_failed', 'No se pudo actualizar el bot.', [ 'status' => 500 ] );
        }

        $bot = $this->botManager->getById( $botId, (int) $tenant['id'] );
        return new \WP_REST_Response( $this->sanitizeOutput( $bot ), 200 );
    }

    public function destroy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant = $this->tenantManager->getForCurrentUser();
        $botId  = (int) $request['id'];

        $existing = $this->resolveBotForCurrentTenant( $botId );
        if ( is_wp_error( $existing ) ) {
            return $existing;
        }

        $this->botManager->delete( $botId, (int) $tenant['id'] );

        return new \WP_REST_Response( null, 204 );
    }

    /**
     * POST /bots/{id}/wizard
     *
     * Recibe wizard_data, genera el system_prompt y opcionalmente lo guarda en el bot.
     * Cuando save=true (default), actualiza system_prompt + wizard_data en la BD.
     * Cuando save=false, retorna solo el prompt generado para previsualización.
     */
    public function wizard( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $bot = $this->resolveBotForCurrentTenant( (int) $request['id'] );
        if ( is_wp_error( $bot ) ) {
            return $bot;
        }

        $wizardData = (array) $request->get_param( 'wizard_data' );

        $errors = PromptBuilder::validate( $wizardData );
        if ( ! empty( $errors ) ) {
            return new \WP_Error(
                'wizard_validation_failed',
                implode( ' ', $errors ),
                [ 'status' => 422 ]
            );
        }

        $generatedPrompt = PromptBuilder::fromWizardData( $wizardData );

        if ( $request->get_param( 'save' ) ) {
            $tenant = $this->tenantManager->getForCurrentUser();

            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'infouno_bots',
                [
                    'system_prompt' => $generatedPrompt,
                    'wizard_data'   => wp_json_encode( $wizardData ),
                ],
                [
                    'id'        => (int) $bot['id'],
                    'tenant_id' => (int) $tenant['id'],
                ],
                [ '%s', '%s' ],
                [ '%d', '%d' ]
            );
        }

        return new \WP_REST_Response(
            [
                'system_prompt' => $generatedPrompt,
                'wizard_data'   => $wizardData,
                'saved'         => (bool) $request->get_param( 'save' ),
            ],
            200
        );
    }

    /** Permission callback: usuario logueado con tenant activo. */
    public function requireActiveTenant( \WP_REST_Request $request ): bool|\WP_Error {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'not_authenticated', 'Autenticación requerida.', [ 'status' => 401 ] );
        }

        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            return new \WP_Error( 'no_tenant', 'Sin tenant asociado.', [ 'status' => 403 ] );
        }

        if ( 'active' !== $tenant['status'] ) {
            return new \WP_Error( 'tenant_inactive', 'Cuenta suspendida.', [ 'status' => 403 ] );
        }

        return true;
    }

    /**
     * Resuelve el bot por ID validando que pertenece al tenant del usuario actual.
     * Guardrail: nunca devolver datos de otro tenant aunque el ID exista.
     */
    private function resolveBotForCurrentTenant( int $botId ): array|\WP_Error {
        $tenant = $this->tenantManager->getForCurrentUser();
        $bot    = $this->botManager->getById( $botId, (int) $tenant['id'] );

        if ( ! $bot ) {
            return new \WP_Error( 'bot_not_found', 'Bot no encontrado.', [ 'status' => 404 ] );
        }

        return $bot;
    }

    /** Expone solo los campos seguros — nunca system_prompt completo en listings. */
    private function sanitizeOutput( array $bot ): array {
        return [
            'id'              => (int) $bot['id'],
            'bot_name'        => $bot['bot_name'],
            'public_token'    => $bot['public_token'],
            'system_prompt'   => $bot['system_prompt'],
            'settings'        => $bot['settings'],
            'llm_provider'    => $bot['llm_provider'],
            'llm_model'       => $bot['llm_model'],
            'allowed_origins' => $bot['allowed_origins'],
            'is_active'       => (bool) $bot['is_active'],
            'created_at'      => $bot['created_at'],
        ];
    }

    private function botArgs( bool $required = true ): array {
        return [
            'bot_name' => [
                'type'              => 'string',
                'required'          => $required,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => static fn( $v ) => strlen( trim( $v ) ) >= 2,
            ],
            'system_prompt' => [
                'type'              => 'string',
                'required'          => $required,
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'llm_provider' => [
                'type'              => 'string',
                'enum'              => [ 'anthropic', 'openai' ],
                'default'           => 'anthropic',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'llm_model' => [
                'type'              => 'string',
                'enum'              => array_unique( array_merge( ...array_values( LLMRouter::ALLOWED_MODELS ) ) ),
                'default'           => 'claude-haiku-4-5-20251001',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'allowed_origins' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'settings' => [
                'type'    => 'object',
                'default' => [],
            ],
            'is_active' => [
                'type'    => 'boolean',
                'default' => true,
            ],
        ];
    }
}
