<?php

declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Tenant\TenantManager;

/**
 * Endpoints REST para la gestión de leads del tenant.
 *
 * Rutas registradas bajo /infouno/v1/:
 *   GET  /leads              — Listado paginado de leads del tenant autenticado.
 *   GET  /leads/export       — Descarga CSV de leads (via admin-post, ver LeadDashboard).
 *   PUT  /leads/{id}/status  — Actualiza estado de un lead (new/contacted/interested/converted/lost).
 *
 * Toda query filtra por tenant_id derivado de la sesión WP — nunca del request.
 */
final class LeadController {

    private const VALID_STATUSES = [ 'new', 'contacted', 'interested', 'converted', 'lost' ];

    public function __construct(
        private readonly TenantManager $tenantManager,
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
        global $wpdb;

        $tenantId  = $this->getTenantId();
        $status    = $request->get_param( 'status' );
        $page      = max( 1, (int) $request->get_param( 'page' ) );
        $perPage   = 50;
        $offset    = ( $page - 1 ) * $perPage;

        $leadsTable = $wpdb->prefix . 'infouno_leads';
        $botsTable  = $wpdb->prefix . 'infouno_bots';

        if ( $status && in_array( $status, self::VALID_STATUSES, true ) ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT l.id, l.name, l.email, l.phone, l.interest,
                            l.score, l.status, l.source, l.notes, l.created_at,
                            b.bot_name,
                            CASE WHEN l.score >= 80 THEN 'alta'
                                 WHEN l.score >= 60 THEN 'media'
                                 ELSE 'baja' END AS prioridad
                     FROM `{$leadsTable}` l
                     INNER JOIN `{$botsTable}` b ON b.id = l.bot_id AND b.tenant_id = l.tenant_id
                     WHERE l.tenant_id = %d AND l.status = %s
                     ORDER BY l.score DESC, l.created_at DESC
                     LIMIT %d OFFSET %d",
                    $tenantId,
                    $status,
                    $perPage,
                    $offset
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT l.id, l.name, l.email, l.phone, l.interest,
                            l.score, l.status, l.source, l.notes, l.created_at,
                            b.bot_name,
                            CASE WHEN l.score >= 80 THEN 'alta'
                                 WHEN l.score >= 60 THEN 'media'
                                 ELSE 'baja' END AS prioridad
                     FROM `{$leadsTable}` l
                     INNER JOIN `{$botsTable}` b ON b.id = l.bot_id AND b.tenant_id = l.tenant_id
                     WHERE l.tenant_id = %d
                     ORDER BY l.score DESC, l.created_at DESC
                     LIMIT %d OFFSET %d",
                    $tenantId,
                    $perPage,
                    $offset
                ),
                ARRAY_A
            );
        }

        return new \WP_REST_Response( $rows ?: [], 200 );
    }

    /**
     * PUT /infouno/v1/leads/{id}/status
     *
     * Actualiza el estado de un lead y registra timestamps de contacto/conversión.
     * Verifica ownership por tenant_id antes de actualizar.
     */
    public function updateStatus( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $tenantId = $this->getTenantId();
        $leadId   = (int) $request->get_param( 'id' );
        $status   = $request->get_param( 'status' );
        $notes    = $request->get_param( 'notes' );

        $table = $wpdb->prefix . 'infouno_leads';

        // Verifica ownership — guardrail de aislamiento
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE id = %d AND tenant_id = %d LIMIT 1",
                $leadId,
                $tenantId
            )
        );

        if ( ! $exists ) {
            return new \WP_Error( 'lead_not_found', 'Lead no encontrado.', [ 'status' => 404 ] );
        }

        $updateData    = [ 'status' => $status ];
        $updateFormats = [ '%s' ];

        if ( $notes !== null ) {
            $updateData['notes'] = $notes;
            $updateFormats[]     = '%s';
        }

        if ( 'contacted' === $status ) {
            $updateData['contacted_at'] = gmdate( 'Y-m-d H:i:s' );
            $updateFormats[]            = '%s';
        }

        if ( 'converted' === $status ) {
            $updateData['converted_at'] = gmdate( 'Y-m-d H:i:s' );
            $updateFormats[]            = '%s';
        }

        $wpdb->update(
            $table,
            $updateData,
            [ 'id' => $leadId, 'tenant_id' => $tenantId ],
            $updateFormats,
            [ '%d', '%d' ]
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

    private function getTenantId(): int {
        $tenant = $this->tenantManager->getForCurrentUser();
        return $tenant ? (int) $tenant['id'] : 0;
    }
}
