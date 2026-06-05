<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\InboundMessage;
use PHPUnit\Framework\TestCase;

final class InboundMessageKindTest extends TestCase {

    public function test_kind_defaults_to_text(): void {
        $m = new InboundMessage( 'telegram', '55', 'hola', 'u1' );
        $this->assertSame( 'text', $m->kind );
    }

    public function test_kind_can_be_unsupported(): void {
        $m = new InboundMessage( 'whatsapp', '549', '', 'wamid.X', 'unsupported' );
        $this->assertSame( 'unsupported', $m->kind );
        $this->assertSame( 'wa:549', $m->conversationKey() );
    }
}
