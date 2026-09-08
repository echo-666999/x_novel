<?php

namespace App\Services;

use App\Data\ReliabilityRunSummary;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\Review;
use App\Models\StoryEvent;
use App\Models\UsageRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReliabilityRunService
{
    public const CHAPTER_TARGET = 50;

    public function completeIfTargetReached(Novel $novel, int $committedSequence): bool
    {
        $target = data_get($novel->settings, 'reliability_run.target_sequence');

        if (! is_numeric($target) || data_get($novel->settings, 'reliability_run.status') !== 'running' || $committedSequence < (int) $target) {
            return false;
        }

        DB::transaction(function () use ($novel, $committedSequence): void {
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $target = data_get($locked->settings, 'reliability_run.target_sequence');

            if (! is_numeric($target) || data_get($locked->settings, 'reliability_run.status') !== 'running' || $committedSequence < (int) $target) {
                return;
            }

            $settings = $locked->settings ?? [];
            $settings['auto_generate'] = false;
            $settings['reliability_run']['status'] = 'completed';
            $settings['reliability_run']['completed_at'] = now()->toISOString();
            $locked->update(['settings' => $settings]);
        }, 3);

        return true;
    }

    /** @return Collection<int, ReliabilityRunSummary> */
    public function allSummaries(): Collection
    {
        return Novel::query()->orderBy('title')->get()
            ->filter(fn (Novel $novel): bool => is_numeric(data_get($novel->settings, 'reliability_run.start_sequence')))
            ->map(fn (Novel $novel): ReliabilityRunSummary => $this->summary($novel))
            ->values();
    }

    public function summary(Novel $novel): ReliabilityRunSummary
    {
        $start = (int) data_get($novel->settings, 'reliability_run.start_sequence');
        $target = (int) data_get($novel->settings, 'reliability_run.target_sequence');
        $chapters = $novel->chapters()->whereBetween('sequence', [$start, $target])->get(['id', 'sequence', 'status']);
        $canonical = $chapters->where('status', ChapterStatus::Canonical);
        $chapterIds = $chapters->pluck('id');
        $canonicalIds = $canonical->pluck('id');
        $runs = GenerationRun::query()->where('novel_id', $novel->getKey())->whereIn('chapter_id', $chapterIds)->get();
        $usage = UsageRecord::query()->where('novel_id', $novel->getKey())->whereIn('chapter_id', $chapterIds)->get();
        $contextTokens = $usage->pluck('input_tokens')->map(fn (mixed $tokens): int => (int) $tokens);
        $workerLost = $runs->where('error_code', StalledRunRecoveryService::ERROR_CODE);
        $expectedThrough = min($target, max($start - 1, (int) $canonical->max('sequence')));
        $expected = $expectedThrough < $start ? collect() : collect(range($start, $expectedThrough));
        $stateVersions = $novel->storyStateVersions()
            ->whereNotNull('chapter_id')
            ->whereIn('chapter_id', $canonicalIds)
            ->orderBy('version')
            ->get(['chapter_id', 'version']);
        $memories = Memory::query()->where('novel_id', $novel->getKey())
            ->whereBetween('valid_from_chapter', [$start, $target])->get();
        $reviews = Review::query()->whereHas('generationRun', fn ($query) => $query->whereIn('chapter_id', $chapterIds))->get();
        $foreshadowingTypes = [
            EventType::ForeshadowingPlanted,
            EventType::ForeshadowingReinforced,
            EventType::ForeshadowingDue,
            EventType::ForeshadowingPaidOff,
            EventType::ForeshadowingAbandoned,
        ];

        return new ReliabilityRunSummary(
            novelId: $novel->getKey(),
            novelTitle: $novel->title,
            startSequence: $start,
            targetSequence: $target,
            canonicalChapters: $canonical->pluck('sequence')->unique()->count(),
            status: $this->status($novel),
            retryRuns: $runs->where('attempt', '>', 1)->count(),
            workerCrashes: $workerLost->count(),
            workerRecoveries: $workerLost->filter(fn (GenerationRun $run): bool => data_get($run->context_snapshot, 'recovery.resumed_at') !== null)->count(),
            memories: $memories->count(),
            embeddedMemories: $memories->whereNotNull('embedding')->count(),
            foreshadowingEvents: StoryEvent::query()->where('novel_id', $novel->getKey())->whereIn('chapter_id', $chapterIds)->whereIn('event_type', $foreshadowingTypes)->count(),
            reviews: $reviews->count(),
            rewrites: $runs->where('stage', GenerationStage::Rewrite)->count(),
            cost: round((float) $usage->sum('estimated_cost'), 6),
            costDriftPercent: $this->costDrift($usage, $chapters, $start, $target),
            averageContextTokens: $contextTokens->isEmpty() ? 0 : (int) round($contextTokens->average()),
            maximumContextTokens: (int) ($contextTokens->max() ?? 0),
            sequenceContinuous: ! $novel->chapters()->where('status', ChapterStatus::Canonical)->whereBetween('sequence', [$start, $target])->select('sequence')->groupBy('sequence')->havingRaw('COUNT(*) > 1')->exists()
                && $expected->diff($canonical->pluck('sequence'))->isEmpty(),
            stateContinuous: $canonicalIds->diff($stateVersions->pluck('chapter_id')->unique())->isEmpty()
                && $this->versionsAreContinuous($stateVersions->pluck('version')),
        );
    }

    private function status(Novel $novel): string
    {
        if (data_get($novel->settings, 'reliability_run.status') === 'completed') {
            return 'completed';
        }
        if ($novel->status === NovelStatus::Paused) {
            return 'paused';
        }

        return data_get($novel->settings, 'auto_generate', false) ? 'running' : 'stopped';
    }

    private function versionsAreContinuous(Collection $versions): bool
    {
        return $versions->values()->every(fn (int $version, int $index): bool => $index === 0 || $version === $versions[$index - 1] + 1);
    }

    private function costDrift(Collection $usage, Collection $chapters, int $start, int $target): ?float
    {
        $midpoint = $start + intdiv($target - $start + 1, 2) - 1;
        $sequenceById = $chapters->pluck('sequence', 'id');
        $firstUsage = $usage->filter(fn (UsageRecord $record): bool => ($sequenceById[$record->chapter_id] ?? $target + 1) <= $midpoint);
        $secondUsage = $usage->filter(fn (UsageRecord $record): bool => ($sequenceById[$record->chapter_id] ?? $start - 1) > $midpoint);
        $firstChapterCount = $firstUsage->pluck('chapter_id')->unique()->count();
        $secondChapterCount = $secondUsage->pluck('chapter_id')->unique()->count();

        if ($firstChapterCount === 0 || $secondChapterCount === 0) {
            return null;
        }

        $first = (float) $firstUsage->sum('estimated_cost') / $firstChapterCount;
        $second = (float) $secondUsage->sum('estimated_cost') / $secondChapterCount;

        if ($first === 0.0) {
            return null;
        }

        return round((($second - $first) / $first) * 100, 2);
    }
}
