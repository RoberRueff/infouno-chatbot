<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\WhatsAppAdapter;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class WhatsAppAdapterTemplateBranchTest extends TestCase {

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

    public function test_sendTemplate_posts_type_template_to_graph_api(): void {
        $captured = [];
        $http     = new class( $captured ) implements ChannelHttpClient {
            public function __construct( private array &$captured ) {}
            public function postJson( string $url, array $headers, array $body ): array {
                $this->captured[] = $body;
                return [ 'code' => 200, 'body' => '{"messages":[{"id":"wamid.TPL"}]}' ];
            }
        };

        $adapter    = new WhatsAppAdapter( $this->vault, $http );
        $channel    = $this->channel( [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ] );
        $components = [ [ 'type' => 'body', 'parameters' => [ [ 'type' => 'text', 'text' => 'Juan' ] ] ] ];

        $adapter->sendTemplate( $channel, '5491111', 'bienvenida', 'es_AR', $components );

        $this->assertCount( 1, $captured );
        $this->assertSame( 'template',   $captured[0]['type'] );
        $this->assertSame( 'bienvenida', $captured[0]['template']['name'] );
        $this->assertSame( 'es_AR',      $captured[0]['template']['language']['code'] );
        $this->assertSame( $components,  $captured[0]['template']['components'] );
        $this->assertSame( 'wamid.TPL',  $adapter->lastWamid() );
    }

    public function test_sendTemplate_throws_WhatsAppGraphException_on_error(): void {
        $http = new class implements ChannelHttpClient {
            public function postJson( string $url, array $headers, array $body ): array {
                return [
                    'code' => 400,
                    'body' => '{"error":{"code":131047,"message":"Re-engagement window closed"}}',
                ];
            }
        };

        $adapter = new WhatsAppAdapter( $this->vault, $http );
        $channel = $this->channel( [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ] );

        $this->expectException( \Infouno\SaaS\Channel\WhatsAppGraphException::class );
        $adapter->sendTemplate( $channel, '5491111', 'bienvenida', 'es_AR', [] );
    }
}
