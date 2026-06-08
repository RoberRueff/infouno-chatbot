<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Orquesta el alta y la reconciliación de suscripciones MercadoPago.
 *
 * reconcileFromNotification es el corazón del Enfoque A: el caller ya validó la
 * firma; acá se trae el recurso autoritativo de MP y se aplica la transición de
 * estado de forma idempotente (por payment_id) y ordenada (por last_event_ts).
 */
final class SubscriptionService {

    public function __construct(
        private readonly MercadoPagoClientInterface $mp,
        private readonly SubscriptionRepository     $repo,
        private readonly TenantManager              $tenants,
        private readonly string                     $backUrl,
    ) {}

    /**
     * @param array<string,mixed> $tenant Fila del tenant (id requerido).
     * @return string init_point
     * @throws \RuntimeException
     */
    public function createSubscription( array $tenant, string $payerEmail, float $premiumPriceArs ): string {
        $tenantId = (int) $tenant['id'];

        if ( $premiumPriceArs <= 0 ) {
            throw new \RuntimeException( 'Precio premium no configurado.' );
        }
        if ( $this->repo->findActiveForTenant( $tenantId ) !== null ) {
            throw new \RuntimeException( 'already_subscribed' );
        }

        $preapproval = $this->mp->createPreapproval( [
            'reason'         => 'Suscripción Premium infouno',
            'payer_email'    => $payerEmail,
            'back_url'       => $this->backUrl,
            'status'         => 'pending',
            'auto_recurring' => [
                'frequency'          => 1,
                'frequency_type'     => 'months',
                'transaction_amount' => $premiumPriceArs,
                'currency_id'        => 'ARS',
            ],
        ] );

        $preapprovalId = (string) ( $preapproval['id'] ?? '' );
        $initPoint     = (string) ( $preapproval['init_point'] ?? '' );
        if ( '' === $preapprovalId || '' === $initPoint ) {
            throw new \RuntimeException( 'MercadoPago no devolvió preapproval válido.' );
        }

        $this->repo->createPending( $tenantId, $preapprovalId, 'premium', $premiumPriceArs );

        return $initPoint;
    }

    /** Cancela la suscripción en MP. El downgrade a free lo aplica el webhook `cancelled`. */
    public function cancelSubscription( string $preapprovalId ): void {
        $this->mp->cancelPreapproval( $preapprovalId );
    }

    public function reconcileFromNotification( string $type, string $resourceId, int $eventTs ): void {
        if ( 'payment' === $type ) {
            $this->reconcilePayment( $resourceId, $eventTs );
            return;
        }
        if ( 'subscription_preapproval' === $type || 'preapproval' === $type ) {
            $this->reconcilePreapproval( $resourceId, $eventTs );
        }
        // otros topics: no-op
    }

    private function reconcilePreapproval( string $preapprovalId, int $eventTs ): void {
        $resource = $this->mp->getPreapproval( $preapprovalId );
        $status   = (string) ( $resource['status'] ?? '' );

        $sub = $this->repo->findByPreapprovalId( $preapprovalId );
        if ( null === $sub ) {
            return;
        }
        $tenantId = (int) $sub['tenant_id'];
        if ( $eventTs <= (int) ( $sub['last_event_ts'] ?? 0 ) ) {
            return;
        }

        if ( 'authorized' === $status ) {
            $next = $this->normalizeDate( $resource['next_payment_date'] ?? null );
            $this->repo->markAuthorized( $tenantId, $preapprovalId, $eventTs, $next );
            $this->tenants->applyPlanChange( $tenantId, 'premium', 'active' );
        } elseif ( 'cancelled' === $status ) {
            $this->repo->markCancelled( $tenantId, $preapprovalId, $eventTs );
            $this->tenants->applyPlanChange( $tenantId, 'free', 'active' );
        }
    }

    private function reconcilePayment( string $paymentId, int $eventTs ): void {
        if ( $this->repo->paymentEventExists( $paymentId ) ) {
            return;
        }

        $payment       = $this->mp->getPayment( $paymentId );
        $status        = (string) ( $payment['status'] ?? '' );
        $preapprovalId = (string) ( $payment['preapproval_id'] ?? ( $payment['metadata']['preapproval_id'] ?? '' ) );
        $amount        = (float) ( $payment['transaction_amount'] ?? 0 );

        if ( '' === $preapprovalId ) {
            return;
        }
        $sub = $this->repo->findByPreapprovalId( $preapprovalId );
        if ( null === $sub ) {
            return;
        }
        $tenantId = (int) $sub['tenant_id'];

        $this->repo->recordPaymentEvent( $tenantId, $paymentId, $preapprovalId, $status, $amount );

        if ( 'approved' === $status ) {
            $this->repo->updateNextPayment( $tenantId, $preapprovalId, $eventTs, null );
            $this->tenants->applyPlanChange( $tenantId, 'premium', 'active' );
        } elseif ( 'rejected' === $status ) {
            $this->tenants->applyPlanChange( $tenantId, 'premium', 'suspended' );
        }
    }

    private function normalizeDate( ?string $iso ): ?string {
        if ( null === $iso || '' === $iso ) {
            return null;
        }
        $ts = strtotime( $iso );
        return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
    }
}
