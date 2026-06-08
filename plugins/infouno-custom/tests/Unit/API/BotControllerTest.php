<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\API;

use Infouno\SaaS\API\BotController;
use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class BotControllerTest extends TestCase {

    private function botManagerReturningBot(): BotManager {
        $bm = $this->createMock( BotManager::class );
        $bm->method( 'getById' )->willReturn( [ 'id' => 9, 'tenant_id' => 3, 'bot_name' => 'X' ] );
        return $bm;
    }

    private function tenantManager(): TenantManager {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'getForCurrentUser' )->willReturn( [ 'id' => 3, 'status' => 'active' ] );
        return $tm;
    }

    private function request( array $params ): \WP_REST_Request {
        $req = new \WP_REST_Request();
        $req['id'] = 9;
        $req->set_param( 'id', 9 );
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return $req;
    }

    /** wizard_data que pasa PromptBuilder::validate(): company_name no vacío + al menos un producto. */
    private function validWizardData(): array {
        return [
            'company_name' => 'Acme SA',
            'products'     => [ 'Producto Uno' ],
        ];
    }

    public function test_wizard_with_save_true_persists_via_manager(): void {
        $bm = $this->botManagerReturningBot();
        $bm->expects( $this->once() )->method( 'saveWizardResult' )
            ->with( 9, 3, $this->isType( 'string' ), $this->isType( 'array' ) )
            ->willReturn( true );

        $ctrl = new BotController( $bm, $this->tenantManager() );
        $resp = $ctrl->wizard( $this->request( [ 'wizard_data' => $this->validWizardData(), 'save' => true ] ) );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertTrue( $resp->get_data()['saved'] );
    }

    public function test_wizard_with_save_false_does_not_persist(): void {
        $bm = $this->botManagerReturningBot();
        $bm->expects( $this->never() )->method( 'saveWizardResult' );

        $ctrl = new BotController( $bm, $this->tenantManager() );
        $resp = $ctrl->wizard( $this->request( [ 'wizard_data' => $this->validWizardData(), 'save' => false ] ) );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertFalse( $resp->get_data()['saved'] );
        $this->assertArrayHasKey( 'system_prompt', $resp->get_data() );
    }

    public function test_wizard_with_invalid_data_returns_422_and_does_not_persist(): void {
        $bm = $this->botManagerReturningBot();
        $bm->expects( $this->never() )->method( 'saveWizardResult' );

        $ctrl = new BotController( $bm, $this->tenantManager() );
        // wizard_data vacío → PromptBuilder::validate() devuelve errores → 422.
        $resp = $ctrl->wizard( $this->request( [ 'wizard_data' => [], 'save' => true ] ) );

        $this->assertInstanceOf( \WP_Error::class, $resp );
        $this->assertSame( 422, $resp->get_error_data()['status'] );
    }
}
