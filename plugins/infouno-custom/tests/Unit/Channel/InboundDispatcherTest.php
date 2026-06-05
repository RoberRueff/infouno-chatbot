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

    /**
     * @param array<string,mixed> $sent
     */
    private function makeDispatcher( ChatPipeline $pipeline, array &$sent ): InboundDispatcher {
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

        $botLoader = fn( int $tid, int $bid ) => [ 'id' => 7, 'tenant_id' => 3, 'system_prompt' => 'x', 'settings' => [] ];

        return new InboundDispatcher( $registry, $repo, $consent, $pipeline, $botLoader );
    }

    public function test_business_error_429_sends_fallback_and_does_not_rethrow(): void {
        $sent     = [];
        $pipeline = $this->createMock( ChatPipeline::class );
        $pipeline->method( 'run' )->willThrowException( new \RuntimeException( 'rate limited', 429 ) );

        $dispatcher = $this->makeDispatcher( $pipeline, $sent );

        // No debe re-lanzar: error de negocio terminal.
        $dispatcher->handle( 4, [ 'message' => [ 'text' => 'Hola' ] ] );

        $this->assertCount( 1, $sent );
        $this->assertSame( '55', $sent[0][0] );
        $this->assertStringContainsString( 'muy rápido', $sent[0][1] );
    }

    public function test_transient_error_503_is_rethrown_for_retry_and_sends_no_business_reply(): void {
        $sent     = [];
        $pipeline = $this->createMock( ChatPipeline::class );
        $pipeline->method( 'run' )->willThrowException( new \RuntimeException( 'LLM caído', 503 ) );

        $dispatcher = $this->makeDispatcher( $pipeline, $sent );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionCode( 503 );

        try {
            $dispatcher->handle( 4, [ 'message' => [ 'text' => 'Hola' ] ] );
        } finally {
            // El 503 se re-lanza para que Action Scheduler reintente; no se envía
            // ninguna respuesta de negocio al usuario.
            $this->assertSame( [], $sent );
        }
    }
}
