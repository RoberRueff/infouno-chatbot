<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\LLM;

use Infouno\SaaS\LLM\TokenEstimator;
use PHPUnit\Framework\TestCase;

final class TokenEstimatorTest extends TestCase {

    public function test_estimate_rounds_up_chars_over_four(): void {
        // 8 chars → 2 tokens; 9 chars → 3 (ceil 9/4 = 3)
        $this->assertSame( 2, TokenEstimator::estimate( 'abcdefgh' ) );
        $this->assertSame( 3, TokenEstimator::estimate( 'abcdefghi' ) );
    }

    public function test_estimate_minimum_is_one(): void {
        $this->assertSame( 1, TokenEstimator::estimate( '' ) );
        $this->assertSame( 1, TokenEstimator::estimate( 'a' ) );
    }

    public function test_estimate_messages_sums_each_content(): void {
        $messages = [
            [ 'role' => 'system', 'content' => 'abcdefgh' ],   // 2
            [ 'role' => 'user',   'content' => 'abcd' ],        // 1
        ];
        $this->assertSame( 3, TokenEstimator::estimateMessages( $messages ) );
    }

    public function test_estimate_messages_empty_is_zero(): void {
        $this->assertSame( 0, TokenEstimator::estimateMessages( [] ) );
    }
}
