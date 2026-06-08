<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

/**
 * Lectura centralizada de la configuración de billing.
 *
 * Secretos (access_token, webhook_secret): constante de entorno primero
 * (INFOUNO_MP_ACCESS_TOKEN, INFOUNO_MP_WEBHOOK_SECRET), opción de WP después.
 * Precio y public_key salen solo de la opción `infouno_billing`.
 */
final class BillingConfig {

    private const OPTION = 'infouno_billing';

    /** @return array<string,mixed> */
    private function options(): array {
        $opt = get_option( self::OPTION, [] );
        return is_array( $opt ) ? $opt : [];
    }

    private function fromEnvOrOption( string $constant, string $optionKey ): string {
        if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
            return (string) constant( $constant );
        }
        return (string) ( $this->options()[ $optionKey ] ?? '' );
    }

    public function accessToken(): string {
        return $this->fromEnvOrOption( 'INFOUNO_MP_ACCESS_TOKEN', 'access_token' );
    }

    public function webhookSecret(): string {
        return $this->fromEnvOrOption( 'INFOUNO_MP_WEBHOOK_SECRET', 'webhook_secret' );
    }

    public function publicKey(): string {
        return (string) ( $this->options()['public_key'] ?? '' );
    }

    public function premiumPriceArs(): float {
        return (float) ( $this->options()['premium_price_ars'] ?? 0.0 );
    }

    public function isConfigured(): bool {
        return '' !== $this->accessToken() && $this->premiumPriceArs() > 0;
    }
}
