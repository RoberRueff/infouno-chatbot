<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Chat;

use Infouno\SaaS\Chat\BufferedSink;
use PHPUnit\Framework\TestCase;

final class BufferedSinkTest extends TestCase {

    public function test_accumulates_writes_into_buffer(): void {
        $sink = new BufferedSink();
        $sink->write( 'Hola' );
        $sink->write( ' ' );
        $sink->write( 'mundo' );
        $sink->finish();

        $this->assertSame( 'Hola mundo', $sink->getBuffer() );
    }

    public function test_never_reports_aborted(): void {
        $sink = new BufferedSink();
        $this->assertFalse( $sink->isAborted() );
    }

    public function test_empty_buffer_by_default(): void {
        $this->assertSame( '', ( new BufferedSink() )->getBuffer() );
    }
}
