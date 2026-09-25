<?php

namespace App\Data;

final readonly class GenerationFailure
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $code,
        public string $message,
        public bool $retryable,
        public array $metadata,
        public string $recommendedAction,
    ) {}
}
