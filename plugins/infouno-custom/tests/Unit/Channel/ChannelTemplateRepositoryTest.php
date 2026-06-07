<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelTemplateRepository;
use PHPUnit\Framework\TestCase;

final class ChannelTemplateRepositoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->onInsert      = null;
        $GLOBALS['wpdb']->insert_id     = 0;
        $GLOBALS['wpdb']->stub_get_row  = null;
        $GLOBALS['wpdb']->stub_get_results = [];
    }

    public function test_findApproved_filters_tenant_and_status(): void {
        $GLOBALS['wpdb']->stub_get_results = [
            [ 'id' => 1, 'tenant_id' => 3, 'channel_id' => 7, 'name' => 'bienvenida', 'language' => 'es_AR', 'status' => 'approved' ],
        ];

        $repo      = new ChannelTemplateRepository();
        $templates = $repo->findApproved( tenantId: 3, channelId: 7 );

        $this->assertCount( 1, $templates );
        $this->assertSame( 'bienvenida', $templates[0]['name'] );
    }

    public function test_findByName_returns_null_when_not_found(): void {
        $GLOBALS['wpdb']->stub_get_row = null;

        $repo = new ChannelTemplateRepository();
        $this->assertNull( $repo->findByName( tenantId: 3, channelId: 7, name: 'no-existe' ) );
    }

    public function test_findByName_returns_template_row(): void {
        $GLOBALS['wpdb']->stub_get_row = [
            'id' => 1, 'tenant_id' => 3, 'channel_id' => 7, 'name' => 'reenganche', 'status' => 'approved',
        ];

        $repo     = new ChannelTemplateRepository();
        $template = $repo->findByName( tenantId: 3, channelId: 7, name: 'reenganche' );

        $this->assertNotNull( $template );
        $this->assertSame( 'reenganche', $template['name'] );
    }
}
