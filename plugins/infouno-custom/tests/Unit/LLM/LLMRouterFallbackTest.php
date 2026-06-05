<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\LLM;

use Infouno\SaaS\LLM\LLMProviderInterface;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\LLM\StreamResult;
use PHPUnit\Framework\TestCase;

final class LLMRouterFallbackTest extends TestCase {

    /** Provider configurable: emite N deltas y opcionalmente lanza después. */
    private function provider( array $deltas, ?int $throwCode, int $in = 5, int $out = 7 ): LLMProviderInterface {
        return new class( $deltas, $throwCode, $in, $out ) implements LLMProviderInterface {
            public int $calls = 0;
            public function __construct(
                private array $deltas,
                private ?int $throwCode,
                private int $in,
                private int $out,
            ) {}
            public function streamChat( array $messages, array $options, callable $onChunk ): StreamResult {
                $this->calls++;
                foreach ( $this->deltas as $d ) { $onChunk( $d ); }
                if ( null !== $this->throwCode ) {
                    throw new \RuntimeException( 'boom', $this->throwCode );
                }
                return new StreamResult( $this->in, $this->out, 'stop', 'anthropic', 'm' );
            }
            public function providerName(): string {
                return 'fake';
            }
        };
    }

    public function test_no_fallback_after_first_delta_emitted(): void {
        // primario emite 1 delta y LUEGO lanza 503 → NO debe usar fallback (re-lanza).
        $primary  = $this->provider( [ 'Hola' ], 503 );
        $fallback = $this->provider( [ 'NO-DEBE-VERSE' ], null );

        $router  = new LLMRouter( [ 'anthropic' => $primary, 'openai' => $fallback ] );
        $emitted = [];

        $this->expectException( \RuntimeException::class );
        try {
            $router->stream(
                [ 'llm_provider' => 'anthropic', 'llm_model' => 'claude-haiku-4-5-20251001', 'settings' => [] ],
                [ [ 'role' => 'user', 'content' => 'hi' ] ],
                function ( string $d ) use ( &$emitted ) { $emitted[] = $d; },
                'free'
            );
        } finally {
            $this->assertSame( [ 'Hola' ], $emitted );  // solo el delta del primario, NUNCA el fallback
            $this->assertSame( 0, $fallback->calls );    // fallback jamás se invocó
        }
    }

    public function test_fallback_runs_when_primary_fails_before_emitting(): void {
        // primario lanza 503 SIN emitir → fallback toma el relevo y responde.
        $primary  = $this->provider( [], 503 );
        $fallback = $this->provider( [ 'desde-fallback' ], null, 3, 4 );

        $router  = new LLMRouter( [ 'anthropic' => $primary, 'openai' => $fallback ] );
        $emitted = [];

        $result = $router->stream(
            [ 'llm_provider' => 'anthropic', 'llm_model' => 'claude-haiku-4-5-20251001', 'settings' => [] ],
            [ [ 'role' => 'user', 'content' => 'hi' ] ],
            function ( string $d ) use ( &$emitted ) { $emitted[] = $d; },
            'free'
        );

        $this->assertSame( [ 'desde-fallback' ], $emitted );
        $this->assertSame( 1, $fallback->calls );
        $this->assertSame( 7, $result->totalTokens() ); // 3 + 4 del fallback
    }
}
