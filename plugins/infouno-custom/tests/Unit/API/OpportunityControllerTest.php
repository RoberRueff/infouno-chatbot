<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\API;

use Infouno\SaaS\API\OpportunityController;
use Infouno\SaaS\Opportunity\OpportunityRepository;
use Infouno\SaaS\Opportunity\OpportunityService;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class OpportunityControllerTest extends TestCase {

    /** Fila completa de oportunidad (todas las claves que sanitizeOutput lee). */
    private function fullOpp( int $id = 99 ): array {
        return [
            'id' => $id, 'tenant_id' => 3, 'lead_id' => 7, 'bot_id' => 5,
            'stage' => 'new', 'estimated_value' => 100.0, 'currency' => 'ARS',
            'assigned_to' => null, 'notes' => null, 'lost_reason' => null,
            'stage_changed_at' => '2026-06-07 00:00:00', 'won_at' => null,
            'lost_at' => null, 'created_at' => '2026-06-07 00:00:00',
            'updated_at' => '2026-06-07 00:00:00',
        ];
    }

    private function tenantManager(): TenantManager {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'getForCurrentUser' )->willReturn( [ 'id' => 3, 'status' => 'active' ] );
        return $tm;
    }

    private function request( array $params ): \WP_REST_Request {
        $req = new \WP_REST_Request();
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return $req;
    }

    public function test_store_returns_404_when_lead_absent(): void {
        $repo = $this->createMock( OpportunityRepository::class );
        $repo->method( 'getLeadSnapshotForTenant' )->willReturn( null );
        $repo->expects( $this->never() )->method( 'create' );

        $ctrl = new OpportunityController( $this->createMock( OpportunityService::class ), $repo, $this->tenantManager() );
        $resp = $ctrl->store( $this->request( [ 'lead_id' => 7 ] ) );

        $this->assertInstanceOf( \WP_Error::class, $resp );
        $this->assertSame( 404, $resp->get_error_data()['status'] );
    }

    public function test_store_returns_422_when_score_below_threshold(): void {
        $repo = $this->createMock( OpportunityRepository::class );
        $repo->method( 'getLeadSnapshotForTenant' )->willReturn( [
            'score'  => OpportunityService::QUALIFIED_THRESHOLD - 1,
            'bot_id' => 5,
        ] );
        $repo->expects( $this->never() )->method( 'create' );

        $ctrl = new OpportunityController( $this->createMock( OpportunityService::class ), $repo, $this->tenantManager() );
        $resp = $ctrl->store( $this->request( [ 'lead_id' => 7 ] ) );

        $this->assertInstanceOf( \WP_Error::class, $resp );
        $this->assertSame( 422, $resp->get_error_data()['status'] );
    }

    public function test_store_returns_existing_active_opportunity_200(): void {
        $repo = $this->createMock( OpportunityRepository::class );
        $repo->method( 'getLeadSnapshotForTenant' )->willReturn( [
            'score'  => OpportunityService::QUALIFIED_THRESHOLD,
            'bot_id' => 5,
        ] );
        $repo->method( 'getActiveByLead' )->willReturn( $this->fullOpp( 12 ) );
        $repo->expects( $this->never() )->method( 'create' );

        $ctrl = new OpportunityController( $this->createMock( OpportunityService::class ), $repo, $this->tenantManager() );
        $resp = $ctrl->store( $this->request( [ 'lead_id' => 7 ] ) );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertSame( 12, $resp->get_data()['id'] );
    }

    public function test_store_creates_opportunity_201(): void {
        $repo = $this->createMock( OpportunityRepository::class );
        $repo->method( 'getLeadSnapshotForTenant' )->willReturn( [
            'score'  => OpportunityService::QUALIFIED_THRESHOLD,
            'bot_id' => 5,
        ] );
        $repo->method( 'getActiveByLead' )->willReturn( null );
        $repo->expects( $this->once() )->method( 'create' )
            ->with( $this->callback( static fn( $d ) => $d['tenant_id'] === 3 && $d['lead_id'] === 7 && $d['bot_id'] === 5 ) )
            ->willReturn( 99 );
        $repo->method( 'getById' )->willReturn( $this->fullOpp( 99 ) );

        $ctrl = new OpportunityController( $this->createMock( OpportunityService::class ), $repo, $this->tenantManager() );
        $resp = $ctrl->store( $this->request( [ 'lead_id' => 7 ] ) );

        $this->assertSame( 201, $resp->get_status() );
        $this->assertSame( 99, $resp->get_data()['id'] );
    }

    public function test_store_returns_500_when_create_fails(): void {
        $repo = $this->createMock( OpportunityRepository::class );
        $repo->method( 'getLeadSnapshotForTenant' )->willReturn( [
            'score'  => OpportunityService::QUALIFIED_THRESHOLD,
            'bot_id' => 5,
        ] );
        $repo->method( 'getActiveByLead' )->willReturn( null );
        $repo->method( 'create' )->willReturn( 0 );
        $repo->expects( $this->never() )->method( 'getById' );

        $ctrl = new OpportunityController( $this->createMock( OpportunityService::class ), $repo, $this->tenantManager() );
        $resp = $ctrl->store( $this->request( [ 'lead_id' => 7 ] ) );

        $this->assertInstanceOf( \WP_Error::class, $resp );
        $this->assertSame( 500, $resp->get_error_data()['status'] );
    }
}
