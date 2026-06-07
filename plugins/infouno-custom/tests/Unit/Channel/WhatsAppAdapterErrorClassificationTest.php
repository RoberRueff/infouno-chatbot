<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\WhatsAppAdapter;
use Infouno\SaaS\Channel\WhatsAppGraphException;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class WhatsAppAdapterErrorClassificationTest extends TestCase {

    private CredentialVault $vault;

    protected function setUp(): void {
        $this->vault = new CredentialVault( str_repeat( 'a', 64 ) );
    }

    private function channel(): array {
        $creds = [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ];
        return [
            'credentials'           => $this->vault->encryptArray( $creds ),
            'credentials_decrypted' => $creds,
        ];
    }

    private function adapterWithHttpCode( int $code, int $errorCode ): WhatsAppAdapter {
        $body = json_encode( [ 'error' => [
            'code'    => $errorCode,
            'message' => 'Test error',
            'type'    => 'OAuthException',
        ] ] );
        $http = new class( $code, $body ) implements ChannelHttpClient {
            public function __construct( private int $code, private string $body ) {}
            public function postJson( string $url, array $headers, array $body ): array {
                return [ 'code' => $this->code, 'body' => $this->body ];
            }
        };
        return new WhatsAppAdapter( $this->vault, $http );
    }

    /** Código 100 = parámetro inválido → permanente, no reintento. */
    public function test_permanent_error_100_throws_non_retryable(): void {
        $adapter = $this->adapterWithHttpCode( 400, 100 );

        $this->expectException( WhatsAppGraphException::class );
        $this->expectExceptionCode( 400 );

        try {
            $adapter->send( $this->channel(), '5491111', 'Hola' );
        } catch ( WhatsAppGraphException $e ) {
            $this->assertFalse( $e->isRetryable() );
            throw $e;
        }
    }

    /** Código 131047 = fuera de ventana / re-engagement → permanente. */
    public function test_permanent_error_131047_throws_non_retryable(): void {
        $adapter = $this->adapterWithHttpCode( 400, 131047 );

        $this->expectException( WhatsAppGraphException::class );

        try {
            $adapter->send( $this->channel(), '5491111', 'Hola' );
        } catch ( WhatsAppGraphException $e ) {
            $this->assertFalse( $e->isRetryable() );
            $this->assertSame( 131047, $e->graphCode() );
            throw $e;
        }
    }

    /** Código 131026 = no entregable → permanente. */
    public function test_permanent_error_131026_throws_non_retryable(): void {
        $adapter = $this->adapterWithHttpCode( 400, 131026 );

        $this->expectException( WhatsAppGraphException::class );

        try {
            $adapter->send( $this->channel(), '5491111', 'Hola' );
        } catch ( WhatsAppGraphException $e ) {
            $this->assertFalse( $e->isRetryable() );
            throw $e;
        }
    }

    /** HTTP 429 = rate limit → transitorio, debe reintentarse. */
    public function test_rate_limit_429_throws_retryable(): void {
        $adapter = $this->adapterWithHttpCode( 429, 4 );

        $this->expectException( WhatsAppGraphException::class );

        try {
            $adapter->send( $this->channel(), '5491111', 'Hola' );
        } catch ( WhatsAppGraphException $e ) {
            $this->assertTrue( $e->isRetryable() );
            throw $e;
        }
    }

    /** HTTP 500 = error de servidor Meta → transitorio. */
    public function test_5xx_throws_retryable(): void {
        $adapter = $this->adapterWithHttpCode( 500, 1 );

        $this->expectException( WhatsAppGraphException::class );

        try {
            $adapter->send( $this->channel(), '5491111', 'Hola' );
        } catch ( WhatsAppGraphException $e ) {
            $this->assertTrue( $e->isRetryable() );
            throw $e;
        }
    }
}
