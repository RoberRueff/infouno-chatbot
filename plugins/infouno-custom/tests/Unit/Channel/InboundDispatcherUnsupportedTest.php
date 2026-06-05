<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelAdapterInterface;
use Infouno\SaaS\Channel\ChannelConsentService;
use Infouno\SaaS\Channel\ChannelRegistry;
use Infouno\SaaS\Channel\ChannelRepository;
use Infouno\SaaS\Channel\InboundDispatcher;
use Infouno\SaaS\Channel\InboundMessage;
use Infouno\SaaS\Chat\ChatPipeline;
use PHPUnit\Framework\TestCase;

final class InboundDispatcherUnsupportedTest extends TestCase {

    public function test_unsupported_message_replies_without_pipeline(): void {
        $sent    = [];
        $adapter = new class( $sent ) implements ChannelAdapterInterface {
            public function __construct( public array &$sent ) {}
            public function type(): string { return 'whatsapp'; }
            public function verifyWebhook( \WP_REST_Request $r, array $c ): bool { return true; }
            public function parseInbound( array $p ): ?InboundMessage {
                return new InboundMessage( 'whatsapp', '549', '', 'wamid.A', 'unsupported' );
            }
            public function send( array $c, string $u, string $t ): void { $this->sent[] = [ $u, $t ]; }
            public function splitMessage( string $t ): array { return [ $t ]; }
        };
        $registry = new ChannelRegistry();
        $registry->register( $adapter );

        $repo = $this->createMock( ChannelRepository::class );
        $repo->method( 'resolveByRoutingKeyId' )->willReturn( [
            'id' => 4, 'tenant_id' => 3, 'bot_id' => 7, 'channel_type' => 'whatsapp', 'credentials_decrypted' => [],
        ] );

        $consent  = $this->createMock( ChannelConsentService::class );
        $pipeline = $this->createMock( ChatPipeline::class );
        $pipeline->expects( $this->never() )->method( 'run' );   // no-texto NO corre el pipeline
        $consent->expects( $this->never() )->method( 'ensure' ); // ni consentimiento

        $dispatcher = new InboundDispatcher(
            $registry, $repo, $consent, $pipeline,
            fn( int $tid, int $bid ) => [ 'id' => 7, 'tenant_id' => 3, 'settings' => [] ]
        );
        $dispatcher->handle( 4, [ 'whatever' => true ] );

        $this->assertCount( 1, $sent );
        $this->assertSame( '549', $sent[0][0] );
        $this->assertStringContainsString( 'texto', $sent[0][1] );
    }
}
