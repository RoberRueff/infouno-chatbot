<?php

declare(strict_types=1);

namespace Infouno\SaaS\Admin;

use Infouno\SaaS\Opportunity\OpportunityRepository;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Panel de administración del pipeline de oportunidades comerciales.
 *
 * Muestra el pipeline por stage, métricas de valor estimado y permite
 * al tenant cambiar el stage de cada oportunidad directamente desde el panel.
 *
 * Guardrails:
 *   - Toda query filtra por tenant_id del usuario logueado.
 *   - Estados terminales (won/lost) no tienen selector de cambio de stage.
 *   - Los timestamps won_at/lost_at se setean una sola vez al cambiar a ese stage.
 */
final class OpportunityDashboard {

    private const PAGE_SLUG    = 'infouno-opportunities';
    private const ACTION_UPDATE = 'infouno_update_opportunity';
    private const NONCE_UPDATE  = 'infouno_opp_update';

    private const STAGE_LABELS = [
        'new'        => 'Nuevo',
        'contacted'  => 'Contactado',
        'interested' => 'Interesado',
        'quoted'     => 'Cotizado',
        'won'        => 'Ganado ✓',
        'lost'       => 'Perdido',
    ];

    private const STAGE_COLORS = [
        'new'        => '#2271b1',
        'contacted'  => '#dba617',
        'interested' => '#7c3aed',
        'quoted'     => '#0284c7',
        'won'        => '#00a32a',
        'lost'       => '#d63638',
    ];

    public function __construct(
        private readonly TenantManager         $tenantManager,
        private readonly OpportunityRepository $opportunityRepo,
    ) {}

    public function init(): void {
        add_action( 'admin_menu',                                [ $this, 'addMenuPage' ] );
        add_action( 'admin_post_' . self::ACTION_UPDATE, [ $this, 'updateStage' ] );
    }

