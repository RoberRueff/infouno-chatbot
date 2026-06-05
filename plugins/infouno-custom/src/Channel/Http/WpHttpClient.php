<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel\Http;

/** Implementación sobre wp_remote_post. Timeout corto: el worker corre en background. */
final class WpHttpClient implements ChannelHttpClient {

    public function postJson( string $url, array $headers, array $body ): array {
        $headers['Content-Type'] = 'application/json';

        $response = wp_remote_post( $url, [
            'headers' => $headers,
            'body'    => (string) wp_json_encode( $body ),
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'code' => 0, 'body' => $response->get_error_message() ];
        }

        return [
            'code' => (int) wp_remote_retrieve_response_code( $response ),
            'body' => (string) wp_remote_retrieve_body( $response ),
        ];
    }
}
