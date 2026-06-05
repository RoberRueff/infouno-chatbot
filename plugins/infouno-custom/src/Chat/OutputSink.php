<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Abstracción del transporte de salida del pipeline de chat.
 * Permite que ChatPipeline sea agnóstico del canal: web (SSE) vs redes (bufferizado).
 */
interface OutputSink {
    /** Emite un fragmento de la respuesta del LLM. */
    public function write( string $delta ): void;

    /** ¿El cliente cortó la conexión? (web: connection_aborted; canales: siempre false). */
    public function isAborted(): bool;

    /** Cierra el stream (web: evento 'done'; canales: no-op). */
    public function finish(): void;
}
