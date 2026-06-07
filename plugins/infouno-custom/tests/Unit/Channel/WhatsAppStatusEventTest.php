<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\WhatsAppStatusEvent;
use PHPUnit\Framework\TestCase;

final class WhatsAppStatusEventTest extends TestCase {

    public function test_fromStatusArray_maps_fields(): void {
        $raw = [
            'id'           => 'wamid.ABC123',
            'status'       => 'delivered',
            'timestamp'    => '1717632000',
            'recipient_id' => '5491111222333',
            'errors'       => [],
        ];

        $event = WhatsAppStatusEvent::fromStatusArray( $raw );

        $this->assertSame( 'wamid.ABC123',     $event->wamid );
        $this->assertSame( 'delivered',        $event->status );
        $this->assertSame( '5491111222333',    $event->recipientPhone );
        $this->assertNull( $event->errorCode );
    }

    public function test_fromStatusArray_captures_error_code(): void {
        $raw = [
            'id'           => 'wamid.FAIL',
            'status'       => 'failed',
            'timestamp'    => '1717632000',
            'recipient_id' => '549111',
            'errors'       => [ [ 'code' => 131026, 'title' => 'Message undeliverable' ] ],
        ];

        $event = WhatsAppStatusEvent::fromStatusArray( $raw );

        $this->assertSame( 'failed', $event->status );
        $this->assertSame( 131026,   $event->errorCode );
    }

    public function test_valid_statuses_are_recognized(): void {
        foreach ( [ 'sent', 'delivered', 'read', 'failed' ] as $s ) {
            $this->assertTrue( WhatsAppStatusEvent::isKnownStatus( $s ) );
        }
        $this->assertFalse( WhatsAppStatusEvent::isKnownStatus( 'unknown' ) );
    }
}
