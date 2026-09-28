<?php

namespace App\Exceptions;

use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;

final class GenerationStageDeferredException extends AiProviderException
{
    public function __construct(public readonly GenerationStage $stage, public readonly string $substage)
    {
        parent::__construct(
            'generation_stage_deferred',
            "{$stage->value} 已保存恢复点；子阶段 {$substage} 必须由新的 Queue Job attempt 执行。",
            true,
        );
    }
}
