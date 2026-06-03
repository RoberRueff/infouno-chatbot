<?php

declare(strict_types=1);

namespace Infouno\SaaS\LLM;

/**
 * Contrato que deben implementar todos los proveedores de LLM.
 * $onChunk recibe cada fragmento de texto a medida que llega del proveedor.
 */
interface LLMProviderInterface {

    /**
     * @param  array<array{role:string,content:string}> $messages  Historial + mensaje actual.
     * @param  array{model:string,max_tokens:int,temperature:float} $options
     * @param  callable(string $delta): void $onChunk  Llamado por cada fragmento de texto recibido.
     * @return StreamResult  Contiene tokens consumidos y motivo de parada.
     * @throws \RuntimeException  Con código HTTP si el proveedor falla (429, 500, etc).
     */
    public function streamChat( array $messages, array $options, callable $onChunk ): StreamResult;

    public function providerName(): string;
}
