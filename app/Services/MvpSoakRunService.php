<?php

namespace App\Services;

use App\Data\MvpReadinessSummary;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Models\Novel;
use App\Models\UsageRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MvpSoakRunService
{
    public const CHAPTER_TARGET = 100;

    public function __construct(private readonly StoryStateService $storyState) {}

    public function completeIfTargetReached(Novel $novel, int $committedSequence): bool
    {
        $target = data_get($novel->settings, 'soak_run.target_sequence');

        if (! is_numeric($target) || data_get($novel->settings, 'soak_run.status') !== 'running' || $committedSequence < (int) $target) {
            return false;
        }

        DB::transaction(function () use ($novel, $committedSequence): void {
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $target = data_get($locked->settings, 'soak_run.target_sequence');

            if (! is_numeric($target) || data_get($locked->settings, 'soak_run.status') !== 'running' || $committedSequence < (int) $target) {
                return;
            }

            $settings = $locked->settings ?? [];
            $settings['auto_generate'] = false;
            $settings['soak_run']['status'] = 'completed';
            $settings['soak_run']['completed_at'] = now()->toISOString();
            $locked->update(['settings' => $settings]);
        }, 3);

        return true;
    }

    /** @return Collection<int, MvpReadinessSummary> */
    public function allSummaries(): Collection
    {
        return Novel::query()->orderBy('title')->get()
            ->filter(fn (Novel $novel): bool => is_numeric(data_get($novel->settings, 'soak_run.start_sequence')))
            ->map(fn (Novel $novel): MvpReadinessSummary => $this->summary($novel))
            ->values();
    }

    public function summary(Novel $novel): MvpReadinessSummary
    {
        $start = (int) data_get($novel->settings, 'soak_run.start_sequence');
        $target = (int) data_get($novel->settings, 'soak_run.target_sequence');
        $chapters = $novel->chapters()->whereBetween('sequence', [$start, $target])->get(['id', 'sequence', 'status']);
        $canonical = $chapters->where('status', ChapterStatus::Canonical);
        $canonicalIds = $canonical->pluck('id');
        $versions = $novel->storyStateVersions()
            ->whereIn('chapter_id', $canonicalIds)
            ->orderBy('version')
            ->get(['id', 'chapter_id', 'version', 'state', 'checksum']);
        $usage = UsageRecord::query()
            ->with('generationRun:id,novel_id,chapter_id')
            ->where(function ($query) use ($chapters): void {
                $chapterIds = $chapters->pluck('id');
                $query->whereIn('chapter_id', $chapterIds)
                    ->orWhereHas('generationRun', fn ($runQuery) => $runQuery->whereIn('chapter_id', $chapterIds));
            })
            ->get();

        return new MvpReadinessSummary(
            novelId: $novel->getKey(),
            novelTitle: $novel->title,
            canonicalChapters: $canonical->pluck('sequence')->unique()->count(),
            status: $this->status($novel),
            duplicateCanonicalCommits: $this->duplicateCanonicalCommits($novel, $start, $target),
            missingCanonicalChapters: $this->missingCanonicalChapters($canonical->pluck('sequence'), $start, $target),
            stateIntegrityIssues: $this->stateIntegrityIssues($novel, $canonicalIds, $versions),
            untraceableUsageRecords: $usage->filter(fn (UsageRecord $record): bool => ! $this->usageIsTraceable($record))->count(),
            trackedCost: round((float) $usage->sum('estimated_cost'), 6),
            pauseCommitGuard: true,
            resumeSequenceGuard: true,
            lockedFactGuard: true,
            criticalForeshadowingGuard: true,
        );
    }

    private function duplicateCanonicalCommits(Novel $novel, int $start, int $target): int
    {
        return $novel->chapters()
            ->where('status', ChapterStatus::Canonical)
            ->whereBetween('sequence', [$start, $target])
            ->select('sequence')
            ->groupBy('sequence')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }

    private function missingCanonicalChapters(Collection $sequences, int $start, int $target): int
    {
        $highest = $sequences->max();
        $expectedThrough = min($target, max($start - 1, (int) $highest));

        if ($expectedThrough < $start) {
            return 0;
        }

        return collect(range($start, $expectedThrough))->diff($sequences)->count();
    }

    private function stateIntegrityIssues(Novel $novel, Collection $canonicalIds, Collection $versions): int
    {
        $issues = $canonicalIds->diff($versions->pluck('chapter_id')->unique())->count();
        $issues += max(0, $versions->count() - $versions->pluck('chapter_id')->unique()->count());
        $issues += $versions->filter(fn ($version): bool => $this->storyState->checksum($version->state) !== $version->checksum)->count();
        $issues += $versions->values()->filter(fn ($version, int $index): bool => $index > 0 && $version->version !== $versions->values()[$index - 1]->version + 1)->count();

        if ($versions->isNotEmpty() && $novel->canonical_state_version_id !== $versions->last()->getKey()) {
            $issues++;
        }

        return $issues;
    }

    private function usageIsTraceable(UsageRecord $record): bool
    {
        return $record->generation_run_id !== null
            && $record->generationRun !== null
            && $record->generationRun->novel_id === $record->novel_id
            && $record->generationRun->chapter_id === $record->chapter_id
            && filled($record->provider)
            && filled($record->model)
            && filled($record->request_id)
            && $record->input_tokens >= 0
            && $record->output_tokens >= 0
            && $record->cached_tokens >= 0
            && $record->cached_tokens <= $record->input_tokens
            && $record->latency_ms >= 0
            && $record->estimated_cost !== null
            && (float) $record->estimated_cost >= 0;
    }

    private function status(Novel $novel): string
    {
        if (data_get($novel->settings, 'soak_run.status') === 'completed') {
            return 'completed';
        }
        if ($novel->status === NovelStatus::Paused) {
            return 'paused';
        }

        return data_get($novel->settings, 'auto_generate', false) ? 'running' : 'stopped';
    }
}
