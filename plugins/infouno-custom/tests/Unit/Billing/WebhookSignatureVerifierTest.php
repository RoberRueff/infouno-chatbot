<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Billing;

use Infouno\SaaS\Billing\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureVerifierTest extends TestCase {

    private const SECRET = 'whsec_test';

    private function sign( string $dataId, string $requestId, int $ts ): string {
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac( 'sha256', $manifest, self::SECRET );
        return "ts={$ts},v1={$v1}";
    }

    public function test_valid_signature_within_window(): void {
        $ts     = 1_000_000;
        $header = $this->sign( '123', 'req-1', $ts );
        $v = new WebhookSignatureVerifier( self::SECRET, 300 );
        $this->assertTrue( $v->verify( $header, 'req-1', '123', $ts + 10 ) );
    }

    public function test_tampered_signature_rejected(): void {
        $ts     = 1_000_000;
        $header = 'ts=' . $ts . ',v1=deadbeef';
        $v = new WebhookSignatureVerifier( self::SECRET, 300 );
        $this->assertFalse( $v->verify( $header, 'req-1', '123', $ts + 10 ) );
    }

    public function test_stale_ts_rejected_as_replay(): void {
        $ts     = 1_000_000;
        $header = $this->sign( '123', 'req-1', $ts );
        $v = new WebhookSignatureVerifier( self::SECRET, 300 );
        $this->assertFalse( $v->verify( $header, 'req-1', '123', $ts + 600 ) );
    }

    public function test_malformed_header_rejected(): void {
        $v = new WebhookSignatureVerifier( self::SECRET, 300 );
        $this->assertFalse( $v->verify( 'garbage', 'req-1', '123', 1_000_000 ) );
    }

    public function test_empty_secret_rejects(): void {
        $ts     = 1_000_000;
        $header = $this->sign( '123', 'req-1', $ts );
        $v = new WebhookSignatureVerifier( '', 300 );
        $this->assertFalse( $v->verify( $header, 'req-1', '123', $ts ) );
    }

    public function test_future_dated_ts_rejected_as_replay(): void {
        // ts 600 s en el futuro respecto a now → fuera de la ventana (otra dirección del abs()).
        $ts     = 1_000_600;
        $header = $this->sign( '123', 'req-1', $ts );
        $v = new WebhookSignatureVerifier( self::SECRET, 300 );
        $this->assertFalse( $v->verify( $header, 'req-1', '123', 1_000_000 ) );
    }

    public function test_signature_for_other_resource_rejected(): void {
        // Firma válida para dataId '123' pero se verifica contra '999' → manifiesto distinto.
        $ts     = 1_000_000;
        $header = $this->sign( '123', 'req-1', $ts );
        $v = new WebhookSignatureVerifier( self::SECRET, 300 );
        $this->assertFalse( $v->verify( $header, 'req-1', '999', $ts + 10 ) );
    }
}
