<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\API;

use Infouno\SaaS\API\ConsentController;
use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Chat\ConversationRepository;
use Infouno\SaaS\Persistence\ConsentRepository;
use PHPUnit\Framework\TestCase;

final class ConsentControllerTest extends TestCase {

    private function bot(): array {
        return [ 'id' => 7, 'tenant_id' => 3 ];
    }

    private function makeRequest( array $params ): \WP_REST_Request {
        $req = new \WP_REST_Request();
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return $req;
    }

    public function test_record_returns_already_consented_when_chat_consent_exists(): void {
        $botMgr = $this->createMock( BotManager::class );
        $botMgr->method( 'getByPublicToken' )->willReturn( $this->bot() );
        $botMgr->method( 'validateOrigin' )->willReturn( true );

        $repo = $this->createMock( ConsentRepository::class );
        $repo->method( 'consentExistsByBot' )->willReturn( true );
        $repo->expects( $this->never() )->method( 'recordConsentRow' );

        $ctrl = new ConsentController( $botMgr, $this->createMock( ConversationRepository::class ), $repo );
        $resp = $ctrl->record( $this->makeRequest( [ 'bot_token' => str_repeat( 'a', 64 ), 'session_id' => 'session1' ] ) );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertFalse( $resp->get_data()['recorded'] );
    }

    public function test_record_inserts_chat_consent_when_absent(): void {
        $botMgr = $this->createMock( BotManager::class );
        $botMgr->method( 'getByPublicToken' )->willReturn( $this->bot() );
        $botMgr->method( 'validateOrigin' )->willReturn( true );

        $repo = $this->createMock( ConsentRepository::class );
        $repo->method( 'consentExistsByBot' )->willReturn( false );
        $repo->expects( $this->once() )
            ->method( 'recordConsentRow' )
            ->with( 3, 7, $this->anything(), 'chat', $this->anything(), $this->anything(), $this->anything() );

        $ctrl = new ConsentController( $botMgr, $this->createMock( ConversationRepository::class ), $repo );
        $resp = $ctrl->record( $this->makeRequest( [ 'bot_token' => str_repeat( 'a', 64 ), 'session_id' => 'session1' ] ) );

        $this->assertSame( 201, $resp->get_status() );
        $this->assertTrue( $resp->get_data()['recorded'] );
    }

    public function test_record_returns_404_when_bot_missing(): void {
        $botMgr = $this->createMock( BotManager::class );
        $botMgr->method( 'getByPublicToken' )->willReturn( null );

        $ctrl = new ConsentController(
            $botMgr,
            $this->createMock( ConversationRepository::class ),
            $this->createMock( ConsentRepository::class )
        );
        $resp = $ctrl->record( $this->makeRequest( [ 'bot_token' => str_repeat( 'a', 64 ), 'session_id' => 'session1' ] ) );

        $this->assertInstanceOf( \WP_Error::class, $resp );
    }

    public function test_recordLead_inserts_lead_consent_and_audit_when_absent(): void {
        $botMgr = $this->createMock( BotManager::class );
        $botMgr->method( 'getByPublicToken' )->willReturn( $this->bot() );
        $botMgr->method( 'validateOrigin' )->willReturn( true );

        $repo = $this->createMock( ConsentRepository::class );
        $repo->method( 'leadConsentExists' )->willReturn( false );
        $repo->method( 'consentExistsByBot' )->willReturn( false );
        $repo->expects( $this->once() )->method( 'recordLeadConsentRow' )
            ->with( 3, 7, $this->anything(), true, false, true, $this->anything(), $this->anything(), $this->anything() );
        $repo->expects( $this->once() )->method( 'recordConsentRow' )
            ->with( 3, 7, $this->anything(), 'lead_capture', $this->anything(), $this->anything(), $this->anything() );

        $ctrl = new ConsentController( $botMgr, $this->createMock( ConversationRepository::class ), $repo );
        $resp = $ctrl->recordLead( $this->makeRequest( [
            'bot_token'  => str_repeat( 'a', 64 ),
            'session_id' => 'session1',
            'scopes'     => [ 'name' => true, 'phone' => false, 'email' => true ],
        ] ) );

        $this->assertSame( 201, $resp->get_status() );
        $this->assertTrue( $resp->get_data()['recorded'] );
    }

