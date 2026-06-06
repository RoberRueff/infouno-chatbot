<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Chat;

use Infouno\SaaS\Chat\DeliveryTelemetry;
use PHPUnit\Framework\TestCase;

final class DeliveryTelemetryTest extends TestCase {

    public function test_format_includes_mode_and_bot(): void {
        $this->assertSame(
            '[INFOUNO-DELIVERY] mode=full bot=42',
            DeliveryTelemetry::logLine( 'full', 42 )
        );
    }
}
