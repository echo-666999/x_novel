<?php

namespace App\Services;

use App\Data\SmokeRunProgress;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Models\Novel;
use App\Models\UsageRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmokeRunService
{
    public const CHAPTER_TARGET = 20;

    public function completeIfTargetReached(Novel $novel, int $committedSequence): bool
    {
        $target = data_get($novel->settings, 'smoke_run.target_sequence');

        if (! is_numeric($target) || data_get($novel->settings, 'smoke_run.status') !== 'running' || $committedSequence < (int) $target) {
            return false;
        }

        DB::transaction(function () use ($novel, $committedSequence): void {
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $target = data_get($locked->settings, 'smoke_run.target_sequence');

            if (! is_numeric($target) || data_get($locked->settings, 'smoke_run.status') !== 'running' || $committedSequence < (int) $target) {
                return;
            }

            $settings = $locked->settings ?? [];
            $settings['auto_generate'] = false;
            $settings['smoke_run']['status'] = 'completed';
            $settings['smoke_run']['completed_at'] = now()->toISOString();
            $locked->update(['settings' => $settings]);
        }, 3);

        return true;
    }

    /** @return Collection<int, SmokeRunProgress> */
    public function allProgress(): Collection
    {
        return Novel::query()->orderBy('title')->get()
            ->filter(fn (Novel $novel): bool => is_numeric(data_get($novel->settings, 'smoke_run.start_sequence')))
            ->map(fn (Novel $novel): SmokeRunProgress => $this->progress($novel))
            ->values();
    }

    public function progress(Novel $novel): SmokeRunProgress
    {
        $start = (int) data_get($novel->settings, 'smoke_run.start_sequence');
        $target = (int) data_get($novel->settings, 'smoke_run.target_sequence');
        $canonicalSequences = $novel->chapters()
            ->where('status', ChapterStatus::Canonical)
            ->whereBetween('sequence', [$start, $target])
            ->pluck('sequence');
        $highest = $canonicalSequences->max();
        $expectedThrough = min($target, max($start - 1, (int) $highest));
        $expected = $expectedThrough < $start ? collect() : collect(range($start, $expectedThrough));
        $missing = $expected->diff($canonicalSequences)->values()->map(fn (mixed $value): int => (int) $value)->all();
        $duplicates = $novel->chapters()
            ->where('status', ChapterStatus::Canonical)
            ->whereBetween('sequence', [$start, $target])
            ->select('sequence')
            ->groupBy('sequence')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $stateChapterIds = $novel->storyStateVersions()
            ->whereNotNull('chapter_id')
            ->whereHas('chapter', fn ($query) => $query->whereBetween('sequence', [$start, $target]))
            ->pluck('chapter_id')
            ->unique();
        $stateVersions = $novel->storyStateVersions()
            ->whereNotNull('chapter_id')
            ->whereHas('chapter', fn ($query) => $query->whereBetween('sequence', [$start, $target]))
            ->orderBy('version')
            ->pluck('version')
            ->unique()
            ->values();
        $canonicalChapterIds = $novel->chapters()
            ->where('status', ChapterStatus::Canonical)
            ->whereBetween('sequence', [$start, $target])
            ->pluck('id');
        $cost = UsageRecord::query()
            ->whereHas('generationRun', fn ($query) => $query
                ->where('novel_id', $novel->getKey())
                ->whereHas('chapter', fn ($query) => $query->whereBetween('sequence', [$start, $target])))
            ->sum('estimated_cost');

        return new SmokeRunProgress(
            novelId: $novel->getKey(),
            novelTitle: $novel->title,
            startSequence: $start,
            targetSequence: $target,
            canonicalChapters: $canonicalSequences->unique()->count(),
            status: $this->status($novel),
            cost: (float) $cost,
            hasDuplicateCommit: $duplicates,
            missingSequences: $missing,
            stateContinuous: $canonicalChapterIds->diff($stateChapterIds)->isEmpty()
                && $this->versionsAreContinuous($stateVersions),
        );
    }

    private function status(Novel $novel): string
    {
        if (data_get($novel->settings, 'smoke_run.status') === 'completed') {
            return 'completed';
        }

        if ($novel->status === NovelStatus::Paused) {
            return 'paused';
        }

        if (data_get($novel->settings, 'auto_stop') !== null || ! data_get($novel->settings, 'auto_generate', false)) {
            return 'stopped';
        }

        return 'running';
    }

    /** @param Collection<int, int> $versions */
    private function versionsAreContinuous(Collection $versions): bool
    {
        if ($versions->isEmpty()) {
            return true;
        }

        return $versions->every(
            fn (int $version, int $index): bool => $index === 0 || $version === $versions[$index - 1] + 1,
        );
    }
}
