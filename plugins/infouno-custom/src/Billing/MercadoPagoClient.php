<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

/**
 * Cliente de la API de MercadoPago (Suscripciones/preapproval + pagos).
 * Toda llamada va por el HttpClientInterface inyectado (testeable sin red).
 */
final class MercadoPagoClient implements MercadoPagoClientInterface {

    private const BASE = 'https://api.mercadopago.com';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string              $accessToken,
    ) {}

    /** @return array<string,string> */
    private function headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type'  => 'application/json',
        ];
    }

    public function createPreapproval( array $payload ): array {
        $res = $this->http->post( self::BASE . '/preapproval', $this->headers(), (string) wp_json_encode( $payload ) );
        return $this->parse( $res );
    }

    public function getPreapproval( string $id ): array {
        $res = $this->http->get( self::BASE . '/preapproval/' . rawurlencode( $id ), $this->headers() );
        return $this->parse( $res );
    }

    public function getPayment( string $id ): array {
        $res = $this->http->get( self::BASE . '/v1/payments/' . rawurlencode( $id ), $this->headers() );
        return $this->parse( $res );
    }

    public function cancelPreapproval( string $id ): bool {
        // MP cancela una suscripción seteando status='cancelled' en el preapproval.
        $res = $this->http->post(
            self::BASE . '/preapproval/' . rawurlencode( $id ),
            $this->headers(),
            (string) wp_json_encode( [ 'status' => 'cancelled' ] )
        );
        $this->parse( $res );
        return true;
    }

    /**
     * @param array{status:int, body:string} $res
     * @return array<string,mixed>
     */
    private function parse( array $res ): array {
        if ( $res['status'] >= 400 ) {
            throw new MercadoPagoException( 'MercadoPago API error (status ' . $res['status'] . ').' );
        }
        $decoded = json_decode( $res['body'], true );
        if ( ! is_array( $decoded ) ) {
            throw new MercadoPagoException( 'MercadoPago API: respuesta no parseable.' );
        }
        return $decoded;
    }
}
