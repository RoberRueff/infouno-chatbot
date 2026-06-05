<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelRepository;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class ChannelRepositoryTest extends TestCase {

    public function test_resolve_by_routing_key_returns_channel_with_decrypted_creds(): void {
        $vault  = new CredentialVault( str_repeat( 'a', 64 ) );
        $cipher = $vault->encryptArray( [ 'bot_token' => '123:ABC' ] );

        $GLOBALS['wpdb']->stub_get_row = [
            'id'             => 4,
            'tenant_id'      => 3,
            'bot_id'         => 7,
            'channel_type'   => 'telegram',
            'routing_key'    => 'rk_abc',
            'credentials'    => $cipher,
            'webhook_secret' => 's3cr3t',
            'status'         => 'active',
        ];

        $repo    = new ChannelRepository( $vault );
        $channel = $repo->resolveByRoutingKey( 'rk_abc' );

        $this->assertNotNull( $channel );
        $this->assertSame( 7, $channel['bot_id'] );
        $this->assertSame( '123:ABC', $channel['credentials_decrypted']['bot_token'] );
    }

    public function test_resolve_unknown_returns_null(): void {
        $GLOBALS['wpdb']->stub_get_row = null;
        $repo = new ChannelRepository( new CredentialVault( str_repeat( 'a', 64 ) ) );

        $this->assertNull( $repo->resolveByRoutingKey( 'nope' ) );
    }
}
