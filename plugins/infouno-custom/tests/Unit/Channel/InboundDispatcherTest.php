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

final class InboundDispatcherTest extends TestCase {

    private function adapterThatSends( array &$sent ): ChannelAdapterInterface {
        return new class( $sent ) implements ChannelAdapterInterface {
            public function __construct( private array &$sent ) {}
            public function type(): string { return 'telegram'; }
            public function verifyWebhook( \WP_REST_Request $r, array $c ): bool { return true; }
            public function parseInbound( array $p ): ?InboundMessage {
                return new InboundMessage( 'telegram', '55', $p['message']['text'], '1' );
            }
            public function send( array $c, string $u, string $t ): void { $this->sent[] = [ $u, $t ]; }
            public function splitMessage( string $t ): array { return [ $t ]; }
        };
    }

    public function test_happy_path_runs_pipeline_and_sends_reply(): void {
        $sent     = [];
        $adapter  = $this->adapterThatSends( $sent );
        $registry = new ChannelRegistry();
        $registry->register( $adapter );

        $repo = $this->createMock( ChannelRepository::class );
        $repo->method( 'resolveByRoutingKeyId' )->willReturn( [
            'id' => 4, 'tenant_id' => 3, 'bot_id' => 7, 'channel_type' => 'telegram',
            'credentials_decrypted' => [ 'bot_token' => 'x' ],
        ] );

        $consent = $this->createMock( ChannelConsentService::class );
        $consent->method( 'ensure' )->willReturn( false ); // no primer contacto

        $pipeline = $this->createMock( ChatPipeline::class );
        $pipeline->expects( $this->once() )->method( 'run' )->willReturnCallback(
            function ( $bot, $key, $text, $sink ) {
                $sink->write( 'Respuesta IA' );
                $sink->finish();
                return new \Infouno\SaaS\LLM\StreamResult( 1, 1, 'stop', 'openai', 'gpt-4o-mini' );
            }
        );

        $botLoader = fn( int $tid, int $bid ) => [ 'id' => 7, 'tenant_id' => 3, 'system_prompt' => 'x', 'settings' => [] ];

        $dispatcher = new InboundDispatcher( $registry, $repo, $consent, $pipeline, $botLoader );
        $dispatcher->handle( 4, [ 'message' => [ 'text' => 'Hola' ] ] );

        $this->assertSame( [ [ '55', 'Respuesta IA' ] ], $sent );
    }
}
