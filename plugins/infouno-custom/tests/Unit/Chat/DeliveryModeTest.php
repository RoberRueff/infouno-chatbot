<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Chat;

use Infouno\SaaS\Chat\DeliveryMode;
use PHPUnit\Framework\TestCase;

final class DeliveryModeTest extends TestCase {

    public function test_full_param_resolves_to_full(): void {
        $this->assertSame( DeliveryMode::FULL, DeliveryMode::fromRequest( 'full' ) );
    }

    public function test_null_resolves_to_sse(): void {
        $this->assertSame( DeliveryMode::SSE, DeliveryMode::fromRequest( null ) );
    }

    public function test_any_other_value_resolves_to_sse(): void {
        $this->assertSame( DeliveryMode::SSE, DeliveryMode::fromRequest( 'streaming' ) );
        $this->assertSame( DeliveryMode::SSE, DeliveryMode::fromRequest( '' ) );
    }
}
