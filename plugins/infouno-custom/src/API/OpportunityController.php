<?php

declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Opportunity\OpportunityRepository;
use Infouno\SaaS\Opportunity\OpportunityService;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Endpoints REST del Opportunity Engine.
 *
 * Todas las rutas requieren WP login + tenant activo.
 * Toda query al repository incluye tenant_id del usuario logueado — no del request.
 *
 * GET    /infouno/v1/opportunities          → Listar oportunidades (filtro stage, paginado)
 * POST   /infouno/v1/opportunities          → Crear manualmente desde lead_id
 * GET    /infouno/v1/opportunities/metrics  → Métricas de pipeline del tenant
 * GET    /infouno/v1/opportunities/{id}     → Ver oportunidad
 * PUT    /infouno/v1/opportunities/{id}/stage → Cambiar stage
 * PUT    /infouno/v1/opportunities/{id}/value → Actualizar estimated_value
 */
final class OpportunityController {

    public function __construct(
        private readonly OpportunityService    $service,
        private readonly OpportunityRepository $repository,
        private readonly TenantManager         $tenantManager,
    ) {}

    public function registerRoutes( string $namespace ): void {
        register_rest_route( $namespace, '/opportunities', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'index' ],
                'permission_callback' => [ $this, 'requireActiveTenant' ],
                'args'                => [
                    'stage' => [
                        'type'              => 'string',
                        'enum'              => OpportunityRepository::STAGES,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'page' => [
                        'type'    => 'integer',
                        'default' => 1,
                        'minimum' => 1,
                    ],
                    'per_page' => [
                        'type'    => 'integer',
                        'default' => 50,
                        'minimum' => 1,
                        'maximum' => 100,
                    ],
                ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'store' ],
                'permission_callback' => [ $this, 'requireActiveTenant' ],
                'args'                => [
                    'lead_id' => [
                        'type'     => 'integer',
                        'required' => true,
                        'minimum'  => 1,
                    ],
                    'estimated_value' => [
                        'type'    => 'number',
                        'minimum' => 0,
                    ],
                    'currency' => [
                        'type'              => 'string',
                        'default'           => 'ARS',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'notes' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                ],
            ],
        ] );

