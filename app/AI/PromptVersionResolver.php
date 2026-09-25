<?php

namespace App\AI;

use App\Enums\AiStage;
use InvalidArgumentException;

final class PromptVersionResolver
{
    private const NARRATIVE_POLICY_STAGES = [
        AiStage::Planner,
        AiStage::Writer,
        AiStage::Assembler,
        AiStage::Reviewer,
        AiStage::Rewrite,
        AiStage::Summary,
    ];

    public function resolve(AiStage $stage): string
    {
        $version = $this->resolveBase($stage);

        return in_array($stage, self::NARRATIVE_POLICY_STAGES, true)
            ? $version.'+'.NarrativeProsePolicy::VERSION
            : $version;
    }

    public function resolveBase(AiStage $stage): string
    {
        $version = config("prompts.versions.{$stage->value}");

        if (! is_string($version) || blank($version)) {
            throw new InvalidArgumentException("Prompt version is not configured for stage [{$stage->value}].");
        }

        return $version;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $versions = config('prompts.versions', []);

        if (! is_array($versions)) {
            throw new InvalidArgumentException('Prompt versions configuration must be an array.');
        }

        return collect($versions)
            ->mapWithKeys(function (mixed $version, mixed $stage): array {
                if (! is_string($stage)) {
                    throw new InvalidArgumentException('Prompt version stages must be strings.');
                }

                $aiStage = AiStage::tryFrom($stage);

                if ($aiStage === null) {
                    throw new InvalidArgumentException("Unknown prompt stage [{$stage}].");
                }

                return [$aiStage->value => $this->resolve($aiStage)];
            })
            ->all();
    }
}
