<?php

namespace App\AI;

use App\AI\Data\ResolvedAiSettings;
use App\Enums\AiStage;
use App\Models\Novel;
use InvalidArgumentException;

class AiSettingsResolver
{
    public function resolve(AiStage|string $stage, ?Novel $novel = null): ResolvedAiSettings
    {
        $stage = $stage instanceof AiStage ? $stage : AiStage::tryFrom($stage);

        if ($stage === null) {
            throw new InvalidArgumentException('Unsupported AI stage.');
        }

        $override = data_get($novel?->settings, "ai.models.{$stage->value}");

        if (is_string($override) && filled(trim($override))) {
            return new ResolvedAiSettings(
                stage: $stage,
                provider: (string) config('ai.provider'),
                model: trim($override),
                source: 'novel',
            );
        }

        $stageModel = config("ai.models.{$stage->value}");
        $model = is_string($stageModel) && filled(trim($stageModel))
            ? trim($stageModel)
            : trim((string) config('ai.model'));

        if ($model === '') {
            throw new InvalidArgumentException("AI model is not configured for stage [{$stage->value}].");
        }

        return new ResolvedAiSettings(
            stage: $stage,
            provider: (string) config('ai.provider'),
            model: $model,
            source: 'global',
        );
    }

    public function modelFor(AiStage|string $stage, ?Novel $novel = null): string
    {
        return $this->resolve($stage, $novel)->model;
    }
}
