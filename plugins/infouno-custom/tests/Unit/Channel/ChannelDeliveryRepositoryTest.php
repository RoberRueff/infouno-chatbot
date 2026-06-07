<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelDeliveryRepository;
use PHPUnit\Framework\TestCase;

final class ChannelDeliveryRepositoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->onInsert   = null;
        $GLOBALS['wpdb']->insert_id  = 0;
        $GLOBALS['wpdb']->stub_get_row = null;
        $GLOBALS['wpdb']->last_query = '';
    }

    public function test_record_inserts_row_with_correct_fields(): void {
        $inserted = [];
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$inserted ): void {
            $inserted = $data;
        };
        $GLOBALS['wpdb']->insert_id = 42;

        $repo = new ChannelDeliveryRepository();
        $id   = $repo->record(
            tenantId:       3,
            channelId:      7,
            messageId:      null,
            externalMsgId: 'wamid.HBgL',
        );

        $this->assertSame( 42, $id );
        $this->assertSame( 3,           $inserted['tenant_id'] );
        $this->assertSame( 7,           $inserted['channel_id'] );
        $this->assertNull( $inserted['message_id'] );
        $this->assertSame( 'wamid.HBgL', $inserted['external_msg_id'] );
        $this->assertSame( 'sent',       $inserted['status'] );
    }

    public function test_updateStatus_builds_correct_query(): void {
        $repo = new ChannelDeliveryRepository();

        $repo->updateStatus(
            tenantId:       3,
            externalMsgId: 'wamid.HBgL',
            status:         'delivered',
            errorCode:      null,
        );

        $this->assertStringContainsString( 'wamid.HBgL', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( 'delivered',  $GLOBALS['wpdb']->last_query );
    }

    public function test_updateStatus_includes_error_code_when_failed(): void {
        $repo = new ChannelDeliveryRepository();

        $repo->updateStatus(
            tenantId:       3,
            externalMsgId: 'wamid.FAIL',
            status:         'failed',
            errorCode:      131026,
        );

        $this->assertStringContainsString( 'wamid.FAIL', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( '131026',     $GLOBALS['wpdb']->last_query );
    }
}
