<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Tenant;

use Infouno\SaaS\Persistence\MissingTenantScopeException;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class TenantManagerRequireTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->stub_get_row = null;
    }

    public function test_requireForCurrentUser_throws_when_no_tenant(): void {
        $GLOBALS['wpdb']->stub_get_row = null;

        $manager = new TenantManager();

        $this->expectException( MissingTenantScopeException::class );
        $this->expectExceptionMessage( 'Sin tenant activo en contexto autenticado.' );

        $manager->requireForCurrentUser();
    }

    public function test_requireForCurrentUser_returns_tenant_array_when_found(): void {
        $tenantRow = [
            'id'          => 7,
            'user_id'     => 42,
            'status'      => 'active',
            'plan'        => 'premium',
            'quota_used'  => 100,
            'quota_limit' => 2000000,
        ];
        $GLOBALS['wpdb']->stub_get_row = $tenantRow;

        $manager = new TenantManager();
        $tenant  = $manager->requireForCurrentUser();

        $this->assertSame( 7,         (int) $tenant['id'] );
        $this->assertSame( 'active',  $tenant['status'] );
        $this->assertSame( 'premium', $tenant['plan'] );
    }

    public function test_requireForCurrentUser_returns_id_accessible(): void {
        $GLOBALS['wpdb']->stub_get_row = [ 'id' => 3, 'user_id' => 99, 'status' => 'active', 'plan' => 'free', 'quota_used' => 0, 'quota_limit' => 50000 ];

        $manager  = new TenantManager();
        $tenant   = $manager->requireForCurrentUser();

        $this->assertSame( 3, (int) $tenant['id'] );
    }
}
