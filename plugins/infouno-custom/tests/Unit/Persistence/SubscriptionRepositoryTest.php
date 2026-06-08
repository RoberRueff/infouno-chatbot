<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Persistence;

use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use PHPUnit\Framework\TestCase;

final class SubscriptionRepositoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->prefix           = 'wp_';
        $GLOBALS['wpdb']->stub_get_row     = null;
        $GLOBALS['wpdb']->stub_get_var     = null;
        $GLOBALS['wpdb']->last_query       = '';
        $GLOBALS['wpdb']->last_update_data = [];
        $GLOBALS['wpdb']->last_update_where = [];
        $GLOBALS['wpdb']->onInsert         = null;
        $GLOBALS['wpdb']->insert_id        = 0;
    }

    public function test_createPending_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new SubscriptionRepository() )->createPending( 0, 'pa-1', 'premium', 14900.0 );
    }

    public function test_createPending_inserts_with_tenant_and_preapproval(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $t, array $d ) use ( &$captured ) { $captured = [ 't' => $t, 'd' => $d ]; };
        $GLOBALS['wpdb']->insert_id = 7;
        $id = ( new SubscriptionRepository() )->createPending( 3, 'pa-1', 'premium', 14900.0 );
        $this->assertSame( 7, $id );
        $this->assertSame( 'wp_infouno_subscriptions', $captured['t'] );
        $this->assertSame( 3, $captured['d']['tenant_id'] );
        $this->assertSame( 'pa-1', $captured['d']['mp_preapproval_id'] );
        $this->assertSame( 'pending', $captured['d']['status'] );
    }

    public function test_findByPreapprovalId_filters_by_preapproval(): void {
        $GLOBALS['wpdb']->stub_get_row = [ 'id' => 1, 'tenant_id' => 3 ];
        $row = ( new SubscriptionRepository() )->findByPreapprovalId( 'pa-1' );
        $this->assertSame( 3, (int) $row['tenant_id'] );
        $this->assertStringContainsString( "mp_preapproval_id = 'pa-1'", $GLOBALS['wpdb']->last_query );
    }

    public function test_findActiveForTenant_fails_closed_on_zero(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new SubscriptionRepository() )->findActiveForTenant( 0 );
    }

    public function test_findActiveForTenant_filters_tenant_and_status(): void {
        $GLOBALS['wpdb']->stub_get_row = null;
        ( new SubscriptionRepository() )->findActiveForTenant( 3 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'tenant_id = 3', $q );
        $this->assertStringContainsString( "status IN ('pending','authorized')", $q );
    }

    public function test_markAuthorized_updates_status_scoped_by_tenant(): void {
        ( new SubscriptionRepository() )->markAuthorized( 3, 'pa-1', 1700000000, '2026-07-08 00:00:00' );
        $this->assertSame( 'authorized', $GLOBALS['wpdb']->last_update_data['status'] );
        $this->assertSame( [ 'tenant_id' => 3, 'mp_preapproval_id' => 'pa-1' ], $GLOBALS['wpdb']->last_update_where );
    }

    public function test_markAuthorized_fails_closed_on_zero(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new SubscriptionRepository() )->markAuthorized( 0, 'pa-1', 1, null );
    }

    public function test_markCancelled_sets_status_scoped_by_tenant(): void {
        ( new SubscriptionRepository() )->markCancelled( 3, 'pa-1', 1700000000 );
        $this->assertSame( 'cancelled', $GLOBALS['wpdb']->last_update_data['status'] );
        $this->assertSame( [ 'tenant_id' => 3, 'mp_preapproval_id' => 'pa-1' ], $GLOBALS['wpdb']->last_update_where );
    }

    public function test_markCancelled_fails_closed_on_zero(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new SubscriptionRepository() )->markCancelled( 0, 'pa-1', 1 );
    }

    public function test_updateNextPayment_updates_scoped_by_tenant(): void {
        ( new SubscriptionRepository() )->updateNextPayment( 3, 'pa-1', 1700000000, '2026-08-08 00:00:00' );
        $this->assertSame( '2026-08-08 00:00:00', $GLOBALS['wpdb']->last_update_data['next_payment_at'] );
        $this->assertSame( [ 'tenant_id' => 3, 'mp_preapproval_id' => 'pa-1' ], $GLOBALS['wpdb']->last_update_where );
    }

    public function test_updateNextPayment_fails_closed_on_zero(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new SubscriptionRepository() )->updateNextPayment( 0, 'pa-1', 1, null );
    }

    public function test_recordPaymentEvent_inserts_idempotent_key(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $t, array $d ) use ( &$captured ) { $captured = [ 't' => $t, 'd' => $d ]; };
        ( new SubscriptionRepository() )->recordPaymentEvent( 3, 'pay-1', 'pa-1', 'approved', 14900.0 );
        $this->assertSame( 'wp_infouno_payment_events', $captured['t'] );
        $this->assertSame( 'pay-1', $captured['d']['mp_payment_id'] );
        $this->assertSame( 3, $captured['d']['tenant_id'] );
    }

    public function test_recordPaymentEvent_fails_closed_on_zero(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new SubscriptionRepository() )->recordPaymentEvent( 0, 'pay-1', 'pa-1', 'approved', 1.0 );
    }

    public function test_paymentEventExists_queries_by_payment_id(): void {
        $GLOBALS['wpdb']->stub_get_var = '1';
        $this->assertTrue( ( new SubscriptionRepository() )->paymentEventExists( 'pay-1' ) );
        $this->assertStringContainsString( "mp_payment_id = 'pay-1'", $GLOBALS['wpdb']->last_query );
    }
}
