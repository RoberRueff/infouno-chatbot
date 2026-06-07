<?php

declare(strict_types=1);

namespace Infouno\SaaS\Admin;

use Infouno\SaaS\Lead\LeadRepository;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Panel de administración de leads capturados por el Lead Engine.
 * Visible para WP admins y usuarios con tenant activo (tenant_admin, tenant_agent).
 *
 * Todo SQL vive en LeadRepository — este admin no usa $wpdb directamente.
 * El tenant se resuelve fail-closed: sin tenant activo → HTTP 500 (bug de programación).
 */
final class LeadDashboard {

    private const NONCE_EXPORT  = 'infouno_export_leads';
    private const NONCE_STATUS  = 'infouno_update_lead_status';
    private const VALID_STATUSES = [ 'new', 'contacted', 'interested', 'converted', 'lost' ];

    private const STATUS_LABELS = [
        'new'        => 'Nuevo',
        'contacted'  => 'Contactado',
        'interested' => 'Interesado',
        'converted'  => 'Convertido',
        'lost'       => 'Perdido',
    ];

    public function __construct(
        private readonly TenantManager  $tenantManager,
        private readonly LeadRepository $leadRepository,
    ) {}

    public function init(): void {
        add_action( 'admin_menu',                        [ $this, 'addMenuPage' ] );
        add_action( 'admin_post_infouno_export_leads',   [ $this, 'exportCsv' ] );
        add_action( 'admin_post_infouno_update_lead',    [ $this, 'updateLeadStatus' ] );
    }

    public function addMenuPage(): void {
        add_submenu_page(
            'infouno-dashboard',
            'Leads Capturados',
            'Leads',
            'read',
            'infouno-leads',
            [ $this, 'renderPage' ]
        );
    }

    public function renderPage(): void {
        try {
            $tenantId = $this->requireTenantId();
        } catch ( MissingTenantScopeException $e ) {
            error_log( '[INFOUNO-LEAD] MissingTenantScopeException en LeadDashboard::renderPage(): ' . $e->getMessage() );
            wp_die(
                esc_html__( 'Error interno del servidor. Contacte al administrador.', 'infouno-custom' ),
                esc_html__( 'Error', 'infouno-custom' ),
                [ 'response' => 500 ]
            );
        }

        if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 esc_html__( 'Estado del lead actualizado.', 'infouno-custom' ) .
                 '</p></div>';
        }

        $filterStatus = sanitize_text_field( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! in_array( $filterStatus, self::VALID_STATUSES, true ) ) {
            $filterStatus = '';
        }

        $leads = $this->leadRepository->listForTenant(
            tenantId: $tenantId,
            status:   '' !== $filterStatus ? $filterStatus : null,
            limit:    100,
            offset:   0,
        );

        $leads          = $leads ?: [];
        $totalLeads     = count( $leads );
        $qualifiedLeads = count( array_filter( $leads, static fn( $l ) => (int) $l['score'] >= 60 ) );
        $convertedLeads = count( array_filter( $leads, static fn( $l ) => 'converted' === $l['status'] ) );

        $exportUrl = wp_nonce_url(
            admin_url( 'admin-post.php?action=infouno_export_leads' ),
            self::NONCE_EXPORT
        );

        $baseUrl = admin_url( 'admin.php?page=infouno-leads' );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Leads Capturados', 'infouno-custom' ); ?></h1>

