<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelAdapterInterface;
use Infouno\SaaS\Channel\ChannelRegistry;
use Infouno\SaaS\Channel\InboundMessage;
use PHPUnit\Framework\TestCase;

final class ChannelRegistryTest extends TestCase {

    private function fakeAdapter( string $type ): ChannelAdapterInterface {
        return new class( $type ) implements ChannelAdapterInterface {
            public function __construct( private string $t ) {}
            public function type(): string { return $this->t; }
            public function verifyWebhook( \WP_REST_Request $r, array $c ): bool { return true; }
            public function parseInbound( array $p ): ?InboundMessage { return null; }
            public function send( array $c, string $u, string $t ): void {}
            public function splitMessage( string $t ): array { return [ $t ]; }
        };
    }

    public function test_register_and_resolve(): void {
        $registry = new ChannelRegistry();
        $registry->register( $this->fakeAdapter( 'telegram' ) );

        $this->assertTrue( $registry->has( 'telegram' ) );
        $this->assertSame( 'telegram', $registry->get( 'telegram' )->type() );
    }

    public function test_get_unknown_throws(): void {
        $this->expectException( \RuntimeException::class );
        ( new ChannelRegistry() )->get( 'tiktok' );
    }
}
