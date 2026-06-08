<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

/** Transporte HTTP de producción sobre wp_remote_*. */
final class WpHttpClient implements HttpClientInterface {

    public function post( string $url, array $headers, string $body ): array {
        $res = wp_remote_post( $url, [
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 15,
        ] );
        return $this->normalize( $res );
    }

    public function get( string $url, array $headers ): array {
        $res = wp_remote_get( $url, [
            'headers' => $headers,
            'timeout' => 15,
        ] );
        return $this->normalize( $res );
    }

    /** @param mixed $res */
    private function normalize( $res ): array {
        if ( is_wp_error( $res ) ) {
            throw new MercadoPagoException( 'HTTP error: ' . $res->get_error_message() );
        }
        return [
            'status' => (int) wp_remote_retrieve_response_code( $res ),
            'body'   => (string) wp_remote_retrieve_body( $res ),
        ];
    }
}
