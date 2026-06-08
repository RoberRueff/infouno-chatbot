<?php
declare(strict_types=1);

namespace Infouno\SaaS\Admin;

/**
 * Página de ajustes de billing (MercadoPago): precio premium + credenciales.
 * Persiste en la opción `infouno_billing`. Secretos enmascarados; vacío = conservar.
 */
final class BillingSettings {

    private const PAGE_SLUG  = 'infouno-billing';
    private const OPTION     = 'infouno_billing';
    private const ACTION     = 'infouno_billing_save';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
        add_action( 'admin_post_' . self::ACTION, [ $this, 'handleSave' ] );
    }

    public function addMenuPage(): void {
        add_submenu_page(
            'infouno-dashboard',
            'Billing / MercadoPago',
            'Billing',
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    public function handleSave(): void {
        check_admin_referer( self::ACTION );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'infouno-custom' ), 403 );
        }

        $current = get_option( self::OPTION, [] );
        $current = is_array( $current ) ? $current : [];

        $new = [
            'premium_price_ars' => (float) ( $_POST['premium_price_ars'] ?? 0 ), // phpcs:ignore
            'public_key'        => sanitize_text_field( wp_unslash( $_POST['public_key'] ?? '' ) ), // phpcs:ignore
            'access_token'      => $this->keepIfEmpty( $_POST['access_token'] ?? '', $current['access_token'] ?? '' ),     // phpcs:ignore
            'webhook_secret'    => $this->keepIfEmpty( $_POST['webhook_secret'] ?? '', $current['webhook_secret'] ?? '' ), // phpcs:ignore
        ];

        update_option( self::OPTION, $new );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&updated=1' ) );
        exit;
    }

    private function keepIfEmpty( string $incoming, string $previous ): string {
        $incoming = sanitize_text_field( wp_unslash( $incoming ) );
        return '' !== $incoming ? $incoming : $previous;
    }

    public function renderPage(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $opt   = get_option( self::OPTION, [] );
        $opt   = is_array( $opt ) ? $opt : [];
        $price = (float) ( $opt['premium_price_ars'] ?? 0 );
        $pk    = (string) ( $opt['public_key'] ?? '' );
        $hasToken  = '' !== (string) ( $opt['access_token'] ?? '' );
        $hasSecret = '' !== (string) ( $opt['webhook_secret'] ?? '' );

        echo '<div class="wrap"><h1>' . esc_html__( 'Billing — MercadoPago', 'infouno-custom' ) . '</h1>';
        if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ajustes guardados.', 'infouno-custom' ) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( self::ACTION );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<table class="form-table">';
        printf(
            '<tr><th>%s</th><td><input type="number" step="0.01" min="0" name="premium_price_ars" value="%s" class="regular-text"></td></tr>',
            esc_html__( 'Precio premium (ARS/mes)', 'infouno-custom' ),
            esc_attr( (string) $price )
        );
        printf(
            '<tr><th>%s</th><td><input type="text" name="public_key" value="%s" class="regular-text"></td></tr>',
            esc_html__( 'Public Key', 'infouno-custom' ),
            esc_attr( $pk )
        );
        printf(
            '<tr><th>%s</th><td><input type="password" name="access_token" placeholder="%s" class="regular-text" autocomplete="new-password"></td></tr>',
            esc_html__( 'Access Token', 'infouno-custom' ),
            esc_attr( $hasToken ? '•••• (configurado — dejar vacío para conservar)' : 'sin configurar' )
        );
        printf(
            '<tr><th>%s</th><td><input type="password" name="webhook_secret" placeholder="%s" class="regular-text" autocomplete="new-password"></td></tr>',
            esc_html__( 'Webhook Secret', 'infouno-custom' ),
            esc_attr( $hasSecret ? '•••• (configurado — dejar vacío para conservar)' : 'sin configurar' )
        );
        echo '</table>';
        submit_button( esc_html__( 'Guardar', 'infouno-custom' ) );
        echo '</form></div>';
    }
}
