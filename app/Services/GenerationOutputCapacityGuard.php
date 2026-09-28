<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Models\Chapter;

/**
 * Provider 调用前校验请求预算没有越过 Plan Admission 冻结的输出上限。
 */
final class GenerationOutputCapacityGuard
{
    public function assertWithinFrozenRoute(Chapter $chapter, AiStage $stage, int $requestedTokens): void
    {
        $snapshot = $chapter->latestPlan?->admission_snapshot;
        if (! is_array($snapshot)) {
            return;
        }

        $capacityKey = match ($stage) {
            AiStage::Extractor => 'event_extraction',
            AiStage::Reviewer => 'review',
            AiStage::Rewrite => 'rewrite',
            default => null,
        };
        $routeMaximum = (int) data_get($snapshot, "routes.{$stage->value}.max_output_tokens", 0);
        $capacityMaximum = $capacityKey === null
            ? $routeMaximum
            : (int) data_get($snapshot, "capacity.{$capacityKey}.max_output_tokens", 0);

        if ($requestedTokens < 1
            || $routeMaximum < 1
            || $capacityMaximum < 1
            || $requestedTokens > min($routeMaximum, $capacityMaximum)) {
            throw new AiProviderException(
                $stage->value.'_capacity_mismatch',
                "{$stage->getLabel()} 请求输出预算 {$requestedTokens} Token 超过 Plan Admission 冻结容量 {$capacityMaximum} Token；请缩小任务或重新规划并冻结合法 Route。",
                false,
            );
        }
    }
}
