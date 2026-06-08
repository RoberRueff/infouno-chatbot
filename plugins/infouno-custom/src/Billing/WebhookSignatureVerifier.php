<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

/**
 * Verifica la firma de los webhooks de MercadoPago.
 *
 * MP envía el header `x-signature: ts=<unix>,v1=<hmac>`. El HMAC-SHA256 se
 * calcula sobre `id:<dataId>;request-id:<requestId>;ts:<ts>;` con el webhook secret.
 * Se rechaza si el HMAC no coincide (hash_equals) o si el ts está fuera de ventana
 * (anti-replay). Sin secret configurado, rechaza todo (fail-closed).
 */
final class WebhookSignatureVerifier {

    public function __construct(
        private readonly string $secret,
        private readonly int    $toleranceSeconds = 300,
    ) {}

    public function verify( string $signatureHeader, string $requestId, string $dataId, int $nowTs ): bool {
        if ( '' === $this->secret ) {
            return false;
        }

        $parts = $this->parseHeader( $signatureHeader );
        if ( null === $parts ) {
            return false;
        }
        [ 'ts' => $ts, 'v1' => $v1 ] = $parts;

        if ( abs( $nowTs - $ts ) > $this->toleranceSeconds ) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $expected = hash_hmac( 'sha256', $manifest, $this->secret );

        return hash_equals( $expected, $v1 );
    }

    /** @return array{ts:int, v1:string}|null */
    private function parseHeader( string $header ): ?array {
        $ts = null;
        $v1 = null;
        foreach ( explode( ',', $header ) as $segment ) {
            $pair = explode( '=', trim( $segment ), 2 );
            if ( 2 !== count( $pair ) ) {
                continue;
            }
            if ( 'ts' === $pair[0] ) {
                $ts = (int) $pair[1];
            } elseif ( 'v1' === $pair[0] ) {
                $v1 = $pair[1];
            }
        }
        if ( null === $ts || null === $v1 || '' === $v1 ) {
            return null;
        }
        return [ 'ts' => $ts, 'v1' => $v1 ];
    }
}
