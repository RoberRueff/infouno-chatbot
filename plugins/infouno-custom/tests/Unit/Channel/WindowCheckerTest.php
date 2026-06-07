<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\WindowChecker;
use PHPUnit\Framework\TestCase;

final class WindowCheckerTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->stub_get_var = null;
    }

    public function test_returns_true_when_last_user_message_is_within_24h(): void {
        $GLOBALS['wpdb']->stub_get_var = gmdate( 'Y-m-d H:i:s', time() - 3600 );

        $checker = new WindowChecker();
        $this->assertTrue( $checker->isOpen( botId: 7, conversationKey: 'wa:5491111' ) );
    }

    public function test_returns_false_when_last_user_message_is_older_than_24h(): void {
        $GLOBALS['wpdb']->stub_get_var = gmdate( 'Y-m-d H:i:s', time() - ( 25 * 3600 ) );

        $checker = new WindowChecker();
        $this->assertFalse( $checker->isOpen( botId: 7, conversationKey: 'wa:5491111' ) );
    }

    public function test_returns_false_when_no_user_messages(): void {
        $GLOBALS['wpdb']->stub_get_var = null;

        $checker = new WindowChecker();
        $this->assertFalse( $checker->isOpen( botId: 7, conversationKey: 'wa:5491111' ) );
    }

    public function test_boundary_exactly_24h_is_closed(): void {
        $GLOBALS['wpdb']->stub_get_var = gmdate( 'Y-m-d H:i:s', time() - ( 24 * 3600 ) );

        $checker = new WindowChecker();
        $this->assertFalse( $checker->isOpen( botId: 7, conversationKey: 'wa:5491111' ) );
    }
}
