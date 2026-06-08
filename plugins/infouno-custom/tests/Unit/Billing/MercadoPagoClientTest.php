<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Billing;

use Infouno\SaaS\Billing\HttpClientInterface;
use Infouno\SaaS\Billing\MercadoPagoClient;
use Infouno\SaaS\Billing\MercadoPagoException;
use PHPUnit\Framework\TestCase;

final class MercadoPagoClientTest extends TestCase {

    private function fakeHttp( int $status, string $body, ?array &$captured = null ): HttpClientInterface {
        return new class( $status, $body, $captured ) implements HttpClientInterface {
            public function __construct( private int $status, private string $body, private ?array &$captured ) {}
            public function post( string $url, array $headers, string $b ): array {
                $this->captured = [ 'method' => 'POST', 'url' => $url, 'headers' => $headers, 'body' => $b ];
                return [ 'status' => $this->status, 'body' => $this->body ];
            }
            public function get( string $url, array $headers ): array {
                $this->captured = [ 'method' => 'GET', 'url' => $url, 'headers' => $headers, 'body' => '' ];
                return [ 'status' => $this->status, 'body' => $this->body ];
            }
        };
    }

    public function test_createPreapproval_posts_with_bearer_and_parses_body(): void {
        $captured = null;
        $http = $this->fakeHttp( 201, json_encode( [ 'id' => 'pa-1', 'init_point' => 'https://mp/checkout' ] ), $captured );
        $client = new MercadoPagoClient( $http, 'tok-123' );

        $res = $client->createPreapproval( [ 'reason' => 'Premium' ] );

        $this->assertSame( 'pa-1', $res['id'] );
        $this->assertSame( 'https://mp/checkout', $res['init_point'] );
        $this->assertStringContainsString( '/preapproval', $captured['url'] );
        $this->assertSame( 'Bearer tok-123', $captured['headers']['Authorization'] );
        $this->assertStringContainsString( 'Premium', $captured['body'] );
    }

    public function test_getPayment_gets_and_parses(): void {
        $http = $this->fakeHttp( 200, json_encode( [ 'id' => 'pay-9', 'status' => 'approved' ] ) );
        $client = new MercadoPagoClient( $http, 'tok' );
        $res = $client->getPayment( 'pay-9' );
        $this->assertSame( 'approved', $res['status'] );
    }

    public function test_error_status_throws(): void {
        $http = $this->fakeHttp( 401, '{"message":"unauthorized"}' );
        $client = new MercadoPagoClient( $http, 'bad' );
        $this->expectException( MercadoPagoException::class );
        $client->getPreapproval( 'pa-1' );
    }

    public function test_cancelPreapproval_returns_true_on_2xx(): void {
        $http = $this->fakeHttp( 200, json_encode( [ 'id' => 'pa-1', 'status' => 'cancelled' ] ) );
        $client = new MercadoPagoClient( $http, 'tok' );
        $this->assertTrue( $client->cancelPreapproval( 'pa-1' ) );
    }
}
