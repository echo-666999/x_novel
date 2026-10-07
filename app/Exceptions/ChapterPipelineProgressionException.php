<?php

namespace App\Exceptions;

use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;
use Throwable;

final class ChapterPipelineProgressionException extends AiProviderException
{
    public function __construct(public readonly GenerationStage $sourceStage, Throwable $cause)
    {
        parent::__construct(
            'pipeline_progression_failed',
            "{$sourceStage->getLabel()}已成功，但后续流程推进失败：{$cause->getMessage()}",
            false,
            previous: $cause,
        );
    }
}
