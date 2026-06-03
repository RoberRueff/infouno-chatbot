<?php

declare(strict_types=1);

namespace Infouno\SaaS\LLM;

/** Value object con los metadatos de una llamada de streaming completada. */
final class StreamResult {

    public function __construct(
        public readonly int    $inputTokens,
        public readonly int    $outputTokens,
        public readonly string $finishReason,
        public readonly string $provider,
        public readonly string $model,
    ) {}

    public function totalTokens(): int {
        return $this->inputTokens + $this->outputTokens;
    }
}
