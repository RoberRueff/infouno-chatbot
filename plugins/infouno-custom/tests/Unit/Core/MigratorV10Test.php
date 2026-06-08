<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Core;

use Infouno\SaaS\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorV10Test extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__infouno_dbdelta_sql'] = [];
        $GLOBALS['wpdb']->prefix          = 'wp_';
    }

    protected function tearDown(): void {
        // test_table_name_uses_wpdb_prefix muta el prefix global; restaurarlo evita
        // contaminar el estado de las clases de test que corren después (orden global).
        $GLOBALS['wpdb']->prefix = 'wp_';
    }

    /** @return string Concatenación de todo el SQL pasado a dbDelta(). */
    private function invokeCreate( string $method ): string {
        $migrator = new Migrator();
        $ref      = new \ReflectionMethod( $migrator, $method );
        $ref->setAccessible( true );
        $ref->invoke( $migrator, $GLOBALS['wpdb'], 'CHARACTER SET utf8mb4' );

        return implode( "\n", $GLOBALS['__infouno_dbdelta_sql'] );
    }


    public function test_channel_templates_table_sql_is_tenant_scoped(): void {
        $sql = $this->invokeCreate( 'createChannelTemplatesTable' );

        $this->assertStringContainsString( 'wp_infouno_channel_templates', $sql );
        $this->assertStringContainsString( 'tenant_id', $sql );
        $this->assertStringContainsString( 'channel_id', $sql );
        $this->assertStringContainsString( "ENUM('approved','pending','rejected')", $sql );
        $this->assertStringContainsString( 'variables_schema', $sql );
    }

    public function test_channel_deliveries_table_sql_has_unique_wamid(): void {
        $sql = $this->invokeCreate( 'createChannelDeliveriesTable' );

        $this->assertStringContainsString( 'wp_infouno_channel_deliveries', $sql );
        $this->assertStringContainsString( 'tenant_id', $sql );
        $this->assertStringContainsString( 'external_msg_id', $sql );
        $this->assertStringContainsString( 'UNIQUE KEY external_msg_id', $sql );
        $this->assertStringContainsString( "ENUM('sent','delivered','read','failed')", $sql );
    }

    public function test_table_name_uses_wpdb_prefix(): void {
        $GLOBALS['wpdb']->prefix = 'mysite_';
        $sql = $this->invokeCreate( 'createChannelTemplatesTable' );

        $this->assertStringContainsString( 'mysite_infouno_channel_templates', $sql );
    }
}
