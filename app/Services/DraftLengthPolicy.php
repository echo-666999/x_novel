<?php

namespace App\Services;

class DraftLengthPolicy
{
    public function count(?string $content): int
    {
        return mb_strlen(trim((string) $content));
    }

    /** @return array{chapter_target_words: int, chapter_minimum_words: int, chapter_maximum_words: int, allocated_scene_words: int, remaining_chapter_words: int, remaining_scene_count: int, scene_target_words: int, required_scene_words: int, maximum_scene_words: int} */
    public function sceneAllocation(int $chapterTarget, int $allocatedWords, int $remainingSceneCount): array
    {
        $remainingSceneCount = max(1, $remainingSceneCount);
        $remainingChapterWords = max(0, $chapterTarget - $allocatedWords);
        $isFinalScene = $remainingSceneCount === 1;

        return [
            'chapter_target_words' => $chapterTarget,
            'chapter_minimum_words' => $this->chapterMinimum($chapterTarget),
            'chapter_maximum_words' => $this->chapterMaximum($chapterTarget),
            'allocated_scene_words' => $allocatedWords,
            'remaining_chapter_words' => $remainingChapterWords,
            'remaining_scene_count' => $remainingSceneCount,
            'scene_target_words' => max(1, (int) ceil($remainingChapterWords / $remainingSceneCount)),
            'required_scene_words' => $isFinalScene
                ? max(0, $this->chapterMinimum($chapterTarget) - $allocatedWords)
                : 0,
            'maximum_scene_words' => max(1, $this->chapterMaximum($chapterTarget) - $allocatedWords),
        ];
    }

    public function chapterMinimum(int $target): int
    {
        return (int) ceil($target * (float) config('generation.chapter_min_length_ratio', .85));
    }

    public function chapterMaximum(int $target): int
    {
        return (int) round($target * (float) config('generation.chapter_max_length_ratio', 1.15));
    }

    public function completionPercentage(int $actual, int $target): float
    {
        return $target > 0 ? round($actual / $target * 100, 1) : 0.0;
    }
}
