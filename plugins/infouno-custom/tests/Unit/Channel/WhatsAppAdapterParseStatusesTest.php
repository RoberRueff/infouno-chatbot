<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\WhatsAppAdapter;
use Infouno\SaaS\Channel\WhatsAppStatusEvent;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class WhatsAppAdapterParseStatusesTest extends TestCase {

    private function adapter(): WhatsAppAdapter {
        $http = new class implements ChannelHttpClient {
            public function postJson( string $url, array $headers, array $body ): array {
                return [ 'code' => 200, 'body' => '{"messages":[{"id":"wamid.X"}]}' ];
            }
        };
        return new WhatsAppAdapter( new CredentialVault( str_repeat( 'a', 64 ) ), $http );
    }

    private function statusPayload( array $statuses ): array {
        return [ 'entry' => [ [ 'changes' => [ [ 'value' => [
            'statuses' => $statuses,
        ] ] ] ] ] ];
    }

    public function test_parseStatuses_returns_events_for_statuses_payload(): void {
        $payload = $this->statusPayload( [
            [ 'id' => 'wamid.A', 'status' => 'delivered', 'timestamp' => '1717632000', 'recipient_id' => '5491111', 'errors' => [] ],
            [ 'id' => 'wamid.B', 'status' => 'read',      'timestamp' => '1717632001', 'recipient_id' => '5491111', 'errors' => [] ],
        ] );

        $events = $this->adapter()->parseStatuses( $payload );

        $this->assertCount( 2, $events );
        $this->assertInstanceOf( WhatsAppStatusEvent::class, $events[0] );
        $this->assertSame( 'wamid.A',  $events[0]->wamid );
        $this->assertSame( 'delivered', $events[0]->status );
        $this->assertSame( 'wamid.B',  $events[1]->wamid );
        $this->assertSame( 'read',      $events[1]->status );
    }

    public function test_parseStatuses_returns_empty_array_for_messages_payload(): void {
        $payload = [ 'entry' => [ [ 'changes' => [ [ 'value' => [
            'messages' => [ [ 'from' => '5491111', 'id' => 'wamid.C', 'type' => 'text', 'text' => [ 'body' => 'Hola' ] ] ],
        ] ] ] ] ] ];

        $this->assertSame( [], $this->adapter()->parseStatuses( $payload ) );
    }

    public function test_parseStatuses_ignores_unknown_statuses(): void {
        $payload = $this->statusPayload( [
            [ 'id' => 'wamid.Z', 'status' => 'unknown_future_status', 'timestamp' => '0', 'recipient_id' => '111', 'errors' => [] ],
        ] );

        $this->assertSame( [], $this->adapter()->parseStatuses( $payload ) );
    }

    public function test_parseInbound_still_returns_null_for_statuses(): void {
        $payload = $this->statusPayload( [
            [ 'id' => 'wamid.A', 'status' => 'delivered', 'timestamp' => '1', 'recipient_id' => '111', 'errors' => [] ],
        ] );

        $this->assertNull( $this->adapter()->parseInbound( $payload ) );
    }
}
