<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\TelegramAdapter;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class TelegramAdapterTest extends TestCase {

    private function adapter( ?ChannelHttpClient $http = null ): TelegramAdapter {
        $vault = new CredentialVault( str_repeat( 'a', 64 ) );
        $http ??= new class implements ChannelHttpClient {
            public array $calls = [];
            public function postJson( string $url, array $headers, array $body ): array {
                $this->calls[] = compact( 'url', 'headers', 'body' );
                return [ 'code' => 200, 'body' => '{"ok":true}' ];
            }
        };
        return new TelegramAdapter( $vault, $http );
    }

    public function test_type(): void {
        $this->assertSame( 'telegram', $this->adapter()->type() );
    }

    public function test_parse_inbound_extracts_message(): void {
        $payload = [
            'update_id' => 7001,
            'message'   => [
                'message_id' => 12,
                'chat'       => [ 'id' => 55 ],
                'text'       => 'Hola, quiero info',
            ],
        ];

        $msg = $this->adapter()->parseInbound( $payload );

        $this->assertNotNull( $msg );
        $this->assertSame( 'telegram', $msg->channelType );
        $this->assertSame( '55', $msg->externalUser );
        $this->assertSame( 'Hola, quiero info', $msg->text );
        $this->assertSame( '7001', $msg->externalMsgId );
    }

    public function test_parse_inbound_returns_null_for_non_text(): void {
        $this->assertNull( $this->adapter()->parseInbound( [ 'update_id' => 1, 'message' => [ 'chat' => [ 'id' => 5 ] ] ] ) );
        $this->assertNull( $this->adapter()->parseInbound( [ 'edited_message' => [] ] ) );
    }

    public function test_split_message_respects_4096_limit(): void {
        $long   = str_repeat( 'x', 9000 );
        $chunks = $this->adapter()->splitMessage( $long );

        $this->assertCount( 3, $chunks );
        foreach ( $chunks as $chunk ) {
            $this->assertLessThanOrEqual( 4096, strlen( $chunk ) );
        }
        $this->assertSame( $long, implode( '', $chunks ) );
    }

    public function test_verify_webhook_compares_secret_header(): void {
        $adapter = $this->adapter();
        $channel = [ 'webhook_secret' => 's3cr3t' ];

        $ok  = new \WP_REST_Request();
        $ok->set_header( 'X-Telegram-Bot-Api-Secret-Token', 's3cr3t' );
        $bad = new \WP_REST_Request();
        $bad->set_header( 'X-Telegram-Bot-Api-Secret-Token', 'wrong' );

        $this->assertTrue( $adapter->verifyWebhook( $ok, $channel ) );
        $this->assertFalse( $adapter->verifyWebhook( $bad, $channel ) );
    }

    public function test_send_posts_to_telegram_api_with_decrypted_token(): void {
        $vault = new CredentialVault( str_repeat( 'a', 64 ) );
        $http  = new class implements ChannelHttpClient {
            public array $calls = [];
            public function postJson( string $url, array $headers, array $body ): array {
                $this->calls[] = compact( 'url', 'headers', 'body' );
                return [ 'code' => 200, 'body' => '{"ok":true}' ];
            }
        };
        $adapter = new TelegramAdapter( $vault, $http );

        $channel = [ 'credentials' => $vault->encryptArray( [ 'bot_token' => '123:ABC' ] ) ];
        $adapter->send( $channel, '55', 'Respuesta del bot' );

        $this->assertCount( 1, $http->calls );
        $this->assertStringContainsString( '/bot123:ABC/sendMessage', $http->calls[0]['url'] );
        $this->assertSame( 55, $http->calls[0]['body']['chat_id'] ?? null );
        $this->assertSame( 'Respuesta del bot', $http->calls[0]['body']['text'] ?? null );
    }
}
