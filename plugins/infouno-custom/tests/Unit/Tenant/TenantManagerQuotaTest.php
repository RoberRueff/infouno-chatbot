<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Tenant;

use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class TenantManagerQuotaTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->stub_query_result = 0;
        $GLOBALS['wpdb']->last_query        = '';
    }

    public function test_reserve_returns_true_when_update_affects_one_row(): void {
        $GLOBALS['wpdb']->stub_query_result = 1;
        $this->assertTrue( ( new TenantManager() )->reserve( 7, 1500 ) );
    }

    public function test_reserve_returns_false_when_no_row_affected(): void {
        $GLOBALS['wpdb']->stub_query_result = 0;
        $this->assertFalse( ( new TenantManager() )->reserve( 7, 1500 ) );
    }

    public function test_reserve_query_is_atomic_conditional(): void {
        $GLOBALS['wpdb']->stub_query_result = 1;
        ( new TenantManager() )->reserve( 7, 1500 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'quota_used = quota_used + 1500', $q );
        $this->assertStringContainsString( 'quota_used + 1500 <= quota_limit', $q );
        $this->assertStringContainsString( "status = 'active'", $q );
    }

    public function test_reconcile_adjusts_reserved_to_actual(): void {
        $GLOBALS['wpdb']->stub_query_result = 1;
        // get_row para el chequeo de alerta 90%
        $GLOBALS['wpdb']->stub_get_row = [ 'quota_used' => 100, 'quota_limit' => 50000 ];
        ( new TenantManager() )->reconcile( 7, 1500, 320 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( '- 1500 + 320', $q );
        $this->assertStringContainsString( 'GREATEST(0', $q );
    }

    public function test_release_subtracts_reserved_floored_at_zero(): void {
        $GLOBALS['wpdb']->stub_query_result = 1;
        ( new TenantManager() )->release( 7, 1500 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'GREATEST(0, quota_used - 1500)', $q );
    }
}
