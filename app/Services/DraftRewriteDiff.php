<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Models\GenerationArtifact;
use App\Models\Review;

class DraftRewriteDiff
{
    /** @return array<string, mixed>|null */
    public function forChapter(int $chapterId): ?array
    {
        $rewrite = GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapterId))
            ->latest('id')->first();

        return $rewrite === null ? null : $this->build($rewrite);
    }

    /** @return array<string, mixed>|null */
    public function forReview(Review $review): ?array
    {
        $rewrite = GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $review->generationRun->chapter_id))
            ->where('id', '<', $review->artifact_id)
            ->latest('id')->first();

        return $rewrite === null ? null : $this->build($rewrite, $review);
    }

    /** @return array<string, mixed> */
    public function build(GenerationArtifact $rewrite, ?Review $afterReview = null): array
    {
        $before = GenerationArtifact::query()->findOrFail((int) data_get($rewrite->data, 'source_artifact_id'));
        $sourceReview = Review::query()->find(data_get($rewrite->data, 'source_review_id'));
        $afterReview ??= Review::query()
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $rewrite->generationRun->chapter_id))
            ->where('id', '>', (int) ($sourceReview?->getKey() ?? 0))
            ->latest('id')->first();

        return [
            'before' => $before,
            'after' => $rewrite,
            'lines' => $this->lines((string) $before->content, (string) $rewrite->content),
            'findings' => $this->findingStatuses($sourceReview, $afterReview),
            'after_review' => $afterReview,
        ];
    }

    /** @return array<int, array{type: string, text: string}> */
    public function lines(string $before, string $after): array
    {
        $left = $this->units($before);
        $right = $this->units($after);
        $lengths = array_fill(0, count($left) + 1, array_fill(0, count($right) + 1, 0));

        for ($i = count($left) - 1; $i >= 0; $i--) {
            for ($j = count($right) - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $left[$i] === $right[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $rows = [];
        for ($i = 0, $j = 0; $i < count($left) || $j < count($right);) {
            if ($i < count($left) && $j < count($right) && $left[$i] === $right[$j]) {
                $rows[] = ['type' => 'unchanged', 'text' => $left[$i++]];
                $j++;
            } elseif ($j < count($right) && ($i === count($left) || $lengths[$i][$j + 1] >= $lengths[$i + 1][$j])) {
                $rows[] = ['type' => 'added', 'text' => $right[$j++]];
            } else {
                $rows[] = ['type' => 'removed', 'text' => $left[$i++]];
            }
        }

        return $rows;
    }

    /** @return array<int, string> */
    private function units(string $content): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/(?<=[。！？!?])|\R+/u', trim($content)) ?: []), fn (string $unit): bool => $unit !== ''));
    }

    /** @return array<int, array<string, mixed>> */
    private function findingStatuses(?Review $before, ?Review $after): array
    {
        if ($before === null) {
            return [];
        }
        $afterFindings = collect($after?->findings ?? []);

        return collect($before->findings)->map(function (array $finding) use ($after, $afterFindings): array {
            $matched = $afterFindings->contains(fn (array $candidate): bool => filled($finding['code'] ?? null)
                ? ($candidate['code'] ?? null) === $finding['code']
                : (($candidate['dimension'] ?? null) === ($finding['dimension'] ?? null)
                    && ($candidate['message'] ?? null) === ($finding['message'] ?? null)));

            return [
                ...$finding,
                'resolution' => $after === null ? 'pending' : ($matched ? 'unresolved' : 'resolved'),
            ];
        })->all();
    }
}
