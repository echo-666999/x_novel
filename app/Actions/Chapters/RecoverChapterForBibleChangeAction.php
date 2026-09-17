<?php

namespace App\Actions\Chapters;

use App\Actions\Novels\CreateBibleVersionAction;
use App\Enums\BibleStatus;
use App\Enums\ChapterStatus;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\User;
use App\Services\GenerationJobDispatcher;
use App\Services\ProjectionRebuilder;
use App\Services\StoryArcProgressProjector;
use App\Services\StoryStateRebuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecoverChapterForBibleChangeAction
{
    private const BIBLE_CONTENT_FIELDS = [
        'logline',
        'themes',
        'tone',
        'pov',
        'tense',
        'taboos',
        'hard_constraints',
        'ending_contract',
        'style_profile',
    ];

    public function __construct(
        private readonly CreateBibleVersionAction $createBibleVersion,
        private readonly StoryStateRebuilder $storyStateRebuilder,
        private readonly ProjectionRebuilder $projectionRebuilder,
        private readonly StoryArcProgressProjector $storyArcProgressProjector,
        private readonly GenerationJobDispatcher $dispatcher,
    ) {}

    /** @return array<string, mixed> */
    public function preview(
        Novel $novel,
        int $chapterSequence,
        int $expectedBibleVersion,
        int $expectedStateVersion,
        string $targetPov,
    ): array {
        $novel = Novel::query()->with('canonicalStateVersion')->findOrFail($novel->getKey());
        $bible = $novel->currentBible()->firstOrFail();
        $chapter = $novel->chapters()->where('sequence', $chapterSequence)->firstOrFail();

        $this->assertRecoverable($novel, $bible, $chapter, $expectedBibleVersion, $expectedStateVersion, $targetPov);

        $state = $this->storyStateRebuilder->rebuild($novel);
        $projection = $this->projectionRebuilder->inspect($novel);
        $runs = $chapter->generationRuns()->with('artifacts')->orderBy('id')->get();
        $plans = $chapter->plans()->orderBy('version')->get();
        $scenes = $chapter->scenes()->orderBy('sequence')->get();
        $target = [...$this->bibleContent($bible), 'pov' => $targetPov];
        $contentDiff = collect(self::BIBLE_CONTENT_FIELDS)
            ->filter(fn (string $field): bool => $bible->{$field} !== $target[$field])
            ->mapWithKeys(fn (string $field): array => [$field => [
                'before' => $bible->{$field},
                'after' => $target[$field],
            ]])->all();
        $arcSummaries = $novel->storyArcs()->get()->map(function ($arc): array {
            $summary = $this->storyArcProgressProjector->summary($arc);

            return [
                'arc_id' => $arc->getKey(),
                'title' => $arc->title,
                'progress' => (float) $arc->progress,
                ...$summary,
            ];
        })->all();
        $entityCounts = $novel->worldEntities()->reorder()->selectRaw('type, count(*) as aggregate')->groupBy('type')->pluck('aggregate', 'type')->map(fn ($count): int => (int) $count)->all();
        $frozen = [
            'schema_version' => 1,
            'novel_id' => $novel->getKey(),
            'chapter_id' => $chapter->getKey(),
            'chapter_sequence' => $chapter->sequence,
            'expected_chapter_status' => $chapter->status->value,
            'expected_bible_version' => $expectedBibleVersion,
            'expected_bible_id' => $bible->getKey(),
            'expected_state_version' => $expectedStateVersion,
            'expected_state_checksum' => $novel->canonicalStateVersion->checksum,
            'target_bible_version' => $expectedBibleVersion + 1,
            'target_pov' => $targetPov,
            'source_plans' => $plans->map(fn ($plan): array => [
                'id' => $plan->getKey(),
                'version' => $plan->version,
                'status' => $plan->status->value,
            ])->all(),
            'source_scenes' => $scenes->map(fn ($scene): array => [
                'id' => $scene->getKey(),
                'sequence' => $scene->sequence,
                'status' => $scene->status->value,
                'current_artifact_id' => $scene->current_artifact_id,
            ])->all(),
            'source_run_ids' => $runs->pluck('id')->all(),
            'source_artifacts' => $runs->flatMap(fn ($run) => $run->artifacts->sortBy('id')->map(fn ($artifact): array => [
                'id' => $artifact->getKey(),
                'type' => $artifact->type->value,
                'checksum' => $artifact->checksum,
            ]))->values()->all(),
        ];

        return [
            ...$frozen,
            'plan_hash' => $this->hash($frozen),
            'current_chapter_status' => $chapter->status->value,
            'recovery_start' => 'chapter_planning',
            'bible_content_diff' => $contentDiff,
            'bible_metadata_changes' => [
                'source_status' => 'current → superseded',
                'new_status' => 'current',
                'version' => "{$expectedBibleVersion} → ".($expectedBibleVersion + 1),
            ],
            'affected_runs' => $runs->map(fn ($run): array => [
                'id' => $run->getKey(),
                'stage' => $run->stage->value,
                'status' => $run->status->value,
                'bible_version' => $run->bible_version,
                'state_version' => $run->state_version,
                'artifact_ids' => $run->artifacts->pluck('id')->all(),
                'usage_records' => $run->usageRecords()->count(),
            ])->all(),
            'preserved_failed_run_ids' => $runs->where('status', RunStatus::Failed)->pluck('id')->all(),
            'state_baseline_check' => [
                'baseline_version' => $state->baselineVersion,
                'current_version' => $state->currentVersion,
                'matches' => $state->matches(),
                'changes' => count($state->changes),
            ],
            'projection_check' => [
                'character_drift_ids' => $projection->characterDriftIds,
                'world_entity_drift_ids' => $projection->worldEntityDriftIds,
                'foreshadowing_drift_ids' => $projection->foreshadowingDriftIds,
                'errors' => $projection->errors,
            ],
            'world_history_candidates' => [
                'canonical_counts_by_type' => $entityCounts,
                'action' => 'evidence_required',
                'will_write' => false,
            ],
            'arc_history_candidates' => [
                'arcs' => $arcSummaries,
                'action' => 'structured_beat_evidence_required',
                'will_write' => false,
            ],
            'estimated_provider_calls' => [
                'chapter_planning' => 1,
                'scene_generation' => max(1, $chapter->scenes()->count()),
                'chapter_assembly' => 1,
                'event_extraction' => 1,
                'review' => 1,
                'rewrite' => '0-2 according to review',
            ],
        ];
    }

    /** @return array{status: string, bible_version: int, chapter_id: int, plan_hash: string, dispatched: bool} */
    public function execute(
        Novel $novel,
        int $chapterSequence,
        int $expectedBibleVersion,
        int $expectedStateVersion,
        string $targetPov,
        string $planHash,
        int $actorId,
    ): array {
        if (! User::query()->whereKey($actorId)->exists()) {
            throw ValidationException::withMessages(['actor' => '显式恢复必须提供有效操作者。']);
        }

        $result = DB::transaction(function () use ($novel, $chapterSequence, $expectedBibleVersion, $expectedStateVersion, $targetPov, $planHash, $actorId): array {
            $lockedNovel = Novel::query()->lockForUpdate()->with('canonicalStateVersion')->findOrFail($novel->getKey());
            $existing = data_get($lockedNovel->settings, "recovery.bible_chapter.{$planHash}");

            if (is_array($existing)) {
                return [
                    'status' => 'already_applied',
                    'bible_version' => (int) $existing['bible_version'],
                    'chapter_id' => (int) $existing['chapter_id'],
                    'plan_hash' => $planHash,
                    'should_dispatch' => blank($existing['dispatched_at'] ?? null),
                ];
            }

            $bible = $lockedNovel->bibles()->where('status', BibleStatus::Current)->lockForUpdate()->firstOrFail();
            $chapter = $lockedNovel->chapters()->where('sequence', $chapterSequence)->lockForUpdate()->firstOrFail();
            $preview = $this->preview($lockedNovel, $chapterSequence, $expectedBibleVersion, $expectedStateVersion, $targetPov);

            if (! hash_equals($preview['plan_hash'], $planHash)) {
                throw ValidationException::withMessages(['plan_hash' => '恢复计划已变化，请重新执行 dry-run 并审核新的 plan hash。']);
            }

            $this->assertRecoverable($lockedNovel, $bible, $chapter, $expectedBibleVersion, $expectedStateVersion, $targetPov);

            $newBible = $this->createBibleVersion->execute($lockedNovel, [
                ...$this->bibleContent($bible),
                'pov' => $targetPov,
            ]);
            $chapter->plans()->where('status', PlanStatus::Ready)->update(['status' => PlanStatus::Superseded]);
            $chapter->scenes()->update([
                'status' => SceneStatus::Planned,
                'current_artifact_id' => null,
            ]);
            $chapter->update([
                'status' => ChapterStatus::Planned,
                'canonical_artifact_id' => null,
                'canonical_metadata' => null,
            ]);

            $settings = $lockedNovel->settings ?? [];
            data_set($settings, "recovery.bible_chapter.{$planHash}", [
                'chapter_id' => $chapter->getKey(),
                'chapter_sequence' => $chapter->sequence,
                'source_bible_version' => $expectedBibleVersion,
                'bible_version' => $newBible->version,
                'expected_state_version' => $expectedStateVersion,
                'target_pov' => $targetPov,
                'actor_id' => $actorId,
                'executed_at' => now()->toISOString(),
                'dispatched_at' => null,
            ]);
            $lockedNovel->update(['settings' => $settings]);

            return [
                'status' => 'applied',
                'bible_version' => $newBible->version,
                'chapter_id' => $chapter->getKey(),
                'plan_hash' => $planHash,
                'should_dispatch' => true,
            ];
        }, 3);

        $dispatched = false;
        if ($result['should_dispatch']) {
            $dispatched = $this->dispatcher->dispatch(new PlanChapterJob($result['chapter_id'], true));
            $this->recordDispatch($novel->getKey(), $planHash);
        }

        unset($result['should_dispatch']);

        return [...$result, 'dispatched' => $dispatched];
    }

    private function assertRecoverable(Novel $novel, NovelBible $bible, Chapter $chapter, int $expectedBibleVersion, int $expectedStateVersion, string $targetPov): void
    {
        if ($bible->version !== $expectedBibleVersion) {
            throw ValidationException::withMessages(['bible' => 'Current Bible Version 与恢复计划不一致。']);
        }
        if ($novel->canonicalStateVersion?->version !== $expectedStateVersion) {
            throw ValidationException::withMessages(['state' => 'Expected State Version 与当前 Canonical Story State 不一致。']);
        }
        if ($chapter->status === ChapterStatus::Canonical) {
            throw ValidationException::withMessages(['chapter' => '正式章节不能通过此恢复流程重置。']);
        }
        if ($chapter->sequence !== $novel->current_chapter_sequence + 1) {
            throw ValidationException::withMessages(['chapter' => '只能恢复当前 Canonical 章节之后的下一章。']);
        }
        if ($chapter->generationRuns()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
            throw ValidationException::withMessages(['chapter' => '章节仍有排队中或运行中的任务，不能执行恢复。']);
        }
        if (blank($targetPov) || $targetPov === $bible->pov) {
            throw ValidationException::withMessages(['pov' => '目标 POV 必须非空且与当前 Bible 不同。']);
        }
    }

    /** @param array<string, mixed> $frozen */
    private function hash(array $frozen): string
    {
        return hash('sha256', json_encode($frozen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private function bibleContent(NovelBible $bible): array
    {
        return collect(self::BIBLE_CONTENT_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => $bible->{$field}])
            ->all();
    }

    private function recordDispatch(int $novelId, string $planHash): void
    {
        DB::transaction(function () use ($novelId, $planHash): void {
            $novel = Novel::query()->lockForUpdate()->findOrFail($novelId);
            $settings = $novel->settings ?? [];
            data_set($settings, "recovery.bible_chapter.{$planHash}.dispatched_at", now()->toISOString());
            $novel->update(['settings' => $settings]);
        }, 3);
    }
}
