<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelConsentService;
use PHPUnit\Framework\TestCase;

final class ChannelConsentServiceTest extends TestCase {

    public function test_first_contact_records_consent_and_returns_true(): void {
        // get_var devuelve 0 → no hay consentimiento previo.
        $GLOBALS['wpdb']->stub_get_var = 0;
        $inserts = [];
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$inserts ) {
            $inserts[] = $table;
        };

        $svc          = new ChannelConsentService();
        $isFirst      = $svc->ensure( 3, 7, 'telegram', 'tg:55' );

        $this->assertTrue( $isFirst );
        // Debe insertar en consents y lead_consents.
        $this->assertContains( 'wp_infouno_consents', $inserts );
        $this->assertContains( 'wp_infouno_lead_consents', $inserts );
    }

    public function test_existing_consent_returns_false_and_inserts_nothing(): void {
        $GLOBALS['wpdb']->stub_get_var = 1; // ya existe consentimiento
        $inserts = [];
        $GLOBALS['wpdb']->onInsert = function ( string $table ) use ( &$inserts ) {
            $inserts[] = $table;
        };

        $svc = new ChannelConsentService();

        $this->assertFalse( $svc->ensure( 3, 7, 'telegram', 'tg:55' ) );
        $this->assertSame( [], $inserts );
    }
}