    public function addMenuPage(): void {
        add_submenu_page(
            'infouno-dashboard',
            'Pipeline de Oportunidades',
            'Oportunidades',
            'read',
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function renderPage(): void {
        $tenantId = $this->getCurrentTenantId();

        if ( ! $tenantId ) {
            wp_die(
                esc_html__( 'No tenés un tenant activo.', 'infouno-custom' ),
                esc_html__( 'Acceso denegado', 'infouno-custom' ),
                [ 'response' => 403 ]
            );
        }

        if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 esc_html__( 'Oportunidad actualizada.', 'infouno-custom' ) .
                 '</p></div>';
        }

        $filterStage = sanitize_text_field( $_GET['stage'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! in_array( $filterStage, OpportunityRepository::STAGES, true ) ) {
            $filterStage = '';
        }

        $metrics      = $this->opportunityRepo->getPipelineMetrics( $tenantId );
        $opportunities = $this->getOpportunitiesWithLeadData( $tenantId, $filterStage ?: null );
        $baseUrl      = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Pipeline de Oportunidades', 'infouno-custom' ); ?></h1>

            <?php $this->renderMetricsCards( $metrics ); ?>
            <?php $this->renderStageFilter( $filterStage, $baseUrl, $metrics ); ?>
            <?php $this->renderTable( $opportunities, $tenantId ); ?>
        </div>
        <?php
    }

    private function renderMetricsCards( array $metrics ): void {
        $pipelineValue = number_format( (float) $metrics['pipeline_value'], 2, ',', '.' );
        $currency      = 'ARS';

        echo '<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">';

        $cards = [
            [ 'value' => $metrics['total'],                    'label' => 'Total oportunidades', 'color' => '#1d2327' ],
            [ 'value' => $metrics['by_stage']['new'] ?? 0,     'label' => 'Nuevas',              'color' => self::STAGE_COLORS['new'] ],
            [ 'value' => $metrics['by_stage']['quoted'] ?? 0,  'label' => 'Cotizadas',           'color' => self::STAGE_COLORS['quoted'] ],
            [ 'value' => $metrics['won_count'],                 'label' => 'Ganadas',             'color' => self::STAGE_COLORS['won'] ],
            [ 'value' => $metrics['lost_count'],                'label' => 'Perdidas',            'color' => self::STAGE_COLORS['lost'] ],
        ];

        foreach ( $cards as $card ) {
            printf(
                '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px 20px;min-width:110px;text-align:center;">
                    <div style="font-size:1.8em;font-weight:700;color:%s">%s</div>
                    <div style="color:#646970;font-size:12px">%s</div>
                </div>',
                esc_attr( $card['color'] ),
                esc_html( (string) $card['value'] ),
                esc_html( $card['label'] )
            );
        }

        // Pipeline value
        printf(
            '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px 20px;min-width:150px;text-align:center;">
                <div style="font-size:1.4em;font-weight:700;color:#00a32a">$ %s %s</div>
                <div style="color:#646970;font-size:12px">Valor en pipeline</div>
            </div>',
            esc_html( $pipelineValue ),
            esc_html( $currency )
        );

        echo '</div>';
    }

    private function renderStageFilter( string $active, string $baseUrl, array $metrics ): void {
        echo '<div style="margin-bottom:14px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">';
        echo '<strong>' . esc_html__( 'Filtrar:', 'infouno-custom' ) . '</strong>';

        $allClass = '' === $active ? 'button-primary' : '';
        printf(
            '<a href="%s" class="button %s">Todos <span style="font-size:11px;opacity:.7">(%d)</span></a>',
            esc_url( $baseUrl ),
            esc_attr( $allClass ),
            (int) $metrics['total']
        );

        foreach ( self::STAGE_LABELS as $stage => $label ) {
            $count     = $metrics['by_stage'][ $stage ] ?? 0;
            $isActive  = $active === $stage ? 'button-primary' : '';
            $color     = self::STAGE_COLORS[ $stage ] ?? '';
            $dot       = $color ? "<span style='display:inline-block;width:8px;height:8px;border-radius:50%;background:{$color};margin-right:4px'></span>" : '';
            printf(
                '<a href="%s" class="button %s">%s%s <span style="font-size:11px;opacity:.7">(%d)</span></a>',
                esc_url( add_query_arg( 'stage', $stage, $baseUrl ) ),
                esc_attr( $isActive ),
                $dot, // already escaped inline HTML
                esc_html( $label ),
                $count
            );
        }

        echo '</div>';
    }

    private function renderTable( array $opportunities, int $tenantId ): void {
        if ( empty( $opportunities ) ) {
            echo '<p>' . esc_html__( 'No hay oportunidades para mostrar.', 'infouno-custom' ) . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        foreach ( [ 'Fecha', 'Lead', 'Contacto', 'Bot', 'Stage', 'Valor estimado', 'Último cambio', 'Acciones' ] as $col ) {
            echo '<th>' . esc_html( $col ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $opportunities as $opp ) {
            $this->renderRow( $opp );
        }

        echo '</tbody></table>';
    }

    private function renderRow( array $opp ): void {
        $oppId     = (int) $opp['id'];
        $stage     = $opp['stage'];
        $isTerminal = in_array( $stage, OpportunityRepository::TERMINAL_STAGES, true );
        $stageLabel = self::STAGE_LABELS[ $stage ] ?? $stage;
        $stageColor = self::STAGE_COLORS[ $stage ] ?? '#646970';

        $value = isset( $opp['estimated_value'] )
            ? '$ ' . number_format( (float) $opp['estimated_value'], 2, ',', '.' ) . ' ' . esc_html( $opp['currency'] ?? 'ARS' )
            : '—';

        $leadName    = $opp['lead_name']    ?? '—';
        $leadEmail   = $opp['lead_email']   ?? '';
        $leadPhone   = $opp['lead_phone']   ?? '';
        $botName     = $opp['bot_name']     ?? '—';
        $stageChanged = isset( $opp['stage_changed_at'] ) ? substr( $opp['stage_changed_at'], 0, 16 ) : '—';
        $created     = isset( $opp['created_at'] ) ? substr( $opp['created_at'], 0, 16 ) : '—';

        $updateUrl = wp_nonce_url(
            admin_url( 'admin-post.php?action=' . self::ACTION_UPDATE . '&opp_id=' . $oppId ),
            self::NONCE_UPDATE . '_' . $oppId
        );

        echo '<tr>';
        echo '<td>' . esc_html( $created ) . '</td>';

        // Lead con link a la sección de leads
        echo '<td>';
        echo esc_html( $leadName );
        if ( ! empty( $opp['lead_id'] ) ) {
            printf(
                ' <a href="%s" style="font-size:11px;color:#646970" title="Ver lead">#%d</a>',
                esc_url( admin_url( 'admin.php?page=infouno-leads' ) ),
                (int) $opp['lead_id']
            );
        }
        echo '</td>';

        // Contacto
        echo '<td style="font-size:12px">';
        if ( $leadEmail ) {
            echo '<a href="' . esc_url( 'mailto:' . $leadEmail ) . '">' . esc_html( $leadEmail ) . '</a><br>';
        }
        echo esc_html( $leadPhone ?: '' );
        echo '</td>';

        echo '<td>' . esc_html( $botName ) . '</td>';

        // Stage con color
        printf(
            '<td><span style="color:%s;font-weight:600">%s</span></td>',
            esc_attr( $stageColor ),
            esc_html( $stageLabel )
        );

        echo '<td>' . esc_html( $value ) . '</td>';
        echo '<td>' . esc_html( $stageChanged ) . '</td>';

        // Acciones: selector de stage inline (solo si no es terminal)
        echo '<td>';
        if ( $isTerminal ) {
            echo '<span style="color:#646970;font-size:12px">' .
                 esc_html__( 'Estado final', 'infouno-custom' ) .
                 '</span>';
        } else {
            echo '<form method="post" action="' . esc_url( $updateUrl ) . '" style="display:flex;gap:4px;align-items:center;">';
            echo '<select name="stage" style="max-width:120px">';
            foreach ( self::STAGE_LABELS as $s => $lbl ) {
                $sel = selected( $stage, $s, false );
                echo '<option value="' . esc_attr( $s ) . '"' . $sel . '>' . esc_html( $lbl ) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="button button-small">✓</button>';
            echo '</form>';
        }
        echo '</td>';

        echo '</tr>';
    }

    // ── Handler de POST ───────────────────────────────────────────────────────

    public function updateStage(): void {
        $oppId = (int) ( $_GET['opp_id'] ?? 0 );

        if ( ! check_admin_referer( self::NONCE_UPDATE . '_' . $oppId ) ) {
            wp_die( esc_html__( 'Nonce inválido.', 'infouno-custom' ), 403 );
        }

        $tenantId = $this->getCurrentTenantId();
        if ( ! $tenantId || ! $oppId ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
            exit();
        }

        $newStage = sanitize_text_field( (string) ( $_POST['stage'] ?? '' ) );
        if ( ! in_array( $newStage, OpportunityRepository::STAGES, true ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
            exit();
        }

        // Verificar que no sea terminal antes de actualizar
        $opp = $this->opportunityRepo->getById( $oppId, $tenantId );
        if ( $opp && ! in_array( $opp['stage'], OpportunityRepository::TERMINAL_STAGES, true ) ) {
            $this->opportunityRepo->updateStage( $oppId, $tenantId, $newStage );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&updated=1' ) );
        exit();
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    /**
     * Obtiene oportunidades con datos del lead (nombre, contacto, bot) en una sola query.
     */
    private function getOpportunitiesWithLeadData( int $tenantId, ?string $stage ): array {
        global $wpdb;

        $oppTable   = $wpdb->prefix . 'infouno_opportunities';
        $leadsTable = $wpdb->prefix . 'infouno_leads';
        $botsTable  = $wpdb->prefix . 'infouno_bots';

        $where = $wpdb->prepare( 'WHERE o.tenant_id = %d', $tenantId );
        if ( $stage !== null && in_array( $stage, OpportunityRepository::STAGES, true ) ) {
            $where .= $wpdb->prepare( ' AND o.stage = %s', $stage );
        }

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.id, o.lead_id, o.bot_id, o.stage, o.estimated_value, o.currency,
                        o.lost_reason, o.stage_changed_at, o.won_at, o.lost_at, o.created_at,
                        l.name  AS lead_name,
                        l.email AS lead_email,
                        l.phone AS lead_phone,
                        b.bot_name
                 FROM `{$oppTable}` o
                 LEFT JOIN `{$leadsTable}` l ON l.id = o.lead_id AND l.tenant_id = o.tenant_id
                 LEFT JOIN `{$botsTable}`  b ON b.id = o.bot_id  AND b.tenant_id = o.tenant_id
                 {$where}
                 ORDER BY FIELD(o.stage,'new','contacted','interested','quoted','lost','won'),
                          o.created_at DESC
                 LIMIT %d",
                100
            ),
            ARRAY_A
        ) ?: [];
    }

    private function getCurrentTenantId(): int {
        $tenant = $this->tenantManager->getForCurrentUser();
        return $tenant ? (int) $tenant['id'] : 0;
    }
}
