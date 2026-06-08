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

    // ── comportamiento de los métodos mutadores (create / update settings) ─

    public function test_create_stamps_tenant_and_merges_settings_over_defaults(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$captured ) {
            $captured = [ 'table' => $table, 'data' => $data ];
        };
        $GLOBALS['wpdb']->insert_id = 55;

        $id = ( new BotManager() )->create( 3, [
            'bot_name' => 'Acme',
            'settings' => [ 'temperature' => 0.2 ],
        ] );

        $this->assertSame( 55, $id );
        $this->assertSame( 'wp_infouno_bots', $captured['table'] );
        $this->assertSame( 3, $captured['data']['tenant_id'] );
        $this->assertSame( 1, $captured['data']['is_active'] );

        $settings = json_decode( $captured['data']['settings'], true );
        $this->assertSame( 0.2, $settings['temperature'] );      // override aplicado
        $this->assertSame( 1024, $settings['max_tokens'] );      // default preservado
    }

    public function test_update_settings_merge_preserves_unspecified_keys(): void {
        // El bot existente tiene temperature=0.7 y max_tokens=2048.
        $GLOBALS['wpdb']->stub_get_row = [
            'id'        => 9,
            'tenant_id' => 3,
            'settings'  => json_encode( [ 'temperature' => 0.7, 'max_tokens' => 2048 ] ),
        ];

        ( new BotManager() )->update( 9, 3, [ 'settings' => [ 'temperature' => 0.2 ] ] );

        $settings = json_decode( $GLOBALS['wpdb']->last_update_data['settings'], true );
        $this->assertSame( 0.2, $settings['temperature'] );   // override aplicado
        $this->assertSame( 2048, $settings['max_tokens'] );   // valor previo preservado
    }

    // ── saveWizardResult ──────────────────────────────────────────────────

    public function test_saveWizardResult_updates_prompt_and_wizard_data_scoped_by_tenant(): void {
        ( new BotManager() )->saveWizardResult( 9, 3, 'PROMPT', [ 'industry' => 'retail' ] );
        $this->assertSame( 'PROMPT', $GLOBALS['wpdb']->last_update_data['system_prompt'] );
        $this->assertArrayHasKey( 'wizard_data', $GLOBALS['wpdb']->last_update_data );
        $this->assertSame( [ 'id' => 9, 'tenant_id' => 3 ], $GLOBALS['wpdb']->last_update_where );
    }

    public function test_saveWizardResult_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->saveWizardResult( 9, 0, 'PROMPT', [] );
    }

    // ── leadCountsForBots ─────────────────────────────────────────────────

    public function test_leadCountsForBots_groups_by_bot_scoped_by_tenant(): void {
        $GLOBALS['wpdb']->stub_get_results = [
            [ 'bot_id' => '5', 'total' => '12' ],
            [ 'bot_id' => '7', 'total' => '3' ],
        ];
        $counts = ( new BotManager() )->leadCountsForBots( [ 5, 7 ], 3 );
        $this->assertSame( [ 5 => 12, 7 => 3 ], $counts );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'infouno_leads', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
        $this->assertStringContainsString( 'GROUP BY bot_id', $q );
    }

    public function test_leadCountsForBots_returns_empty_for_no_bots(): void {
        $this->assertSame( [], ( new BotManager() )->leadCountsForBots( [], 3 ) );
        // No debe ejecutar ninguna query cuando no hay bots.
        $this->assertSame( '', $GLOBALS['wpdb']->last_query );
    }

    public function test_leadCountsForBots_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new BotManager() )->leadCountsForBots( [ 5 ], 0 );
    }
}
