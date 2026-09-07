<?php

namespace App\Data;

final readonly class ContextRequest
{
    public function __construct(
        public int $novelId,
        public int $chapterId,
        public ?int $sceneId,
        public string $taskType,
        public int $stateVersion,
        public int $chapterPlanId,
        public int $tokenBudget,
        public string $promptVersion,
        public string $model,
        public ?int $previousArtifactId = null,
    ) {}
}
