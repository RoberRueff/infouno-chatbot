<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\WhatsAppAdapter;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class WhatsAppAdapterSendWamidTest extends TestCase {

    private CredentialVault $vault;

    protected function setUp(): void {
        $this->vault = new CredentialVault( str_repeat( 'a', 64 ) );
    }

    private function channel( array $creds ): array {
        return [
            'credentials'           => $this->vault->encryptArray( $creds ),
            'credentials_decrypted' => $creds,
        ];
    }

    public function test_send_captures_wamid_from_graph_response(): void {
        $http = new class implements ChannelHttpClient {
            public function postJson( string $url, array $headers, array $body ): array {
                return [
                    'code' => 200,
                    'body' => '{"messages":[{"id":"wamid.HBgL"}],"messaging_product":"whatsapp"}',
                ];
            }
        };

        $adapter = new WhatsAppAdapter( $this->vault, $http );
        $channel = $this->channel( [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ] );

        $adapter->send( $channel, '5491111', 'Hola' );

        $this->assertSame( 'wamid.HBgL', $adapter->lastWamid() );
    }

    public function test_send_returns_null_wamid_on_non_200(): void {
        $http = new class implements ChannelHttpClient {
            public function postJson( string $url, array $headers, array $body ): array {
                return [ 'code' => 400, 'body' => '{"error":{"code":100,"message":"bad param"}}' ];
            }
        };

        $adapter = new WhatsAppAdapter( $this->vault, $http );
        $channel = $this->channel( [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ] );

        try {
            $adapter->send( $channel, '5491111', 'Hola' );
        } catch ( \RuntimeException ) {
            // esperado en Task 4 — ignoramos aquí
        }

        $this->assertNull( $adapter->lastWamid() );
    }

    public function test_last_wamid_resets_between_sends(): void {
        $calls = 0;
        $http  = new class( $calls ) implements ChannelHttpClient {
            public function __construct( private int &$calls ) {}
            public function postJson( string $url, array $headers, array $body ): array {
                $this->calls++;
                $id = $this->calls === 1 ? 'wamid.FIRST' : 'wamid.SECOND';
                return [ 'code' => 200, 'body' => '{"messages":[{"id":"' . $id . '"}]}' ];
            }
        };

        $adapter = new WhatsAppAdapter( $this->vault, $http );
        $channel = $this->channel( [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ] );

        $adapter->send( $channel, '111', 'a' );
        $this->assertSame( 'wamid.FIRST', $adapter->lastWamid() );

        $adapter->send( $channel, '111', 'b' );
        $this->assertSame( 'wamid.SECOND', $adapter->lastWamid() );
    }
}
