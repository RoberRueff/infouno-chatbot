<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

use Infouno\SaaS\Persistence\SubscriptionRepository;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Construye el SubscriptionService con sus dependencias de producción.
 * Punto único de wiring — usado por RestRouter (rutas REST) y por
 * TenantBillingPanel (WP Admin), para no duplicar la construcción.
 */
final class BillingServiceFactory {

    public static function create( TenantManager $tenantManager ): SubscriptionService {
        $config = new BillingConfig();
        $client = new MercadoPagoClient( new WpHttpClient(), $config->accessToken() );

        return new SubscriptionService(
            $client,
            new SubscriptionRepository(),
            $tenantManager,
            home_url( '/billing/return' )
        );
    }
}
