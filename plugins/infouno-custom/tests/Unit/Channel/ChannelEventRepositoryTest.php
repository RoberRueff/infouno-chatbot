<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelEventRepository;
use PHPUnit\Framework\TestCase;

final class ChannelEventRepositoryTest extends TestCase {

    public function test_first_occurrence_is_new(): void {
        $GLOBALS['wpdb']->stub_query_result = 1; // INSERT IGNORE insertó 1 fila
        $repo = new ChannelEventRepository();

        $this->assertTrue( $repo->markIfNew( 4, 'telegram', 'upd-1' ) );
    }

    public function test_duplicate_is_not_new(): void {
        $GLOBALS['wpdb']->stub_query_result = 0; // INSERT IGNORE no insertó (ya existía)
        $repo = new ChannelEventRepository();

        $this->assertFalse( $repo->markIfNew( 4, 'telegram', 'upd-1' ) );
    }

    public function test_insert_includes_channel_id(): void {
        $GLOBALS['wpdb']->stub_query_result = 1;
        $repo = new ChannelEventRepository();

        $repo->markIfNew( 4, 'telegram', 'upd-1' );

        // El fix exige que channel_id forme parte de la clave de idempotencia.
        $query = $GLOBALS['wpdb']->last_query;
        $this->assertStringContainsString( 'channel_id', $query );
        $this->assertStringContainsString( '4', $query );
    }

    /**
     * Fix C2: la idempotencia es por canal, no global. Dos canales distintos con
     * el MISMO external_msg_id (ej. update_id secuencial de Telegram que colisiona
     * entre tenants) deben tratarse ambos como mensajes nuevos.
     */
    public function test_same_external_msg_id_in_different_channels_are_both_new(): void {
        $GLOBALS['wpdb']->stub_query_result = 1; // la UNIQUE (channel_id, external_msg_id) no colisiona
        $repo = new ChannelEventRepository();

        $this->assertTrue( $repo->markIfNew( 101, 'telegram', 'upd-555' ) );
        $this->assertStringContainsString( '101', $GLOBALS['wpdb']->last_query );

        $this->assertTrue( $repo->markIfNew( 202, 'telegram', 'upd-555' ) );
        $this->assertStringContainsString( '202', $GLOBALS['wpdb']->last_query );
    }
}
