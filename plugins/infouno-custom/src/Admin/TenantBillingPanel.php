<?php
declare(strict_types=1);

namespace Infouno\SaaS\Admin;

use Infouno\SaaS\Billing\BillingConfig;
use Infouno\SaaS\Billing\SubscriptionService;
use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Panel de suscripción del tenant (WP Admin, capability `read`).
 * Distinto de BillingSettings (superadmin). Permite ver el plan, suscribirse a
 * Premium (redirect al checkout de MP) y cancelar. Sin SQL crudo — delega.
 */
final class TenantBillingPanel {

    private const PAGE_SLUG        = 'infouno-subscription';
    private const ACTION_SUBSCRIBE = 'infouno_subscribe';
    private const ACTION_CANCEL    = 'infouno_cancel_subscription';

    public function __construct(
        private readonly TenantManager          $tenantManager,
        private readonly SubscriptionService    $service,
        private readonly SubscriptionRepository $repository,
        private readonly BillingConfig          $config,
    ) {}

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
        add_action( 'admin_post_' . self::ACTION_SUBSCRIBE, [ $this, 'handleSubscribe' ] );
        add_action( 'admin_post_' . self::ACTION_CANCEL, [ $this, 'handleCancel' ] );
    }

    public function addMenuPage(): void {
        add_submenu_page(
            'infouno-dashboard',
            'Mi Suscripción',
            'Mi Suscripción',
            'read',
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    public function handleSubscribe(): void {
        check_admin_referer( self::ACTION_SUBSCRIBE );

        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            wp_die( esc_html__( 'Sin tenant activo.', 'infouno-custom' ), 403 );
        }

        $user  = get_userdata( (int) ( $tenant['user_id'] ?? 0 ) );
        $email = $user && isset( $user->user_email ) ? (string) $user->user_email : '';

        try {
            $initPoint = $this->service->createSubscription( $tenant, $email, $this->config->premiumPriceArs() );
        } catch ( \RuntimeException $e ) {
            error_log( '[INFOUNO] tenant subscribe error: ' . $e->getMessage() );
            wp_safe_redirect( $this->pageUrl( [ 'error' => 'subscribe' ] ) );
            exit;
        }

        // Host externo (mercadopago.com): wp_redirect, no wp_safe_redirect.
        wp_redirect( $initPoint );
        exit;
    }

    public function handleCancel(): void {
        check_admin_referer( self::ACTION_CANCEL );

        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            wp_die( esc_html__( 'Sin tenant activo.', 'infouno-custom' ), 403 );
        }

        $sub = $this->repository->findActiveForTenant( (int) $tenant['id'] );
        if ( null === $sub ) {
            wp_safe_redirect( $this->pageUrl( [ 'error' => 'no_subscription' ] ) );
            exit;
        }

        $this->service->cancelSubscription( (string) $sub['mp_preapproval_id'] );
        wp_safe_redirect( $this->pageUrl( [ 'subscribed' => 'cancel_requested' ] ) );
        exit;
    }

    public function renderPage(): void {
        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            echo '<div class="wrap"><p>' . esc_html__( 'No tenés un tenant activo.', 'infouno-custom' ) . '</p></div>';
            return;
        }

        $plan   = (string) ( $tenant['plan'] ?? 'free' );
        $status = (string) ( $tenant['status'] ?? 'active' );
        $sub    = $this->repository->findActiveForTenant( (int) $tenant['id'] );

        echo '<div class="wrap"><h1>' . esc_html__( 'Mi Suscripción', 'infouno-custom' ) . '</h1>';
        $this->renderNotices();

        if ( ! $this->config->isConfigured() ) {
            echo '<div class="notice notice-warning"><p>' .
                 esc_html__( 'El administrador aún no configuró los pagos. Volvé más tarde.', 'infouno-custom' ) .
                 '</p></div></div>';
            return;
        }

        if ( 'suspended' === $status ) {
            echo '<div class="notice notice-error"><p>' .
                 esc_html__( 'Tu último pago fue rechazado y tu cuenta está suspendida.', 'infouno-custom' ) .
                 '</p></div>';
            $this->renderCancelButton();
        } elseif ( 'premium' === $plan && $sub && 'authorized' === ( $sub['status'] ?? '' ) ) {
            $next = $sub['next_payment_at'] ?? null;
            echo '<p><strong>' . esc_html__( 'Plan Premium activo', 'infouno-custom' ) . '</strong> — 2.000.000 tokens/mes.</p>';
            if ( $next ) {
                printf( '<p>%s %s</p>', esc_html__( 'Próximo cobro:', 'infouno-custom' ), esc_html( (string) $next ) );
            }
            $this->renderCancelButton();
        } else {
            printf(
                '<p>%s <strong>%s</strong> — 50.000 tokens/mes.</p>',
                esc_html__( 'Plan actual:', 'infouno-custom' ),
                esc_html( ucfirst( $plan ) )
            );
            $this->renderSubscribeButton();
        }

        echo '</div>';
    }

    private function renderSubscribeButton(): void {
        $this->renderActionForm( self::ACTION_SUBSCRIBE, esc_html__( 'Suscribirme a Premium', 'infouno-custom' ), 'button-primary' );
    }

    private function renderCancelButton(): void {
        $this->renderActionForm( self::ACTION_CANCEL, esc_html__( 'Cancelar suscripción', 'infouno-custom' ), 'button' );
    }

    private function renderActionForm( string $action, string $label, string $class ): void {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px">';
        wp_nonce_field( $action );
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
        printf( '<button type="submit" class="button %s">%s</button>', esc_attr( $class ), esc_html( $label ) );
        echo '</form>';
    }

    private function renderNotices(): void {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( ! empty( $_GET['subscribed'] ) && 'cancel_requested' === $_GET['subscribed'] ) {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 esc_html__( 'Cancelación solicitada. Tu plan bajará a Free al confirmarse.', 'infouno-custom' ) .
                 '</p></div>';
        }
        if ( ! empty( $_GET['error'] ) ) {
            $msg = 'no_subscription' === $_GET['error']
                ? esc_html__( 'No hay una suscripción activa para cancelar.', 'infouno-custom' )
                : esc_html__( 'No se pudo iniciar la suscripción. Intentá de nuevo.', 'infouno-custom' );
            echo '<div class="notice notice-error is-dismissible"><p>' . $msg . '</p></div>';
        }
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /** @param array<string,string> $args */
    private function pageUrl( array $args ): string {
        return add_query_arg(
            array_merge( [ 'page' => self::PAGE_SLUG ], $args ),
            admin_url( 'admin.php' )
        );
    }
}
