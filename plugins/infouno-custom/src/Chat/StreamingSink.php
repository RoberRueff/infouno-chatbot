<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Sink para el widget web: emite cada fragmento como evento SSE y respeta
 * la desconexión del cliente. Equivale al callback $onChunk del ChatController.
 *
 * @param callable $emit fn(string $delta): void — escribe el delta como SSE.
 */
final class StreamingSink implements OutputSink {

    /** @var callable */
    private $emit;

    public function __construct( callable $emit ) {
        $this->emit = $emit;
    }

    public function write( string $delta ): void {
        if ( $this->isAborted() ) {
            return;
        }
        ( $this->emit )( $delta );
    }

    public function isAborted(): bool {
        return function_exists( 'connection_aborted' ) && 1 === connection_aborted();
    }

    public function finish(): void {
        // El evento 'done' lo emite ChatController tras retornar el pipeline.
    }
}
