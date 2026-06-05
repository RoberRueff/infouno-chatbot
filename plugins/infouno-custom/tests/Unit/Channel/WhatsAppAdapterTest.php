<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\WhatsAppAdapter;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class WhatsAppAdapterTest extends TestCase {

    private CredentialVault $vault;

    protected function setUp(): void {
        $this->vault = new CredentialVault( str_repeat( 'a', 64 ) );
    }

    private function adapter( ?ChannelHttpClient $http = null ): WhatsAppAdapter {
        $http ??= new class implements ChannelHttpClient {
            public array $calls = [];
            public function postJson( string $url, array $headers, array $body ): array {
                $this->calls[] = compact( 'url', 'headers', 'body' );
                return [ 'code' => 200, 'body' => '{"messages":[{"id":"x"}]}' ];
            }
        };
        return new WhatsAppAdapter( $this->vault, $http );
    }

    private function channel( array $creds ): array {
        return [
            'credentials'           => $this->vault->encryptArray( $creds ),
            'credentials_decrypted' => $creds,
        ];
    }

    public function test_type(): void {
        $this->assertSame( 'whatsapp', $this->adapter()->type() );
    }

    public function test_parse_inbound_text(): void {
        $payload = [ 'entry' => [ [ 'changes' => [ [ 'value' => [
            'messages' => [ [ 'from' => '5491111', 'id' => 'wamid.ABC', 'type' => 'text', 'text' => [ 'body' => 'Hola precios?' ] ] ],
        ] ] ] ] ] ];
        $m = $this->adapter()->parseInbound( $payload );
        $this->assertNotNull( $m );
        $this->assertSame( 'whatsapp', $m->channelType );
        $this->assertSame( '5491111', $m->externalUser );
        $this->assertSame( 'Hola precios?', $m->text );
        $this->assertSame( 'wamid.ABC', $m->externalMsgId );
        $this->assertSame( 'text', $m->kind );
    }

    public function test_parse_inbound_non_text_is_unsupported(): void {
        $payload = [ 'entry' => [ [ 'changes' => [ [ 'value' => [
            'messages' => [ [ 'from' => '5491111', 'id' => 'wamid.AUD', 'type' => 'audio', 'audio' => [ 'id' => 'm1' ] ] ],
        ] ] ] ] ] ];
        $m = $this->adapter()->parseInbound( $payload );
        $this->assertNotNull( $m );
        $this->assertSame( 'unsupported', $m->kind );
        $this->assertSame( 'wamid.AUD', $m->externalMsgId );
    }

    public function test_parse_inbound_status_is_null(): void {
        $payload = [ 'entry' => [ [ 'changes' => [ [ 'value' => [
            'statuses' => [ [ 'id' => 'wamid.X', 'status' => 'delivered' ] ],
        ] ] ] ] ] ];
        $this->assertNull( $this->adapter()->parseInbound( $payload ) );
    }

    public function test_verify_webhook_hmac(): void {
        $secret = 'app-secret-123';
        $body   = '{"entry":[]}';
        $sig    = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

        $ok = new \WP_REST_Request();
        $ok->set_body( $body );
        $ok->set_header( 'X-Hub-Signature-256', $sig );

        $bad = new \WP_REST_Request();
        $bad->set_body( $body );
        $bad->set_header( 'X-Hub-Signature-256', 'sha256=deadbeef' );

        $channel = $this->channel( [ 'app_secret' => $secret ] );
        $this->assertTrue(  $this->adapter()->verifyWebhook( $ok, $channel ) );
        $this->assertFalse( $this->adapter()->verifyWebhook( $bad, $channel ) );
    }

    public function test_verify_challenge(): void {
        $channel = $this->channel( [ 'verify_token' => 'mytoken' ] );

        $ok = new \WP_REST_Request();
        $ok->set_param( 'hub_mode', 'subscribe' );
        $ok->set_param( 'hub_verify_token', 'mytoken' );
        $ok->set_param( 'hub_challenge', 'CHALLENGE-123' );
        $this->assertSame( 'CHALLENGE-123', $this->adapter()->verifyChallenge( $ok, $channel ) );

        $bad = new \WP_REST_Request();
        $bad->set_param( 'hub_mode', 'subscribe' );
        $bad->set_param( 'hub_verify_token', 'wrong' );
        $bad->set_param( 'hub_challenge', 'CHALLENGE-123' );
        $this->assertNull( $this->adapter()->verifyChallenge( $bad, $channel ) );
    }

    public function test_send_posts_to_graph_api(): void {
        $http = new class implements ChannelHttpClient {
            public array $calls = [];
            public function postJson( string $url, array $headers, array $body ): array {
                $this->calls[] = compact( 'url', 'headers', 'body' );
                return [ 'code' => 200, 'body' => 'ok' ];
            }
        };
        $adapter = new WhatsAppAdapter( $this->vault, $http );
        $channel = $this->channel( [ 'access_token' => 'TOKEN', 'phone_number_id' => 'PNID' ] );

        $adapter->send( $channel, '5491111', 'Respuesta' );

        $this->assertCount( 1, $http->calls );
        $this->assertStringContainsString( '/PNID/messages', $http->calls[0]['url'] );
        $this->assertSame( 'Bearer TOKEN', $http->calls[0]['headers']['Authorization'] ?? null );
        $this->assertSame( 'whatsapp', $http->calls[0]['body']['messaging_product'] ?? null );
        $this->assertSame( '5491111', $http->calls[0]['body']['to'] ?? null );
        $this->assertSame( 'Respuesta', $http->calls[0]['body']['text']['body'] ?? null );
    }

    public function test_split_message_respects_4096(): void {
        $chunks = $this->adapter()->splitMessage( str_repeat( 'x', 9000 ) );
        $this->assertCount( 3, $chunks );
        $this->assertSame( str_repeat( 'x', 9000 ), implode( '', $chunks ) );
    }
}
