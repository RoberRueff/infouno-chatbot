<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Persistence;

use Infouno\SaaS\Persistence\ConsentRepository;
use Infouno\SaaS\Persistence\MissingTenantScopeException;
use PHPUnit\Framework\TestCase;

final class ConsentRepositoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->stub_get_var  = null;
        $GLOBALS['wpdb']->stub_get_row  = null;
        $GLOBALS['wpdb']->last_query    = '';
        $GLOBALS['wpdb']->last_write_query = '';
        $GLOBALS['wpdb']->onInsert      = null;
        $GLOBALS['wpdb']->insert_id     = 0;
    }

    public function test_consentExistsByBot_returns_true_when_row_present(): void {
        $GLOBALS['wpdb']->stub_get_var = '42';
        $repo = new ConsentRepository();
        $this->assertTrue( $repo->consentExistsByBot( 7, 'abc', 'chat' ) );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'bot_id = 7', $q );
        $this->assertStringContainsString( "scope = 'chat'", $q );
        $this->assertStringContainsString( "session_hash = 'abc'", $q );
    }

    public function test_consentExistsByBot_returns_false_when_absent(): void {
        $GLOBALS['wpdb']->stub_get_var = null;
        $this->assertFalse( ( new ConsentRepository() )->consentExistsByBot( 7, 'abc', 'chat' ) );
    }

    public function test_consentExistsByBot_fails_closed_on_zero_bot(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new ConsentRepository() )->consentExistsByBot( 0, 'abc', 'chat' );
    }

    public function test_consentExistsByTenant_uses_tenant_filter_and_count(): void {
        $GLOBALS['wpdb']->stub_get_var = '1';
        $repo = new ConsentRepository();
        $this->assertTrue( $repo->consentExistsByTenant( 3, 'xyz', 'chat' ) );
        $q = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'COUNT(*)', $q );
        $this->assertStringContainsString( 'tenant_id = 3', $q );
        $this->assertStringContainsString( "scope = 'chat'", $q );
    }

    public function test_consentExistsByTenant_false_when_count_zero(): void {
        $GLOBALS['wpdb']->stub_get_var = '0';
        $this->assertFalse( ( new ConsentRepository() )->consentExistsByTenant( 3, 'xyz', 'chat' ) );
    }

    public function test_consentExistsByTenant_fails_closed_on_zero_tenant(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new ConsentRepository() )->consentExistsByTenant( 0, 'xyz', 'chat' );
    }

    public function test_recordConsentRow_web_inserts_into_consents_with_scope(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$captured ) {
            $captured = [ 'table' => $table, 'data' => $data ];
        };
        ( new ConsentRepository() )->recordConsentRow( 3, 7, 'sess', 'lead_capture', '1.0', 'iphash', 'uahash' );
        $this->assertSame( 'wp_infouno_consents', $captured['table'] );
        $this->assertSame( 7, $captured['data']['bot_id'] );
        $this->assertSame( 3, $captured['data']['tenant_id'] );
        $this->assertSame( 'sess', $captured['data']['session_hash'] );
        $this->assertSame( 'lead_capture', $captured['data']['scope'] );
        $this->assertSame( 'iphash', $captured['data']['ip_hash'] );
        $this->assertSame( 'uahash', $captured['data']['user_agent_hash'] );
        $this->assertArrayNotHasKey( 'channel', $captured['data'] );
        $this->assertArrayNotHasKey( 'accepted_at', $captured['data'] );
    }

    public function test_recordConsentRow_channel_sets_channel_and_timestamp(): void {
        $captured = null;
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$captured ) {
            $captured = $data;
        };
        ( new ConsentRepository() )->recordConsentRow( 3, 7, 'sess', 'chat', '1.0', '', '', 'telegram' );
        $this->assertSame( 'telegram', $captured['channel'] );
        $this->assertArrayHasKey( 'accepted_at', $captured );
        $this->assertSame( '', $captured['ip_hash'] );
    }

    public function test_recordConsentRow_fails_closed_on_zero_bot(): void {
        $this->expectException( MissingTenantScopeException::class );
        ( new ConsentRepository() )->recordConsentRow( 3, 0, 'sess', 'chat', '1.0', '', '' );
    }
}