    public function test_revoke_anonymizes_pii_and_flags_and_records_audit(): void {
        $botMgr = $this->createMock( BotManager::class );
        $botMgr->method( 'getByPublicToken' )->willReturn( $this->bot() );
        $botMgr->method( 'validateOrigin' )->willReturn( true );

        $conv = $this->createMock( ConversationRepository::class );
        $conv->method( 'deleteSession' )->willReturn( 4 );

        $repo = $this->createMock( ConsentRepository::class );
        $repo->expects( $this->once() )->method( 'anonymizeLeadPii' )->with( 3, $this->anything() );
        $repo->expects( $this->once() )->method( 'revokeCaptureFlags' )->with( 7, $this->anything() );
        $repo->method( 'consentExistsByBot' )->willReturn( false );
        $repo->expects( $this->once() )->method( 'recordConsentRow' )
            ->with( 3, 7, $this->anything(), 'consent_revoked', $this->anything(), $this->anything(), $this->anything() );

        $ctrl = new ConsentController( $botMgr, $conv, $repo );
        $resp = $ctrl->revoke( $this->makeRequest( [ 'bot_token' => str_repeat( 'a', 64 ), 'session_id' => 'session1' ] ) );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertTrue( $resp->get_data()['revoked'] );
        $this->assertSame( 4, $resp->get_data()['messages_processed'] );
    }

    public function test_recordLead_returns_already_consented_when_lead_consent_exists(): void {
        $botMgr = $this->createMock( BotManager::class );
        $botMgr->method( 'getByPublicToken' )->willReturn( $this->bot() );
        $botMgr->method( 'validateOrigin' )->willReturn( true );

        $repo = $this->createMock( ConsentRepository::class );
        $repo->method( 'leadConsentExists' )->willReturn( true );
        $repo->expects( $this->never() )->method( 'recordLeadConsentRow' );
        $repo->expects( $this->never() )->method( 'recordConsentRow' );

        $ctrl = new ConsentController( $botMgr, $this->createMock( ConversationRepository::class ), $repo );
        $resp = $ctrl->recordLead( $this->makeRequest( [
            'bot_token'  => str_repeat( 'a', 64 ),
            'session_id' => 'session1',
            'scopes'     => [ 'name' => true, 'phone' => false, 'email' => true ],
        ] ) );

        $this->assertSame( 200, $resp->get_status() );
        $this->assertFalse( $resp->get_data()['recorded'] );
    }

    public function test_recordLead_skips_audit_insert_when_lead_capture_evidence_exists(): void {
        $botMgr = $this->createMock( BotManager::class );
        $botMgr->method( 'getByPublicToken' )->willReturn( $this->bot() );
        $botMgr->method( 'validateOrigin' )->willReturn( true );

        $repo = $this->createMock( ConsentRepository::class );
        $repo->method( 'leadConsentExists' )->willReturn( false );
        // Ya existe evidencia scope='lead_capture' → el INSERT de auditoría se omite.
        $repo->method( 'consentExistsByBot' )->willReturn( true );
        $repo->expects( $this->once() )->method( 'recordLeadConsentRow' );
        $repo->expects( $this->never() )->method( 'recordConsentRow' );

        $ctrl = new ConsentController( $botMgr, $this->createMock( ConversationRepository::class ), $repo );
        $resp = $ctrl->recordLead( $this->makeRequest( [
            'bot_token'  => str_repeat( 'a', 64 ),
            'session_id' => 'session1',
            'scopes'     => [ 'name' => true, 'phone' => false, 'email' => true ],
        ] ) );

        $this->assertSame( 201, $resp->get_status() );
        $this->assertTrue( $resp->get_data()['recorded'] );
    }
}
