<?php
declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Lead\LeadRepository;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Endpoints REST para la gestión de leads del tenant.
 *
 * Rutas registradas bajo /infouno/v1/:
 *   GET  /leads              — Listado paginado de leads del tenant autenticado.
 *   PUT  /leads/{id}/status  — Actualiza estado de un lead.
 *
 * Todo SQL vive en LeadRepository — este controller no usa $wpdb directamente.
 * El tenant se resuelve fail-closed: sin tenant activo → HTTP 500 (bug de programación).
 */
final class LeadController {

    private const VALID_STATUSES = [ 'new', 'contacted', 'interested', 'converted', 'lost' ];

    public function __construct(
        private readonly TenantManager  $tenantManager,
        private readonly LeadRepository $leadRepository,
    ) {}

    public function registerRoutes( string $namespace ): void {
        register_rest_route( $namespace, '/leads', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'index' ],
            'permission_callback' => [ $this, 'requireTenant' ],
            'args'                => [
                'status' => [
                    'type'              => 'string',
                    'required'          => false,
                    'enum'              => self::VALID_STATUSES,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'page' => [
                    'type'    => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                ],
            ],
        ] );

        register_rest_route( $namespace, '/leads/(?P<id>\d+)/status', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'updateStatus' ],
            'permission_callback' => [ $this, 'requireTenant' ],
            'args'                => [
                'id' => [
                    'type'     => 'integer',
                    'required' => true,
                    'minimum'  => 1,
                ],
                'status' => [
                    'type'              => 'string',
                    'required'          => true,
                    'enum'              => self::VALID_STATUSES,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'notes' => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ] );
    }

    /**
     * GET /infouno/v1/leads
     *
     * Retorna hasta 50 leads del tenant, ordenados por score DESC.
     * Filtra opcionalmente por estado. Pagina de 50 en 50.
     */
    public function index( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $tenantId = $this->requireTenantId();
        } catch ( MissingTenantScopeException $e ) {
            error_log( '[INFOUNO-LEAD] MissingTenantScopeException en index(): ' . $e->getMessage() );
            return new \WP_REST_Response( [ 'error' => 'Error interno del servidor.' ], 500 );
        }

        $status  = $request->get_param( 'status' );
        $page    = max( 1, (int) $request->get_param( 'page' ) );
        $perPage = 50;
        $offset  = ( $page - 1 ) * $perPage;

        $rows = $this->leadRepository->listForTenant(
            tenantId: $tenantId,
            status:   $status && in_array( $status, self::VALID_STATUSES, true ) ? $status : null,
            limit:    $perPage,
            offset:   $offset,
        );

        return new \WP_REST_Response( $rows, 200 );
    }

    /**
     * PUT /infouno/v1/leads/{id}/status
     *
     * Actualiza el estado de un lead y registra timestamps de contacto/conversión.
     * Verifica ownership por tenant_id antes de actualizar.
     */
    public function updateStatus( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        try {
            $tenantId = $this->requireTenantId();
        } catch ( MissingTenantScopeException $e ) {
            error_log( '[INFOUNO-LEAD] MissingTenantScopeException en updateStatus(): ' . $e->getMessage() );
            return new \WP_REST_Response( [ 'error' => 'Error interno del servidor.' ], 500 );
        }

        $leadId = (int) $request->get_param( 'id' );
        $status = $request->get_param( 'status' );
        $notes  = $request->get_param( 'notes' );

        if ( ! $this->leadRepository->verifyOwnership( leadId: $leadId, tenantId: $tenantId ) ) {
            return new \WP_Error( 'lead_not_found', 'Lead no encontrado.', [ 'status' => 404 ] );
        }

        $this->leadRepository->updateStatusForTenant(
            leadId:   $leadId,
            tenantId: $tenantId,
            status:   $status,
            notes:    $notes,
        );

        return new \WP_REST_Response( [ 'updated' => true, 'status' => $status ], 200 );
    }

    /** Permission callback: requiere usuario WP logueado con tenant activo. */
    public function requireTenant( \WP_REST_Request $request ): bool|\WP_Error {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'not_authenticated', 'Autenticación requerida.', [ 'status' => 401 ] );
        }

        if ( ! $this->tenantManager->getForCurrentUser() ) {
            return new \WP_Error( 'no_tenant', 'Sin tenant asociado.', [ 'status' => 403 ] );
        }

        return true;
    }

    /**
     * Resuelve el tenant del usuario actual de forma fail-closed.
     * Lanza MissingTenantScopeException si no hay tenant — error de programación
     * (el permission_callback ya garantizó que hay tenant antes de llegar aquí).
     *
     * @throws MissingTenantScopeException
     */
    private function requireTenantId(): int {
        return (int) $this->tenantManager->requireForCurrentUser()['id'];
    }
}
