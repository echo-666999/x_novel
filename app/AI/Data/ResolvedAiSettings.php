<?php

namespace App\AI\Data;

use App\Enums\AiStage;

final readonly class ResolvedAiSettings
{
    public function __construct(
        public AiStage $stage,
        public string $provider,
        public string $model,
        public string $source,
    ) {}
}
