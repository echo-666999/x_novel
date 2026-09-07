<?php

namespace App\Data;

final readonly class ContextSnapshot
{
    /**
     * @param  array<int, int>  $characterIds
     * @param  array<int, int>  $worldEntityIds
     * @param  array<int, int>  $foreshadowingIds
     * @param  array<int, int>  $factIds
     * @param  array<int, int>  $memoryIds
     * @param  array<int, int>  $recentChapterIds
     * @param  array<string, mixed>  $l0
     * @param  array<string, mixed>  $l1
     */
    public function __construct(
        public int $novelId,
        public int $chapterId,
        public ?int $sceneId,
        public string $taskType,
        public int $bibleVersion,
        public int $stateVersion,
        public int $chapterPlanId,
        public array $characterIds,
        public array $worldEntityIds,
        public array $foreshadowingIds,
        public array $factIds,
        public array $memoryIds,
        public array $recentChapterIds,
        public ?int $previousArtifactId,
        public string $promptVersion,
        public string $model,
        public TokenAllocation $tokenAllocation,
        public array $l0,
        public array $l1,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'novel_id' => $this->novelId,
            'chapter_id' => $this->chapterId,
            'scene_id' => $this->sceneId,
            'task_type' => $this->taskType,
            'bible_version' => $this->bibleVersion,
            'state_version' => $this->stateVersion,
            'chapter_plan_id' => $this->chapterPlanId,
            'character_ids' => $this->characterIds,
            'world_entity_ids' => $this->worldEntityIds,
            'foreshadowing_ids' => $this->foreshadowingIds,
            'fact_ids' => $this->factIds,
            'memory_ids' => $this->memoryIds,
            'recent_chapter_ids' => $this->recentChapterIds,
            'previous_artifact_id' => $this->previousArtifactId,
            'prompt_version' => $this->promptVersion,
            'model' => $this->model,
            'token_budget' => $this->tokenAllocation->budget,
            'token_allocation' => $this->tokenAllocation->toArray(),
            'truncated_sections' => $this->tokenAllocation->truncatedSections,
            'l0' => $this->l0,
            'l1' => $this->l1,
        ];
    }
}
