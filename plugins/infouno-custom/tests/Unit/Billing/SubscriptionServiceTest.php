<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Billing;

use Infouno\SaaS\Billing\MercadoPagoClientInterface;
use Infouno\SaaS\Billing\SubscriptionService;
use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class SubscriptionServiceTest extends TestCase {

    private function client( array $resources ): MercadoPagoClientInterface {
        return new class( $resources ) implements MercadoPagoClientInterface {
            public function __construct( private array $r ) {}
            public function createPreapproval( array $p ): array { return $this->r['create'] ?? []; }
            public function getPreapproval( string $id ): array { return $this->r['preapproval'] ?? []; }
            public function getPayment( string $id ): array { return $this->r['payment'] ?? []; }
            public function cancelPreapproval( string $id ): bool { return true; }
        };
    }

    public function test_reconcile_preapproval_authorized_activates_premium(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'findByPreapprovalId' )->willReturn( [ 'id' => 1, 'tenant_id' => 3, 'last_event_ts' => 0 ] );
        $repo->expects( $this->once() )->method( 'markAuthorized' )->with( 3, 'pa-1', $this->anything(), $this->anything() );

        $tm = $this->createMock( TenantManager::class );
        $tm->expects( $this->once() )->method( 'applyPlanChange' )->with( 3, 'premium', 'active' );

        $client = $this->client( [ 'preapproval' => [ 'id' => 'pa-1', 'status' => 'authorized', 'next_payment_date' => '2026-07-08T00:00:00Z' ] ] );
        $svc = new SubscriptionService( $client, $repo, $tm, 'https://site/back' );

        $svc->reconcileFromNotification( 'subscription_preapproval', 'pa-1', 1_700_000_100 );
    }

    public function test_reconcile_payment_rejected_suspends(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'paymentEventExists' )->willReturn( false );
        $repo->method( 'findByPreapprovalId' )->willReturn( [ 'id' => 1, 'tenant_id' => 3, 'last_event_ts' => 0 ] );
        $repo->expects( $this->once() )->method( 'recordPaymentEvent' );

        $tm = $this->createMock( TenantManager::class );
        $tm->expects( $this->once() )->method( 'applyPlanChange' )->with( 3, 'premium', 'suspended' );

        $client = $this->client( [ 'payment' => [ 'id' => 'pay-1', 'status' => 'rejected', 'transaction_amount' => 14900, 'preapproval_id' => 'pa-1' ] ] );
        $svc = new SubscriptionService( $client, $repo, $tm, 'https://site/back' );

        $svc->reconcileFromNotification( 'payment', 'pay-1', 1_700_000_100 );
    }

    public function test_reconcile_payment_idempotent_skips_when_already_processed(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'paymentEventExists' )->willReturn( true );
        $repo->expects( $this->never() )->method( 'recordPaymentEvent' );

        $tm = $this->createMock( TenantManager::class );
        $tm->expects( $this->never() )->method( 'applyPlanChange' );

        $client = $this->client( [ 'payment' => [ 'id' => 'pay-1', 'status' => 'approved', 'preapproval_id' => 'pa-1' ] ] );
        $svc = new SubscriptionService( $client, $repo, $tm, 'https://site/back' );

        $svc->reconcileFromNotification( 'payment', 'pay-1', 1_700_000_100 );
    }

    public function test_reconcile_preapproval_cancelled_downgrades_to_free(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'findByPreapprovalId' )->willReturn( [ 'id' => 1, 'tenant_id' => 3, 'last_event_ts' => 0 ] );
        $repo->expects( $this->once() )->method( 'markCancelled' );

        $tm = $this->createMock( TenantManager::class );
        $tm->expects( $this->once() )->method( 'applyPlanChange' )->with( 3, 'free', 'active' );

        $client = $this->client( [ 'preapproval' => [ 'id' => 'pa-1', 'status' => 'cancelled' ] ] );
        $svc = new SubscriptionService( $client, $repo, $tm, 'https://site/back' );

        $svc->reconcileFromNotification( 'subscription_preapproval', 'pa-1', 1_700_000_100 );
    }

    public function test_reconcile_ignores_stale_event(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'findByPreapprovalId' )->willReturn( [ 'id' => 1, 'tenant_id' => 3, 'last_event_ts' => 1_700_000_500 ] );
        $repo->expects( $this->never() )->method( 'markAuthorized' );

        $tm = $this->createMock( TenantManager::class );
        $tm->expects( $this->never() )->method( 'applyPlanChange' );

        $client = $this->client( [ 'preapproval' => [ 'id' => 'pa-1', 'status' => 'authorized' ] ] );
        $svc = new SubscriptionService( $client, $repo, $tm, 'https://site/back' );

        // ts del evento (1_700_000_100) < last_event_ts (1_700_000_500) → ignora.
        $svc->reconcileFromNotification( 'subscription_preapproval', 'pa-1', 1_700_000_100 );
    }

    public function test_createSubscription_returns_init_point_and_persists_pending(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'findActiveForTenant' )->willReturn( null );
        $repo->expects( $this->once() )->method( 'createPending' )->with( 3, 'pa-9', 'premium', 14900.0 );

        $tm = $this->createMock( TenantManager::class );
        $client = $this->client( [ 'create' => [ 'id' => 'pa-9', 'init_point' => 'https://mp/go' ] ] );
        $svc = new SubscriptionService( $client, $repo, $tm, 'https://site/back' );

        $initPoint = $svc->createSubscription( [ 'id' => 3 ], 'owner@example.com', 14900.0 );
        $this->assertSame( 'https://mp/go', $initPoint );
    }

    public function test_createSubscription_throws_when_already_subscribed(): void {
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'findActiveForTenant' )->willReturn( [ 'id' => 1, 'status' => 'authorized' ] );

        $svc = new SubscriptionService( $this->client( [] ), $repo, $this->createMock( TenantManager::class ), 'https://site/back' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'already_subscribed' );
        $svc->createSubscription( [ 'id' => 3 ], 'owner@example.com', 14900.0 );
    }
}
