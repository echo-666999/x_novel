<?php

namespace App\AI;

use App\Enums\AiStage;
use InvalidArgumentException;

final class PromptVersionResolver
{
    public function resolve(AiStage $stage): string
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
