<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Sink para canales asíncronos: acumula la respuesta completa en memoria.
 * No hay cliente conectado, por lo que isAborted() siempre es false.
 */
final class BufferedSink implements OutputSink {

    private string $buffer = '';

    public function write( string $delta ): void {
        $this->buffer .= $delta;
    }

    public function isAborted(): bool {
        return false;
    }

    public function finish(): void {
        // no-op: el contenido se recupera con getBuffer()
    }

    public function getBuffer(): string {
        return $this->buffer;
    }
}
