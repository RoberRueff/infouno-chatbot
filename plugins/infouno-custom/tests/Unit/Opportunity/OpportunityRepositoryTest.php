<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Opportunity;

use Infouno\SaaS\Opportunity\OpportunityRepository;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use PHPUnit\Framework\TestCase;

final class OpportunityRepositoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->prefix          = 'wp_';
        $GLOBALS['wpdb']->stub_get_var    = null;
        $GLOBALS['wpdb']->stub_get_row    = null;
        $GLOBALS['wpdb']->stub_get_results = [];
        $GLOBALS['wpdb']->last_query      = '';
        $GLOBALS['wpdb']->last_update_where = [];
        $GLOBALS['wpdb']->onInsert        = null;
        $GLOBALS['wpdb']->insert_id       = 0;
    }

    public function test_create_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->create( [ 'tenant_id' => 0, 'lead_id' => 1, 'bot_id' => 1 ] );
    }

    public function test_getActiveByLead_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->getActiveByLead( 1, 0 );
    }

    public function test_getById_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->getById( 1, 0 );
    }

    public function test_listForTenant_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->listForTenant( 0 );
    }

    public function test_countForTenant_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->countForTenant( 0 );
    }

    public function test_getPipelineMetrics_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->getPipelineMetrics( 0 );
    }

    public function test_updateStage_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->updateStage( 1, 0, 'contacted' );
    }

    public function test_updateValue_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->updateValue( 1, 0, 100.0 );
    }

    public function test_logAutomation_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new OpportunityRepository() )->logAutomation( 0, 'email' );
    }

    public function test_getById_query_includes_tenant_filter(): void {
        $GLOBALS['wpdb']->stub_get_row = [ 'id' => 5 ];
        ( new OpportunityRepository() )->getById( 5, 3 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'infouno_opportunities', $q );
        $this->assertStringContainsString( 'id = 5', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
    }

    public function test_getActiveByLead_excludes_terminal_stages(): void {
        $GLOBALS['wpdb']->stub_get_row = null;
        ( new OpportunityRepository() )->getActiveByLead( 7, 3 );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'lead_id = 7', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
        $this->assertStringContainsString( "NOT IN ('won', 'lost')", $q );
    }

    public function test_countForTenant_with_stage_filters_both(): void {
        $GLOBALS['wpdb']->stub_get_var = '4';
        $count = ( new OpportunityRepository() )->countForTenant( 3, 'quoted' );
        $this->assertSame( 4, $count );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'tenant_id = 3', $q );
        $this->assertStringContainsString( "stage = 'quoted'", $q );
    }

    public function test_create_inserts_with_tenant_and_returns_id(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$captured ) {
            $captured = [ 'table' => $table, 'data' => $data ];
        };
        $GLOBALS['wpdb']->insert_id = 42;
        $id = ( new OpportunityRepository() )->create( [
            'tenant_id' => 3, 'lead_id' => 7, 'bot_id' => 5,
        ] );
        $this->assertSame( 42, $id );
        $this->assertSame( 'wp_infouno_opportunities', $captured['table'] );
        $this->assertSame( 3, $captured['data']['tenant_id'] );
        $this->assertSame( 'new', $captured['data']['stage'] );
    }

    public function test_updateValue_where_includes_tenant(): void {
        ( new OpportunityRepository() )->updateValue( 9, 3, 250.0, 'usd' );
        $this->assertSame( [ 'id' => 9, 'tenant_id' => 3 ], $GLOBALS['wpdb']->last_update_where );
    }
}
