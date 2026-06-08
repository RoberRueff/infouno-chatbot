<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Bot;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use PHPUnit\Framework\TestCase;

final class BotManagerTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->prefix          = 'wp_';
        $GLOBALS['wpdb']->stub_get_var    = null;
        $GLOBALS['wpdb']->stub_get_row    = null;
        $GLOBALS['wpdb']->stub_get_results = [];
        $GLOBALS['wpdb']->last_query      = '';
        $GLOBALS['wpdb']->last_update_data  = [];
        $GLOBALS['wpdb']->last_update_where = [];
        $GLOBALS['wpdb']->last_delete_where = [];
        $GLOBALS['wpdb']->onInsert        = null;
        $GLOBALS['wpdb']->insert_id       = 0;
    }

    public function test_create_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->create( 0, [ 'bot_name' => 'X' ] );
    }

    public function test_countForTenant_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->countForTenant( 0 );
    }

    public function test_getById_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->getById( 1, 0 );
    }

    public function test_getAllForTenant_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->getAllForTenant( 0 );
    }

    public function test_update_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->update( 1, 0, [ 'bot_name' => 'X' ] );
    }

    public function test_delete_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->delete( 1, 0 );
    }

    public function test_getByPublicToken_does_not_require_tenant_scope(): void {
        $GLOBALS['wpdb']->stub_get_row = null;
        $this->assertNull( ( new BotManager() )->getByPublicToken( 'abc' ) );
        $this->assertStringContainsString( "public_token = 'abc'", $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( 'is_active = 1', $GLOBALS['wpdb']->last_query );
    }

    public function test_getById_query_includes_tenant_filter(): void {
        $GLOBALS['wpdb']->stub_get_row = null;
        ( new BotManager() )->getById( 5, 3 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'infouno_bots', $q );
        $this->assertStringContainsString( 'id = 5', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
    }

    public function test_countForTenant_query_includes_tenant_filter(): void {
        $GLOBALS['wpdb']->stub_get_var = '2';
        $this->assertSame( 2, ( new BotManager() )->countForTenant( 3 ) );
        $this->assertStringContainsString( 'tenant_id = 3', $GLOBALS['wpdb']->last_query );
    }

    public function test_update_where_includes_tenant(): void {
        ( new BotManager() )->update( 9, 3, [ 'bot_name' => 'Nuevo' ] );
        $this->assertSame( [ 'id' => 9, 'tenant_id' => 3 ], $GLOBALS['wpdb']->last_update_where );
    }

    public function test_delete_where_includes_tenant(): void {
        ( new BotManager() )->delete( 9, 3 );
        $this->assertSame( [ 'id' => 9, 'tenant_id' => 3 ], $GLOBALS['wpdb']->last_delete_where );
    }
}