        // /opportunities/metrics ANTES de /opportunities/{id} para evitar conflicto de routing.
        register_rest_route( $namespace, '/opportunities/metrics', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'metrics' ],
            'permission_callback' => [ $this, 'requireActiveTenant' ],
        ] );

        register_rest_route( $namespace, '/opportunities/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'show' ],
                'permission_callback' => [ $this, 'requireActiveTenant' ],
            ],
        ] );

        register_rest_route( $namespace, '/opportunities/(?P<id>\d+)/stage', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'updateStage' ],
            'permission_callback' => [ $this, 'requireActiveTenant' ],
            'args'                => [
                'stage' => [
                    'type'              => 'string',
                    'required'          => true,
                    'enum'              => OpportunityRepository::STAGES,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'lost_reason' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( $namespace, '/opportunities/(?P<id>\d+)/value', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'updateValue' ],
            'permission_callback' => [ $this, 'requireActiveTenant' ],
            'args'                => [
                'estimated_value' => [
                    'type'     => 'number',
                    'required' => true,
                    'minimum'  => 0,
                ],
                'currency' => [
                    'type'              => 'string',
                    'default'           => 'ARS',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    public function index( \WP_REST_Request $request ): \WP_REST_Response {
        $tenantId = $this->currentTenantId();
        $perPage  = (int) $request->get_param( 'per_page' );
        $page     = (int) $request->get_param( 'page' );
        $stage    = $request->get_param( 'stage' );

        $items = $this->repository->listForTenant(
            $tenantId,
            $stage ?: null,
            $perPage,
            ( $page - 1 ) * $perPage
        );

        $total = $this->repository->countForTenant( $tenantId, $stage ?: null );

        return new \WP_REST_Response(
            [
                'items'    => array_map( [ $this, 'sanitizeOutput' ], $items ),
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => $total > 0 ? (int) ceil( $total / $perPage ) : 0,
            ],
            200
        );
    }

    public function store( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $tenant   = $this->tenantManager->getForCurrentUser();
        $tenantId = (int) $tenant['id'];
        $leadId   = (int) $request->get_param( 'lead_id' );

        // Leer el lead de origen (existencia + score + bot_id) en una sola query.
        $snapshot = $this->repository->getLeadSnapshotForTenant( $leadId, $tenantId );

        if ( $snapshot === null ) {
            return new \WP_Error( 'lead_not_found', 'Lead no encontrado.', [ 'status' => 404 ] );
        }

        // Verificar que el lead tiene score suficiente (R1 del opportunity-engine.md).
        if ( $snapshot['score'] < OpportunityService::QUALIFIED_THRESHOLD ) {
            return new \WP_Error(
                'lead_not_qualified',
                sprintf( 'El lead tiene score %d. Se requiere score ≥ %d para crear una oportunidad.', $snapshot['score'], OpportunityService::QUALIFIED_THRESHOLD ),
                [ 'status' => 422 ]
            );
        }

        $existing = $this->repository->getActiveByLead( $leadId, $tenantId );
        if ( $existing ) {
            return new \WP_REST_Response( $this->sanitizeOutput( $existing ), 200 );
        }

        $oppId = $this->repository->create( [
            'tenant_id'       => $tenantId,
            'lead_id'         => $leadId,
            'bot_id'          => $snapshot['bot_id'],
            'estimated_value' => $request->get_param( 'estimated_value' ),
            'currency'        => $request->get_param( 'currency' ) ?? 'ARS',
            'notes'           => $request->get_param( 'notes' ),
        ] );

        if ( ! $oppId ) {
            return new \WP_Error( 'create_failed', 'No se pudo crear la oportunidad.', [ 'status' => 500 ] );
        }

        do_action( 'infouno_opportunity_created', $oppId, $tenantId, 'new', $request->get_param( 'estimated_value' ) );

        $opp = $this->repository->getById( $oppId, $tenantId );
        return new \WP_REST_Response( $this->sanitizeOutput( $opp ), 201 );
    }

    public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $opp = $this->resolveOpportunity( (int) $request['id'] );
        if ( is_wp_error( $opp ) ) {
            return $opp;
        }

        return new \WP_REST_Response( $this->sanitizeOutput( $opp ), 200 );
    }

    public function metrics( \WP_REST_Request $request ): \WP_REST_Response {
        $tenantId = $this->currentTenantId();
        return new \WP_REST_Response( $this->service->getPipelineMetrics( $tenantId ), 200 );
    }

    public function updateStage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $opp = $this->resolveOpportunity( (int) $request['id'] );
        if ( is_wp_error( $opp ) ) {
            return $opp;
        }

        $tenantId  = $this->currentTenantId();
        $newStage  = (string) $request->get_param( 'stage' );
        $lostReason = (string) $request->get_param( 'lost_reason' );

        // Guardrail comercial: no cambiar stages terminales.
        if ( in_array( $opp['stage'], OpportunityRepository::TERMINAL_STAGES, true ) ) {
            return new \WP_Error(
                'stage_terminal',
                sprintf( 'La oportunidad está en estado "%s" (terminal) y no puede cambiar de stage.', $opp['stage'] ),
                [ 'status' => 409 ]
            );
        }

        $changed = $this->service->updateStage( (int) $opp['id'], $tenantId, $newStage, $lostReason );

        if ( ! $changed ) {
            return new \WP_Error( 'stage_update_failed', 'No se pudo actualizar el stage.', [ 'status' => 500 ] );
        }

        $updated = $this->repository->getById( (int) $opp['id'], $tenantId );
        return new \WP_REST_Response( $this->sanitizeOutput( $updated ), 200 );
    }

    public function updateValue( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $opp = $this->resolveOpportunity( (int) $request['id'] );
        if ( is_wp_error( $opp ) ) {
            return $opp;
        }

        $tenantId = $this->currentTenantId();
        $value    = (float) $request->get_param( 'estimated_value' );
        $currency = (string) $request->get_param( 'currency' );

        $updated = $this->service->updateValue( (int) $opp['id'], $tenantId, $value, $currency );

        if ( ! $updated ) {
            return new \WP_Error( 'value_update_failed', 'No se pudo actualizar el valor estimado.', [ 'status' => 500 ] );
        }

        $fresh = $this->repository->getById( (int) $opp['id'], $tenantId );
        return new \WP_REST_Response( $this->sanitizeOutput( $fresh ), 200 );
    }

    /** Permission callback: WP login + tenant activo. */
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
     * Retorna el tenant_id del usuario logueado (desde sesión del servidor, nunca del request).
     */
    private function currentTenantId(): int {
        return (int) $this->tenantManager->getForCurrentUser()['id'];
    }

    /**
     * Resuelve la oportunidad por ID validando que pertenece al tenant del usuario actual.
     * Retorna 404 (no 403) para no revelar si la oportunidad existe en otro tenant.
     */
    private function resolveOpportunity( int $id ): array|\WP_Error {
        $tenantId = $this->currentTenantId();
        $opp      = $this->repository->getById( $id, $tenantId );

        if ( ! $opp ) {
            return new \WP_Error( 'opportunity_not_found', 'Oportunidad no encontrada.', [ 'status' => 404 ] );
        }

        return $opp;
    }

    /** Expone solo los campos del contrato público de la API. */
    private function sanitizeOutput( array $opp ): array {
        return [
            'id'               => (int) $opp['id'],
            'tenant_id'        => (int) $opp['tenant_id'],
            'lead_id'          => (int) $opp['lead_id'],
            'bot_id'           => (int) $opp['bot_id'],
            'stage'            => $opp['stage'],
            'estimated_value'  => isset( $opp['estimated_value'] ) ? (float) $opp['estimated_value'] : null,
            'currency'         => $opp['currency'],
            'assigned_to'      => isset( $opp['assigned_to'] ) ? (int) $opp['assigned_to'] : null,
            'notes'            => $opp['notes'],
            'lost_reason'      => $opp['lost_reason'],
            'stage_changed_at' => $opp['stage_changed_at'],
            'won_at'           => $opp['won_at'],
            'lost_at'          => $opp['lost_at'],
            'created_at'       => $opp['created_at'],
            'updated_at'       => $opp['updated_at'],
        ];
    }
}
