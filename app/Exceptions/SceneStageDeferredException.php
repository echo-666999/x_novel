<?php

namespace App\Exceptions;

use App\AI\Exceptions\AiProviderException;

final class SceneStageDeferredException extends AiProviderException
{
    public function __construct(
        public readonly string $substage,
        public readonly int $remainingSeconds,
        public readonly int $requiredSeconds,
    ) {
        parent::__construct(
            'scene_stage_deferred',
            "Scene 子阶段 {$substage} 需要约 {$requiredSeconds} 秒，但当前 Job 只剩 {$remainingSeconds} 秒，已保存恢复点并交给新 Job。",
            true,
        );
    }
}
