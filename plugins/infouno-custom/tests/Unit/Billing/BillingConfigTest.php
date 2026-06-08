<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Billing;

use Infouno\SaaS\Billing\BillingConfig;
use PHPUnit\Framework\TestCase;

final class BillingConfigTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__infouno_options'] = [];
    }

    public function test_access_token_from_option_when_no_constant(): void {
        update_option( 'infouno_billing', [ 'access_token' => 'opt-token' ] );
        $this->assertSame( 'opt-token', ( new BillingConfig() )->accessToken() );
    }

    public function test_access_token_empty_when_unset(): void {
        $this->assertSame( '', ( new BillingConfig() )->accessToken() );
    }

    public function test_premium_price_reads_option_as_float(): void {
        update_option( 'infouno_billing', [ 'premium_price_ars' => '14900.50' ] );
        $this->assertSame( 14900.50, ( new BillingConfig() )->premiumPriceArs() );
    }

    public function test_premium_price_zero_when_unset(): void {
        $this->assertSame( 0.0, ( new BillingConfig() )->premiumPriceArs() );
    }

    public function test_webhook_secret_and_public_key_from_option(): void {
        update_option( 'infouno_billing', [ 'webhook_secret' => 'whsec', 'public_key' => 'pk' ] );
        $cfg = new BillingConfig();
        $this->assertSame( 'whsec', $cfg->webhookSecret() );
        $this->assertSame( 'pk', $cfg->publicKey() );
    }
}
