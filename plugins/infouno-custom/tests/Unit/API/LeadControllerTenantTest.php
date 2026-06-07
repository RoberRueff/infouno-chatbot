<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\API;

use Infouno\SaaS\API\LeadController;
use Infouno\SaaS\Lead\LeadRepository;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LeadControllerTenantTest extends TestCase {

    private TenantManager&MockObject  $tenantManager;
    private LeadRepository&MockObject $repo;
    private LeadController            $controller;

    protected function setUp(): void {
        $this->tenantManager = $this->createMock( TenantManager::class );
        $this->repo          = $this->createMock( LeadRepository::class );
        $this->controller    = new LeadController( $this->tenantManager, $this->repo );
    }

    public function test_requireTenantId_throws_MissingTenantScopeException_when_no_tenant(): void {
        $this->tenantManager
            ->method( 'requireForCurrentUser' )
            ->willThrowException( new MissingTenantScopeException( 'Sin tenant activo.' ) );

        $request = new \WP_REST_Request();
        $request->set_param( 'page', 1 );

        $response = $this->controller->index( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 500, $response->get_status() );
    }

    public function test_index_returns_leads_from_repo_when_tenant_exists(): void {
        $this->tenantManager
            ->method( 'requireForCurrentUser' )
            ->willReturn( [ 'id' => 3, 'status' => 'active' ] );

        $this->repo
            ->method( 'listForTenant' )
            ->willReturn( [
                [ 'id' => 1, 'name' => 'Ana', 'status' => 'new', 'score' => 80 ],
            ] );

        $request = new \WP_REST_Request();
        $request->set_param( 'page', 1 );

        $response = $this->controller->index( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $response->get_data() );
    }

    public function test_updateStatus_returns_404_when_lead_not_owned_by_tenant(): void {
        $this->tenantManager
            ->method( 'requireForCurrentUser' )
            ->willReturn( [ 'id' => 3, 'status' => 'active' ] );

        $this->repo
            ->method( 'verifyOwnership' )
            ->willReturn( false );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', 99 );
        $request->set_param( 'status', 'contacted' );

        $result = $this->controller->updateStatus( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_updateStatus_returns_200_when_lead_owned(): void {
        $this->tenantManager
            ->method( 'requireForCurrentUser' )
            ->willReturn( [ 'id' => 3, 'status' => 'active' ] );

        $this->repo
            ->method( 'verifyOwnership' )
            ->willReturn( true );

        $this->repo
            ->expects( $this->once() )
            ->method( 'updateStatusForTenant' );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', 5 );
        $request->set_param( 'status', 'converted' );

        $response = $this->controller->updateStatus( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
    }
}