            <div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;min-width:120px;text-align:center;">
                    <div style="font-size:2em;font-weight:700;color:#1d2327"><?php echo esc_html( (string) $totalLeads ); ?></div>
                    <div style="color:#646970"><?php esc_html_e( 'Total leads', 'infouno-custom' ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;min-width:120px;text-align:center;">
                    <div style="font-size:2em;font-weight:700;color:#2271b1"><?php echo esc_html( (string) $qualifiedLeads ); ?></div>
                    <div style="color:#646970"><?php esc_html_e( 'Calificados (≥60)', 'infouno-custom' ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;min-width:120px;text-align:center;">
                    <div style="font-size:2em;font-weight:700;color:#00a32a"><?php echo esc_html( (string) $convertedLeads ); ?></div>
                    <div style="color:#646970"><?php esc_html_e( 'Convertidos', 'infouno-custom' ); ?></div>
                </div>
            </div>

            <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <strong><?php esc_html_e( 'Filtrar por estado:', 'infouno-custom' ); ?></strong>
                <a href="<?php echo esc_url( $baseUrl ); ?>" class="button <?php echo '' === $filterStatus ? 'button-primary' : ''; ?>">
                    <?php esc_html_e( 'Todos', 'infouno-custom' ); ?>
                </a>
                <?php foreach ( self::STATUS_LABELS as $key => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'status', $key, $baseUrl ) ); ?>"
                       class="button <?php echo $filterStatus === $key ? 'button-primary' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url( $exportUrl ); ?>" class="button" style="margin-left:auto;">
                    ⬇ <?php esc_html_e( 'Exportar CSV', 'infouno-custom' ); ?>
                </a>
            </div>

            <?php if ( empty( $leads ) ) : ?>
                <p><?php esc_html_e( 'Todavía no hay leads registrados para este tenant.', 'infouno-custom' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:130px"><?php esc_html_e( 'Fecha', 'infouno-custom' ); ?></th>
                            <th><?php esc_html_e( 'Bot', 'infouno-custom' ); ?></th>
                            <th><?php esc_html_e( 'Nombre', 'infouno-custom' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'infouno-custom' ); ?></th>
                            <th><?php esc_html_e( 'Teléfono', 'infouno-custom' ); ?></th>
                            <th><?php esc_html_e( 'Interés', 'infouno-custom' ); ?></th>
                            <th style="width:60px"><?php esc_html_e( 'Score', 'infouno-custom' ); ?></th>
                            <th style="width:70px"><?php esc_html_e( 'Prioridad', 'infouno-custom' ); ?></th>
                            <th style="width:180px"><?php esc_html_e( 'Estado', 'infouno-custom' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $leads as $lead ) :
                            $statusLabel = self::STATUS_LABELS[ $lead['status'] ?? 'new' ] ?? '—';
                            $prioridad   = $lead['prioridad'] ?? '—';
                            $prioColor   = 'Alta' === $prioridad ? '#d63638' : ( 'Media' === $prioridad ? '#dba617' : '#646970' );
                            $updateUrl   = wp_nonce_url(
                                admin_url( 'admin-post.php?action=infouno_update_lead&lead_id=' . (int) $lead['id'] ),
                                self::NONCE_STATUS . '_' . (int) $lead['id']
                            );
                            ?>
                            <tr>
                                <td><?php echo esc_html( substr( $lead['created_at'] ?? '', 0, 16 ) ); ?></td>
                                <td><?php echo esc_html( $lead['bot_name'] ?? '—' ); ?></td>
                                <td><?php echo esc_html( $lead['name'] ?? '—' ); ?></td>
                                <td>
                                    <?php if ( ! empty( $lead['email'] ) ) : ?>
                                        <a href="<?php echo esc_url( 'mailto:' . $lead['email'] ); ?>">
                                            <?php echo esc_html( $lead['email'] ); ?>
                                        </a>
                                    <?php else : ?>—<?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $lead['phone'] ?? '—' ); ?></td>
                                <td><?php echo esc_html( $lead['interest'] ?? '—' ); ?></td>
                                <td><strong><?php echo esc_html( (string) ( $lead['score'] ?? 0 ) ); ?></strong></td>
                                <td><span style="color:<?php echo esc_attr( $prioColor ); ?>;font-weight:600">
                                    <?php echo esc_html( $prioridad ); ?>
                                </span></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url( $updateUrl ); ?>" style="display:flex;gap:4px;align-items:center;">
                                        <select name="status" style="max-width:110px">
                                            <?php foreach ( self::STATUS_LABELS as $key => $label ) : ?>
                                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $lead['status'], $key ); ?>>
                                                    <?php echo esc_html( $label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="button button-small">✓</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Exporta los leads del tenant como archivo CSV con BOM UTF-8.
     */
    public function exportCsv(): void {
        if ( ! check_admin_referer( self::NONCE_EXPORT ) ) {
            wp_die( esc_html__( 'Nonce inválido.', 'infouno-custom' ), 403 );
        }

        try {
            $tenantId = $this->requireTenantId();
        } catch ( MissingTenantScopeException $e ) {
            error_log( '[INFOUNO-LEAD] MissingTenantScopeException en LeadDashboard::exportCsv(): ' . $e->getMessage() );
            wp_die( esc_html__( 'Error interno del servidor.', 'infouno-custom' ), 500 );
        }

        $leads    = $this->leadRepository->listForCsv( tenantId: $tenantId );
        $filename = 'infouno-leads-' . gmdate( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, [ 'Fecha', 'Bot', 'Nombre', 'Email', 'Teléfono', 'Interés', 'Score', 'Estado', 'Notas' ] );

        foreach ( $leads ?: [] as $lead ) {
            fputcsv( $out, [
                $lead['created_at'],
                $lead['bot_name'],
                $lead['name']     ?? '',
                $lead['email']    ?? '',
                $lead['phone']    ?? '',
                $lead['interest'] ?? '',
                $lead['score'],
                self::STATUS_LABELS[ $lead['status'] ?? 'new' ] ?? $lead['status'],
                $lead['notes']    ?? '',
            ] );
        }

        fclose( $out );
        exit();
    }

    /**
     * Actualiza el estado de un lead desde el formulario inline del panel.
     */
    public function updateLeadStatus(): void {
        $leadId = (int) ( $_POST['lead_id'] ?? $_GET['lead_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

        if ( ! check_admin_referer( self::NONCE_STATUS . '_' . $leadId ) ) {
            wp_die( esc_html__( 'Nonce inválido.', 'infouno-custom' ), 403 );
        }

        try {
            $tenantId = $this->requireTenantId();
        } catch ( MissingTenantScopeException $e ) {
            error_log( '[INFOUNO-LEAD] MissingTenantScopeException en LeadDashboard::updateLeadStatus(): ' . $e->getMessage() );
            wp_die( esc_html__( 'Error interno del servidor.', 'infouno-custom' ), 500 );
        }

        if ( ! $tenantId || ! $leadId ) {
            wp_safe_redirect( admin_url( 'admin.php?page=infouno-leads' ) );
            exit();
        }

        $status = sanitize_text_field( (string) ( $_POST['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=infouno-leads' ) );
            exit();
        }

        if ( $this->leadRepository->verifyOwnership( leadId: $leadId, tenantId: $tenantId ) ) {
            $this->leadRepository->updateStatusForTenant(
                leadId:   $leadId,
                tenantId: $tenantId,
                status:   $status,
                notes:    null, // el dashboard no actualiza notes
            );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=infouno-leads&updated=1' ) );
        exit();
    }

    /**
     * Resuelve el tenant del usuario actual de forma fail-closed.
     * Lanza MissingTenantScopeException si no hay tenant.
     *
     * @throws MissingTenantScopeException
     */
    private function requireTenantId(): int {
        return (int) $this->tenantManager->requireForCurrentUser()['id'];
    }
}
