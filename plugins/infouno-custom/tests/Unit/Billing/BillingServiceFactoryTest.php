<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Billing;

use Infouno\SaaS\Billing\BillingServiceFactory;
use Infouno\SaaS\Billing\SubscriptionService;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class BillingServiceFactoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__infouno_options'] = [];
    }

    public function test_create_returns_subscription_service(): void {
        $svc = BillingServiceFactory::create( $this->createMock( TenantManager::class ) );
        $this->assertInstanceOf( SubscriptionService::class, $svc );
    }

    public function test_create_works_with_empty_config(): void {
        $svc = BillingServiceFactory::create( $this->createMock( TenantManager::class ) );
        $this->assertInstanceOf( SubscriptionService::class, $svc );
    }
}
