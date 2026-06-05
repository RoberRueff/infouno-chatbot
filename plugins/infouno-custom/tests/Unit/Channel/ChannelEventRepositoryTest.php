<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelEventRepository;
use PHPUnit\Framework\TestCase;

final class ChannelEventRepositoryTest extends TestCase {

    public function test_first_occurrence_is_new(): void {
        $GLOBALS['wpdb']->stub_query_result = 1; // INSERT IGNORE insertó 1 fila
        $repo = new ChannelEventRepository();

        $this->assertTrue( $repo->markIfNew( 'telegram', 'upd-1' ) );
    }

    public function test_duplicate_is_not_new(): void {
        $GLOBALS['wpdb']->stub_query_result = 0; // INSERT IGNORE no insertó (ya existía)
        $repo = new ChannelEventRepository();

        $this->assertFalse( $repo->markIfNew( 'telegram', 'upd-1' ) );
    }
}
