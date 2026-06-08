<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Core;

use Infouno\SaaS\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorV11Test extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__infouno_dbdelta_sql'] = [];
        $GLOBALS['wpdb']->prefix          = 'wp_';
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb']->prefix = 'wp_';
    }

    private function invokeCreate( string $method ): string {
        $migrator = new Migrator();
        $ref      = new \ReflectionMethod( $migrator, $method );
        $ref->setAccessible( true );
        $ref->invoke( $migrator, $GLOBALS['wpdb'] );
        return implode( "\n", $GLOBALS['__infouno_dbdelta_sql'] );
    }

    public function test_db_version_is_11(): void {
        $this->assertSame( '11', Migrator::DB_VERSION );
    }

    public function test_subscriptions_table_ddl(): void {
        $sql = $this->invokeCreate( 'createSubscriptionsTable' );
        $this->assertStringContainsString( 'wp_infouno_subscriptions', $sql );
        $this->assertStringContainsString( 'tenant_id', $sql );
        $this->assertStringContainsString( 'mp_preapproval_id', $sql );
        $this->assertStringContainsString( 'status', $sql );
        $this->assertStringContainsString( 'next_payment_at', $sql );
        $this->assertStringContainsString( 'last_event_ts', $sql );
        $this->assertStringContainsString( 'UNIQUE KEY', $sql );
    }

    public function test_payment_events_table_ddl(): void {
        $sql = $this->invokeCreate( 'createPaymentEventsTable' );
        $this->assertStringContainsString( 'wp_infouno_payment_events', $sql );
        $this->assertStringContainsString( 'mp_payment_id', $sql );
        $this->assertStringContainsString( 'tenant_id', $sql );
        $this->assertStringContainsString( 'UNIQUE KEY', $sql );
    }
}
