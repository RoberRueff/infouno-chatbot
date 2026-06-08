<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Lead;

use Infouno\SaaS\Lead\LeadRepository;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use PHPUnit\Framework\TestCase;

/**
 * Tests de LeadRepository — métodos tenant-scoped post-refactor.
 *
 * Estrategia: WpdbStub del bootstrap. Se verifica que el SQL preparado
 * contiene tenant_id (o bot_id donde corresponde) y las columnas correctas.
 * Los métodos que lanzan guardScope() se prueban con tenantId=0.
 */
final class LeadRepositoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->stub_get_row     = null;
        $GLOBALS['wpdb']->stub_get_var     = null;
        $GLOBALS['wpdb']->stub_get_results = [];
        $GLOBALS['wpdb']->last_query       = '';
        $GLOBALS['wpdb']->onInsert         = null;
        $GLOBALS['wpdb']->insert_id        = 0;
    }

    // ── listForTenant ─────────────────────────────────────────────────────────

    public function test_listForTenant_throws_on_zero_tenant(): void {
        $repo = new LeadRepository();

        $this->expectException( MissingTenantScopeException::class );

        $repo->listForTenant( tenantId: 0, status: null, limit: 50, offset: 0 );
    }

    public function test_listForTenant_no_status_filter_includes_tenant_id_in_query(): void {
        $GLOBALS['wpdb']->stub_get_results = [];

        $repo = new LeadRepository();
        $repo->listForTenant( tenantId: 3, status: null, limit: 50, offset: 0 );

        $this->assertStringContainsString( '3', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( 'tenant_id', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( 'infouno_leads', $GLOBALS['wpdb']->last_query );
    }

    public function test_listForTenant_with_status_filter_includes_status_in_query(): void {
        $GLOBALS['wpdb']->stub_get_results = [];

        $repo = new LeadRepository();
        $repo->listForTenant( tenantId: 5, status: 'contacted', limit: 100, offset: 0 );

        $this->assertStringContainsString( 'contacted', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( '5', $GLOBALS['wpdb']->last_query );
    }

    public function test_listForTenant_includes_bot_join(): void {
        $GLOBALS['wpdb']->stub_get_results = [];

        $repo = new LeadRepository();
        $repo->listForTenant( tenantId: 1, status: null, limit: 50, offset: 0 );

        $this->assertStringContainsString( 'infouno_bots', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( 'bot_name', $GLOBALS['wpdb']->last_query );
    }

    public function test_listForTenant_returns_empty_array_when_no_results(): void {
        $GLOBALS['wpdb']->stub_get_results = [];

        $repo   = new LeadRepository();
        $result = $repo->listForTenant( tenantId: 1, status: null, limit: 50, offset: 0 );

        $this->assertSame( [], $result );
    }

    // ── verifyOwnership ───────────────────────────────────────────────────────

    public function test_verifyOwnership_throws_on_zero_tenant(): void {
        $repo = new LeadRepository();

        $this->expectException( MissingTenantScopeException::class );

        $repo->verifyOwnership( leadId: 1, tenantId: 0 );
    }

    public function test_verifyOwnership_returns_true_when_row_found(): void {
        $GLOBALS['wpdb']->stub_get_var = '42';

        $repo   = new LeadRepository();
        $result = $repo->verifyOwnership( leadId: 42, tenantId: 3 );

        $this->assertTrue( $result );
        $this->assertStringContainsString( 'tenant_id', $GLOBALS['wpdb']->last_query );
    }

    public function test_verifyOwnership_returns_false_when_not_found(): void {
        $GLOBALS['wpdb']->stub_get_var = null;

        $repo   = new LeadRepository();
        $result = $repo->verifyOwnership( leadId: 99, tenantId: 3 );

        $this->assertFalse( $result );
    }

    // ── updateStatusForTenant ─────────────────────────────────────────────────

    public function test_updateStatusForTenant_throws_on_zero_tenant(): void {
        $repo = new LeadRepository();

        $this->expectException( MissingTenantScopeException::class );

        $repo->updateStatusForTenant( leadId: 1, tenantId: 0, status: 'contacted', notes: null );
    }

    public function test_updateStatusForTenant_scopes_where_by_tenant_and_sets_status(): void {
        $repo = new LeadRepository();
        $repo->updateStatusForTenant( leadId: 5, tenantId: 3, status: 'converted', notes: null );

        // El WHERE del UPDATE es el punto de aislamiento crítico: debe filtrar por tenant_id.
        $this->assertSame( 5, $GLOBALS['wpdb']->last_update_where['id'] );
        $this->assertSame( 3, $GLOBALS['wpdb']->last_update_where['tenant_id'] );
        $this->assertSame( 'converted', $GLOBALS['wpdb']->last_update_data['status'] );
        // status 'converted' agrega timestamp converted_at.
        $this->assertArrayHasKey( 'converted_at', $GLOBALS['wpdb']->last_update_data );
    }

    public function test_updateStatusForTenant_contacted_sets_timestamp(): void {
        $repo = new LeadRepository();
        $repo->updateStatusForTenant( leadId: 1, tenantId: 2, status: 'contacted', notes: null );

        $this->assertArrayHasKey( 'contacted_at', $GLOBALS['wpdb']->last_update_data );
        $this->assertSame( 2, $GLOBALS['wpdb']->last_update_where['tenant_id'] );
        $this->assertArrayNotHasKey( 'converted_at', $GLOBALS['wpdb']->last_update_data );
    }

    // ── listForCsv ────────────────────────────────────────────────────────────

    public function test_listForCsv_throws_on_zero_tenant(): void {
        $repo = new LeadRepository();

        $this->expectException( MissingTenantScopeException::class );

        $repo->listForCsv( tenantId: 0 );
    }

    public function test_listForCsv_query_includes_tenant_and_bot_join(): void {
        $GLOBALS['wpdb']->stub_get_results = [];

        $repo = new LeadRepository();
        $repo->listForCsv( tenantId: 4 );

        $this->assertStringContainsString( 'tenant_id', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( 'infouno_bots', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( 'bot_name', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( '4', $GLOBALS['wpdb']->last_query );
    }

    // ── getConsentsForSession ─────────────────────────────────────────────────

    public function test_getConsentsForSession_throws_on_zero_bot(): void {
        $repo = new LeadRepository();

        $this->expectException( MissingTenantScopeException::class );

        $repo->getConsentsForSession( sessionId: 'abc', botId: 0 );
    }

    public function test_getConsentsForSession_returns_empty_when_no_row(): void {
        $GLOBALS['wpdb']->stub_get_row = null;

        $repo   = new LeadRepository();
        $result = $repo->getConsentsForSession( sessionId: 'sess-abc', botId: 3 );

        $this->assertSame( [], $result );
    }

    public function test_getConsentsForSession_returns_empty_when_all_flags_zero(): void {
        $GLOBALS['wpdb']->stub_get_row = [
            'can_capture_name'  => 0,
            'can_capture_phone' => 0,
            'can_capture_email' => 0,
        ];

        $repo   = new LeadRepository();
        $result = $repo->getConsentsForSession( sessionId: 'sess-abc', botId: 3 );

        $this->assertSame( [], $result );
    }

    public function test_getConsentsForSession_returns_row_when_any_flag_set(): void {
        $GLOBALS['wpdb']->stub_get_row = [
            'can_capture_name'  => 1,
            'can_capture_phone' => 0,
            'can_capture_email' => 1,
        ];

        $repo   = new LeadRepository();
        $result = $repo->getConsentsForSession( sessionId: 'sess-xyz', botId: 7 );

        $this->assertSame( 1, (int) $result['can_capture_name'] );
        $this->assertSame( 1, (int) $result['can_capture_email'] );
    }

    public function test_getConsentsForSession_query_includes_bot_id(): void {
        $GLOBALS['wpdb']->stub_get_row = null;

        $repo = new LeadRepository();
        $repo->getConsentsForSession( sessionId: 'sess-test', botId: 9 );

        $this->assertStringContainsString( 'bot_id', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( 'infouno_lead_consents', $GLOBALS['wpdb']->last_query );
    }
}
