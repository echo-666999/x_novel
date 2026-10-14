<?php

namespace App\AI;

use App\Enums\AiStage;
use InvalidArgumentException;

final class PromptVersionResolver
{
    /** 返回写入 Run 与 Input Hash 的有效版本：基础 Prompt 版本 + 可选共享策略版本。 */
    public function resolve(AiStage $stage): string
    {
        $version = $this->resolveBase($stage);
        $policy = config("prompts.stage_policies.{$stage->value}");

        if ($policy === null) {
            return $version;
        }

        if (! is_string($policy) || blank($policy)) {
            throw new InvalidArgumentException("Prompt policy is invalid for stage [{$stage->value}].");
        }

        return $version.'+'.$this->resolvePolicy($policy);
    }

    /** 返回代码中当前 Prompt 本体的基础版本，不包含共享叙事策略。 */
    public function resolveBase(AiStage $stage): string
    {
        $version = config("prompts.versions.{$stage->value}");

        if (! is_string($version) || blank($version)) {
            throw new InvalidArgumentException("Prompt version is not configured for stage [{$stage->value}].");
        }

        return $version;
    }

    /**
     * 解析没有独立 AiStage、但会直接调用 Provider 的任务版本。
     */
    public function resolveTask(string $task): string
    {
        $configuration = config("prompts.tasks.{$task}");

        if (! is_array($configuration)) {
            throw new InvalidArgumentException("Prompt task is not configured [{$task}].");
        }

        $version = $configuration['version'] ?? null;
        if (! is_string($version) || blank($version)) {
            throw new InvalidArgumentException("Prompt version is not configured for task [{$task}].");
        }

        $policy = $configuration['policy'] ?? null;
        if ($policy === null) {
            return $version;
        }

        if (! is_string($policy) || blank($policy)) {
            throw new InvalidArgumentException("Prompt policy is invalid for task [{$task}].");
        }

        return $version.'+'.$this->resolvePolicy($policy);
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

    /** 返回 NarrativeProsePolicy 某个方法对应的独立版本。 */
    private function resolvePolicy(string $policy): string
    {
        $version = config("prompts.policies.{$policy}");

        if (! is_string($version) || blank($version)) {
            throw new InvalidArgumentException("Narrative policy version is not configured [{$policy}].");
        }

        return $version;
    }
}
