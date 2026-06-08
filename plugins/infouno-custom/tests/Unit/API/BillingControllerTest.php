<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\API;

use Infouno\SaaS\API\BillingController;
use Infouno\SaaS\Billing\BillingConfig;
use Infouno\SaaS\Billing\SubscriptionService;
use Infouno\SaaS\Billing\WebhookSignatureVerifier;
use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class BillingControllerTest extends TestCase {

    private function request( array $params = [], array $headers = [] ): \WP_REST_Request {
        $req = new \WP_REST_Request();
        foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
        foreach ( $headers as $k => $v ) { $req->set_header( $k, $v ); }
        return $req;
    }

    private function ctrl( $tm, $svc, $verifier, $repo ): BillingController {
        $cfg = $this->createMock( BillingConfig::class );
        $cfg->method( 'premiumPriceArs' )->willReturn( 14900.0 );
        return new BillingController( $tm, $svc, $verifier, $repo, $cfg );
    }

    public function test_subscribe_returns_init_point(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'requireForCurrentUser' )->willReturn( [ 'id' => 3, 'user_id' => 7, 'status' => 'active' ] );
        $svc = $this->createMock( SubscriptionService::class );
        $svc->method( 'createSubscription' )->willReturn( 'https://mp/checkout/abc' );

        $ctrl = $this->ctrl( $tm, $svc, $this->createMock( WebhookSignatureVerifier::class ), $this->createMock( SubscriptionRepository::class ) );
        $resp = $ctrl->subscribe( $this->request() );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertSame( 'https://mp/checkout/abc', $resp->get_data()['init_point'] );
    }

    public function test_subscribe_409_when_already_subscribed(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'requireForCurrentUser' )->willReturn( [ 'id' => 3, 'user_id' => 7 ] );
        $svc = $this->createMock( SubscriptionService::class );
        $svc->method( 'createSubscription' )->willThrowException( new \RuntimeException( 'already_subscribed' ) );

        $ctrl = $this->ctrl( $tm, $svc, $this->createMock( WebhookSignatureVerifier::class ), $this->createMock( SubscriptionRepository::class ) );
        $resp = $ctrl->subscribe( $this->request() );

        $this->assertInstanceOf( \WP_Error::class, $resp );
        $this->assertSame( 409, $resp->get_error_data()['status'] );
    }

    public function test_webhook_invalid_signature_does_not_reconcile(): void {
        $verifier = $this->createMock( WebhookSignatureVerifier::class );
        $verifier->method( 'verify' )->willReturn( false );
        $svc = $this->createMock( SubscriptionService::class );
        $svc->expects( $this->never() )->method( 'reconcileFromNotification' );

        $ctrl = $this->ctrl( $this->createMock( TenantManager::class ), $svc, $verifier, $this->createMock( SubscriptionRepository::class ) );
        $resp = $ctrl->webhook( $this->request(
            [ 'type' => 'payment', 'data' => [ 'id' => 'pay-1' ] ],
            [ 'x-signature' => 'ts=1,v1=bad', 'x-request-id' => 'req-1' ]
        ) );

        $this->assertSame( 200, $resp->get_status() );
    }

    public function test_webhook_valid_signature_reconciles(): void {
        $verifier = $this->createMock( WebhookSignatureVerifier::class );
        $verifier->method( 'verify' )->willReturn( true );
        $svc = $this->createMock( SubscriptionService::class );
        $svc->expects( $this->once() )->method( 'reconcileFromNotification' )->with( 'payment', 'pay-1', $this->anything() );

        $ctrl = $this->ctrl( $this->createMock( TenantManager::class ), $svc, $verifier, $this->createMock( SubscriptionRepository::class ) );
        $resp = $ctrl->webhook( $this->request(
            [ 'type' => 'payment', 'data' => [ 'id' => 'pay-1' ] ],
            [ 'x-signature' => 'ts=1,v1=good', 'x-request-id' => 'req-1' ]
        ) );

        $this->assertSame( 200, $resp->get_status() );
    }

    public function test_subscription_returns_plan_and_status(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'requireForCurrentUser' )->willReturn( [ 'id' => 3, 'plan' => 'premium', 'status' => 'active' ] );
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'findActiveForTenant' )->willReturn( [ 'status' => 'authorized', 'next_payment_at' => '2026-07-08 00:00:00' ] );

        $ctrl = $this->ctrl( $tm, $this->createMock( SubscriptionService::class ), $this->createMock( WebhookSignatureVerifier::class ), $repo );
        $resp = $ctrl->subscription( $this->request() );

        $this->assertSame( 200, $resp->get_status() );
        $data = $resp->get_data();
        $this->assertSame( 'premium', $data['plan'] );
        $this->assertSame( 'authorized', $data['subscription']['status'] );
    }

    public function test_cancel_404_when_no_subscription(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'requireForCurrentUser' )->willReturn( [ 'id' => 3 ] );
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'findActiveForTenant' )->willReturn( null );
        $svc = $this->createMock( SubscriptionService::class );
        $svc->expects( $this->never() )->method( 'cancelSubscription' );

        $ctrl = $this->ctrl( $tm, $svc, $this->createMock( WebhookSignatureVerifier::class ), $repo );
        $resp = $ctrl->cancel( $this->request() );

        $this->assertInstanceOf( \WP_Error::class, $resp );
        $this->assertSame( 404, $resp->get_error_data()['status'] );
    }

    public function test_cancel_invokes_service_when_subscription_exists(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'requireForCurrentUser' )->willReturn( [ 'id' => 3 ] );
        $repo = $this->createMock( SubscriptionRepository::class );
        $repo->method( 'findActiveForTenant' )->willReturn( [ 'mp_preapproval_id' => 'pa-1' ] );
        $svc = $this->createMock( SubscriptionService::class );
        $svc->expects( $this->once() )->method( 'cancelSubscription' )->with( 'pa-1' );

        $ctrl = $this->ctrl( $tm, $svc, $this->createMock( WebhookSignatureVerifier::class ), $repo );
        $resp = $ctrl->cancel( $this->request() );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertTrue( $resp->get_data()['cancelled'] );
    }
}
