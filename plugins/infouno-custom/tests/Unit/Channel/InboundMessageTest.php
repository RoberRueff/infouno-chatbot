<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\InboundMessage;
use PHPUnit\Framework\TestCase;

final class InboundMessageTest extends TestCase {

    public function test_exposes_normalized_fields(): void {
        $msg = new InboundMessage( 'telegram', '55', 'Hola', 'upd-1001' );

        $this->assertSame( 'telegram', $msg->channelType );
        $this->assertSame( '55', $msg->externalUser );
        $this->assertSame( 'Hola', $msg->text );
        $this->assertSame( 'upd-1001', $msg->externalMsgId );
    }

    public function test_conversation_key_is_channel_prefixed(): void {
        $msg = new InboundMessage( 'telegram', '55', 'Hola', 'upd-1001' );
        $this->assertSame( 'tg:55', $msg->conversationKey() );
    }
}
