<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\API;

use Infouno\SaaS\API\ChannelWebhookController;
use Infouno\SaaS\Channel\ChannelAdapterInterface;
use Infouno\SaaS\Channel\ChannelEventRepository;
use Infouno\SaaS\Channel\ChannelRegistry;
use Infouno\SaaS\Channel\ChannelRepository;
use Infouno\SaaS\Channel\InboundMessage;
use Infouno\SaaS\Channel\WebhookChallengeInterface;
use PHPUnit\Framework\TestCase;

final class ChannelWebhookControllerTest extends TestCase {

    private function controller( ChannelAdapterInterface $adapter, array $channel ): ChannelWebhookController {
        $registry = new ChannelRegistry();
        $registry->register( $adapter );
        $repo = $this->createMock( ChannelRepository::class );
        $repo->method( 'resolveByRoutingKey' )->willReturn( $channel );
        $events = $this->createMock( ChannelEventRepository::class );
        $events->method( 'markIfNew' )->willReturn( true );
        return new ChannelWebhookController( $registry, $repo, $events );
    }

    /** Adapter que implementa el challenge GET. */
    private function challengeAdapter(): ChannelAdapterInterface {
        return new class implements ChannelAdapterInterface, WebhookChallengeInterface {
            public function type(): string { return 'whatsapp'; }
            public function verifyWebhook( \WP_REST_Request $r, array $c ): bool { return true; }
            public function verifyChallenge( \WP_REST_Request $r, array $c ): ?string {
                return 'subscribe' === $r->get_param( 'hub_mode' ) ? (string) $r->get_param( 'hub_challenge' ) : null;
            }
            public function parseInbound( array $p ): ?InboundMessage {
                return isset( $p['msg'] ) ? new InboundMessage( 'whatsapp', '549', 'hi', 'id1' ) : null;
            }
            public function send( array $c, string $u, string $t ): void {}
            public function splitMessage( string $t ): array { return [ $t ]; }
        };
    }

    public function test_get_challenge_echoes_valid_challenge(): void {
        $channel = [ 'id' => 4, 'channel_type' => 'whatsapp' ];
        $ctrl    = $this->controller( $this->challengeAdapter(), $channel );

        $req = new \WP_REST_Request();
        $req->set_param( 'type', 'whatsapp' );
        $req->set_param( 'key', 'rk_x' );
        $req->set_param( 'hub_mode', 'subscribe' );
        $req->set_param( 'hub_challenge', 'C-99' );

        $res = $ctrl->handleChallenge( $req );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 'C-99', $res->get_data() );
    }

    public function test_post_without_message_does_not_dedup_as_handled(): void {
        // parseInbound → null (status/receipt). El handler responde 200 ignorado, sin encolar.
        $channel = [ 'id' => 4, 'channel_type' => 'whatsapp' ];
        $ctrl    = $this->controller( $this->challengeAdapter(), $channel );

        $req = new \WP_REST_Request();
        $req->set_param( 'type', 'whatsapp' );
        $req->set_param( 'key', 'rk_x' );
        $req->set_body( '{}' );   // get_json_params → [] → parseInbound null

        $res = $ctrl->handle( $req );
        $this->assertSame( 200, $res->get_status() );
        $this->assertTrue( (bool) ( $res->get_data()['ignored'] ?? false ) );
    }
}
