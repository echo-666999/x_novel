<?php

namespace App\Actions\Chapters;

use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\FactStatus;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\StoryEventStatus;
use App\Models\Chapter;
use App\Models\Fact;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use App\Services\ProjectionRebuilder;
use App\Services\StoryArcProgressProjector;
use App\Services\StoryStateRebuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeleteChapterRangeAction
{
    public function __construct(
        private readonly StoryStateRebuilder $storyStateRebuilder,
        private readonly ProjectionRebuilder $projectionRebuilder,
        private readonly StoryArcProgressProjector $storyArcProgressProjector,
    ) {}

    /** @return array<string, mixed> */
    public function impact(Chapter $chapter): array
    {
        $target = Chapter::query()->find($chapter->getKey());

        if ($target === null) {
            return [
                'status' => 'already_deleted',
                'range' => "第 {$chapter->sequence} 章起",
                'state' => '无变化',
                'chapters' => 0,
                'scenes' => 0,
                'plans' => 0,
                'runs' => 0,
                'artifacts' => 0,
                'reviews' => 0,
                'usage' => 0,
                'events' => 0,
                'facts' => 0,
                'memories' => 0,
                'characters' => 0,
                'world_entities' => 0,
                'foreshadowing_projections' => 0,
            ];
        }

        $novel = Novel::query()->findOrFail($target->novel_id);
        $context = $this->context($novel, $target->sequence);

        return $this->publicImpact($context);
    }

    /** @return array<string, mixed> */
    public function execute(Chapter $chapter, string $reason, ?int $actorId = null): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => '必须填写删除原因。']);
        }

        if (mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => '删除原因不能超过 2000 个字符。']);
        }

        $novelId = (int) $chapter->novel_id;
        $chapterId = (int) $chapter->getKey();
        $sequence = (int) $chapter->sequence;

        $result = DB::transaction(function () use ($novelId, $chapterId, $sequence, $reason, $actorId): array {
            $novel = Novel::query()->lockForUpdate()->findOrFail($novelId);

            if ($novel->status !== NovelStatus::Paused) {
                throw ValidationException::withMessages([
                    'novel' => '从指定章节起删除前，必须先暂停小说。',
                ]);
            }

            $target = Chapter::query()
                ->where('novel_id', $novel->getKey())
                ->whereKey($chapterId)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                if (! $novel->chapters()->where('sequence', '>=', $sequence)->exists()) {
                    return ['status' => 'already_deleted', 'chapter_id' => $chapterId, 'from_sequence' => $sequence];
                }

                throw ValidationException::withMessages([
                    'chapter' => '原章节已不存在，但相同范围出现了新的章节；为避免误删，本次操作已拒绝。',
                ]);
            }

            if ((int) $target->sequence !== $sequence) {
                throw ValidationException::withMessages(['chapter' => '章节序号已变化，请刷新页面后重新预览。']);
            }

            $activeRun = $novel->generationRuns()
                ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
                ->lockForUpdate()
                ->first(['id']) !== null;

            if ($activeRun) {
                throw ValidationException::withMessages([
                    'runs' => '小说仍有排队中或运行中的 Generation Run，不能删除章节。',
                ]);
            }

            $context = $this->context($novel, $sequence, lock: true);
            $impact = $this->publicImpact($context);

            $this->assertNoEarlierCanonicalReferences($novel, $context);
            $this->restoreNovelPointer($novel, $sequence, $context['previous_state_id']);
            $this->restoreSupersededFacts($novel, $context);

            DB::table('usage_records')->whereIn('id', $context['usage_ids'])->delete();
            DB::table('reviews')->whereIn('id', $context['review_ids'])->delete();
            DB::table('generation_artifacts')->whereIn('id', $context['artifact_ids'])->delete();
            DB::table('generation_runs')->whereIn('id', $context['run_ids'])->delete();
            DB::table('memories')->whereIn('id', $context['memory_ids'])->delete();
            DB::table('facts')->whereIn('id', $context['fact_ids'])->delete();
            DB::table('story_events')->whereIn('id', $context['event_ids'])->delete();
            DB::table('story_state_versions')->whereIn('id', $context['state_version_ids'])->delete();
            DB::table('characters')->whereIn('id', $context['character_ids'])->delete();
            DB::table('world_entities')->whereIn('id', $context['world_entity_ids'])->delete();
            DB::table('chapters')->whereIn('id', $context['chapter_ids'])->delete();

            $novel->refresh()->unsetRelations();
            $this->storyArcProgressProjector->refreshNovel($novel);

            if ($novel->canonical_state_version_id !== null) {
                $projection = $this->projectionRebuilder->rebuild($novel);
                if (! $projection->isHealthy()) {
                    throw ValidationException::withMessages(['projection' => '删除后的运行时投影重建未通过一致性检查。']);
                }

                $rebuild = $this->storyStateRebuilder->rebuild($novel->fresh());
                if (! $rebuild->matches()) {
                    throw ValidationException::withMessages(['state' => '删除后 Canonical Story State 重建存在差异，事务已回滚。']);
                }
            }

            $this->assertDeleted($context);
            $this->recordAudit($novel->fresh(), $reason, $actorId, $impact);

            return [
                ...$impact,
                'status' => 'deleted',
                'chapter_id' => $chapterId,
                'from_sequence' => $sequence,
            ];
        }, 3);

        Log::warning('Chapter tail physically deleted.', [
            'novel_id' => $novelId,
            'chapter_id' => $chapterId,
            'from_sequence' => $sequence,
            'actor_id' => $actorId,
            'reason' => $reason,
            'result' => $result,
        ]);

        return $result;
    }

    /** @return array<string, mixed> */
    private function context(Novel $novel, int $sequence, bool $lock = false): array
    {
        $chaptersQuery = $novel->chapters()->where('sequence', '>=', $sequence)->orderBy('sequence');
        if ($lock) {
            $chaptersQuery->lockForUpdate();
        }
        $chapters = $chaptersQuery->get();
        $chapterIds = collect($chapters->modelKeys());
        $sceneIds = DB::table('scenes')->whereIn('chapter_id', $chapterIds)->pluck('id');
        $eventIds = DB::table('story_events')->whereIn('chapter_id', $chapterIds)->pluck('id');

        $firstDeletedVersion = DB::table('story_state_versions')
            ->where('novel_id', $novel->getKey())
            ->whereIn('chapter_id', $chapterIds)
            ->min('version');
        $stateVersions = $firstDeletedVersion === null
            ? collect()
            : DB::table('story_state_versions')
                ->where('novel_id', $novel->getKey())
                ->where('version', '>=', $firstDeletedVersion)
                ->orderBy('version')
                ->get(['id', 'version']);
        $stateVersionIds = $stateVersions->pluck('id');
        $previousState = $firstDeletedVersion === null
            ? null
            : DB::table('story_state_versions')
                ->where('novel_id', $novel->getKey())
                ->where('version', '<', $firstDeletedVersion)
                ->orderByDesc('version')
                ->first(['id', 'version']);

        if ($firstDeletedVersion !== null && $previousState === null) {
            throw ValidationException::withMessages(['state' => '删除范围之前没有可恢复的 Canonical Story State。']);
        }

        $metadataCharacterIds = $chapters
            ->flatMap(fn (Chapter $chapter): array => data_get($chapter->canonical_metadata, 'character_introductions', []))
            ->pluck('character_id')
            ->map(fn ($id): int => (int) $id)
            ->filter();
        $metadataWorldEntityIds = $chapters
            ->flatMap(fn (Chapter $chapter): array => data_get($chapter->canonical_metadata, 'world_entity_introductions', []))
            ->pluck('world_entity_id')
            ->map(fn ($id): int => (int) $id)
            ->filter();
        $characterIds = DB::table('characters')
            ->where('novel_id', $novel->getKey())
            ->where(fn ($query) => $query->whereIn('source_chapter_id', $chapterIds)->orWhereIn('id', $metadataCharacterIds))
            ->pluck('id')
            ->unique()
            ->values();
        $worldEntityIds = DB::table('world_entities')
            ->where('novel_id', $novel->getKey())
            ->where(fn ($query) => $query->whereIn('source_chapter_id', $chapterIds)->orWhereIn('id', $metadataWorldEntityIds))
            ->pluck('id')
            ->unique()
            ->values();

        $memoryIds = DB::table('memories')
            ->where('novel_id', $novel->getKey())
            ->where(function ($query) use ($sequence, $chapterIds, $eventIds, $stateVersionIds): void {
                $query->where('valid_from_chapter', '>=', $sequence)
                    ->orWhere(fn ($source) => $source->where('source_type', 'chapter')->whereIn('source_id', $chapterIds))
                    ->orWhere(fn ($source) => $source->where('source_type', 'story_event')->whereIn('source_id', $eventIds))
                    ->orWhere(fn ($source) => $source->where('source_type', 'story_state_version')->whereIn('source_id', $stateVersionIds));
            })
            ->pluck('id');

        $runIds = DB::table('generation_runs')
            ->where('novel_id', $novel->getKey())
            ->where(function ($query) use ($chapterIds, $sceneIds, $memoryIds): void {
                $query->whereIn('chapter_id', $chapterIds)
                    ->orWhereIn('scene_id', $sceneIds)
                    ->orWhere(fn ($scope) => $scope->whereIn('scope_type', ['chapter', 'chapter_summary'])->whereIn('scope_id', $chapterIds))
                    ->orWhere(fn ($scope) => $scope->where('scope_type', 'scene')->whereIn('scope_id', $sceneIds))
                    ->orWhere(fn ($scope) => $scope->where('scope_type', 'memory')->whereIn('scope_id', $memoryIds));
            })
            ->pluck('id');
        $artifactIds = DB::table('generation_artifacts')->whereIn('generation_run_id', $runIds)->pluck('id');
        $reviewIds = DB::table('reviews')
            ->whereIn('generation_run_id', $runIds)
            ->orWhereIn('artifact_id', $artifactIds)
            ->pluck('id');
        $usageIds = DB::table('usage_records')
            ->whereIn('chapter_id', $chapterIds)
            ->orWhereIn('generation_run_id', $runIds)
            ->pluck('id');

        $factIds = DB::table('facts')
            ->where('novel_id', $novel->getKey())
            ->where(function ($query) use ($eventIds, $characterIds, $worldEntityIds): void {
                $query->whereIn('source_event_id', $eventIds)
                    ->orWhere(fn ($subject) => $subject->where('subject_type', 'character')->whereIn('subject_id', $characterIds))
                    ->orWhere(fn ($subject) => $subject->where('subject_type', 'world_entity')->whereIn('subject_id', $worldEntityIds));
            })
            ->pluck('id');

        $eventForeshadowingIds = DB::table('story_events')
            ->whereIn('id', $eventIds)
            ->where('subject_type', 'foreshadowing')
            ->pluck('subject_id')
            ->map(fn ($id): int => (int) $id)
            ->filter();
        $foreshadowingProjectionIds = DB::table('foreshadowings')
            ->where('novel_id', $novel->getKey())
            ->where(fn ($query) => $query
                ->whereIn('setup_chapter_id', $chapterIds)
                ->orWhereIn('payoff_chapter_id', $chapterIds)
                ->orWhereIn('id', $eventForeshadowingIds))
            ->pluck('id')
            ->unique()
            ->values();

        return [
            'novel_id' => $novel->getKey(),
            'from_sequence' => $sequence,
            'to_sequence' => $chapters->max('sequence'),
            'chapter_ids' => $chapterIds,
            'canonical_chapters' => $chapters->where('status', ChapterStatus::Canonical)->count(),
            'scene_ids' => $sceneIds,
            'plan_ids' => DB::table('chapter_plans')->whereIn('chapter_id', $chapterIds)->pluck('id'),
            'run_ids' => $runIds,
            'artifact_ids' => $artifactIds,
            'review_ids' => $reviewIds,
            'usage_ids' => $usageIds,
            'event_ids' => $eventIds,
            'fact_ids' => $factIds,
            'memory_ids' => $memoryIds,
            'character_ids' => $characterIds,
            'world_entity_ids' => $worldEntityIds,
            'foreshadowing_projection_ids' => $foreshadowingProjectionIds,
            'state_version_ids' => $stateVersionIds,
            'from_state_version' => $stateVersions->max('version'),
            'previous_state_id' => $previousState?->id,
            'to_state_version' => $previousState?->version,
        ];
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function publicImpact(array $context): array
    {
        $to = $context['to_sequence'] ?? $context['from_sequence'];
        $state = $context['from_state_version'] === null
            ? 'Canonical State 不变'
            : "v{$context['from_state_version']} → v{$context['to_state_version']}";

        return [
            'status' => 'ready',
            'range' => "第 {$context['from_sequence']}–{$to} 章（含全部后续章节）",
            'state' => $state,
            'chapters' => $context['chapter_ids']->count(),
            'canonical_chapters' => $context['canonical_chapters'],
            'scenes' => $context['scene_ids']->count(),
            'plans' => $context['plan_ids']->count(),
            'runs' => $context['run_ids']->count(),
            'artifacts' => $context['artifact_ids']->count(),
            'reviews' => $context['review_ids']->count(),
            'usage' => $context['usage_ids']->count(),
            'events' => $context['event_ids']->count(),
            'facts' => $context['fact_ids']->count(),
            'memories' => $context['memory_ids']->count(),
            'characters' => $context['character_ids']->count(),
            'world_entities' => $context['world_entity_ids']->count(),
            'foreshadowing_projections' => $context['foreshadowing_projection_ids']->count(),
        ];
    }

    /** @param array<string, mixed> $context */
    private function assertNoEarlierCanonicalReferences(Novel $novel, array $context): void
    {
        $remainingEvents = $novel->storyEvents()
            ->where('status', StoryEventStatus::Active->value)
            ->whereNotIn('chapter_id', $context['chapter_ids']);

        $characterReference = (clone $remainingEvents)
            ->where('subject_type', 'character')
            ->whereIn('subject_id', $context['character_ids']->map(fn (int $id): string => (string) $id))
            ->exists();
        $worldReference = (clone $remainingEvents)
            ->whereIn('subject_type', ['world_entity', 'location', 'item', 'faction'])
            ->whereIn('subject_id', $context['world_entity_ids']->map(fn (int $id): string => (string) $id))
            ->exists();

        if ($characterReference || $worldReference) {
            throw ValidationException::withMessages([
                'canonical_references' => '删除范围内首次引入的人物或世界实体被更早的 Active Event 引用，无法安全截断。',
            ]);
        }

        $remainingPlanReference = DB::table('chapter_plans')
            ->whereNotIn('chapter_id', $context['chapter_ids'])
            ->whereIn('pov_character_id', $context['character_ids'])
            ->exists();
        $remainingSceneReference = DB::table('scenes')
            ->whereNotIn('chapter_id', $context['chapter_ids'])
            ->whereIn('pov_character_id', $context['character_ids'])
            ->exists();
        $remainingEventSceneReference = DB::table('story_events')
            ->whereNotIn('chapter_id', $context['chapter_ids'])
            ->whereIn('scene_id', $context['scene_ids'])
            ->exists();
        $remainingCanonicalArtifactReference = DB::table('chapters')
            ->whereNotIn('id', $context['chapter_ids'])
            ->whereIn('canonical_artifact_id', $context['artifact_ids'])
            ->exists();
        $remainingSceneArtifactReference = DB::table('scenes')
            ->whereNotIn('chapter_id', $context['chapter_ids'])
            ->whereIn('current_artifact_id', $context['artifact_ids'])
            ->exists();

        if ($remainingPlanReference
            || $remainingSceneReference
            || $remainingEventSceneReference
            || $remainingCanonicalArtifactReference
            || $remainingSceneArtifactReference) {
            throw ValidationException::withMessages([
                'runtime_references' => '删除范围的 Scene、Artifact 或首次引入人物仍被保留范围引用，无法安全截断。',
            ]);
        }
    }

    private function restoreNovelPointer(Novel $novel, int $sequence, ?int $previousStateId): void
    {
        $previousSequence = $novel->chapters()
            ->where('status', ChapterStatus::Canonical->value)
            ->where('sequence', '<', $sequence)
            ->max('sequence');
        $values = ['current_chapter_sequence' => $previousSequence];
        if ($previousStateId !== null) {
            $values['canonical_state_version_id'] = $previousStateId;
        }
        $novel->update($values);
    }

    /** @param array<string, mixed> $context */
    private function restoreSupersededFacts(Novel $novel, array $context): void
    {
        $ids = GenerationArtifact::query()
            ->whereIn('generation_run_id', $context['run_ids'])
            ->where('type', ArtifactType::StatePatch)
            ->get()
            ->flatMap(fn (GenerationArtifact $artifact): array => data_get($artifact->data, 'fact_changes', []))
            ->filter(fn (mixed $change): bool => is_array($change) && ($change['action'] ?? null) === 'supersede')
            ->pluck('fact_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->diff($context['fact_ids'])
            ->unique()
            ->values();

        Fact::query()
            ->where('novel_id', $novel->getKey())
            ->whereIn('id', $ids)
            ->where('status', FactStatus::Superseded->value)
            ->update(['status' => FactStatus::Active->value]);
    }

    /** @param array<string, mixed> $context */
    private function assertDeleted(array $context): void
    {
        $checks = [
            'chapters' => ['id', $context['chapter_ids']],
            'scenes' => ['id', $context['scene_ids']],
            'chapter_plans' => ['id', $context['plan_ids']],
            'generation_runs' => ['id', $context['run_ids']],
            'generation_artifacts' => ['id', $context['artifact_ids']],
            'reviews' => ['id', $context['review_ids']],
            'usage_records' => ['id', $context['usage_ids']],
            'story_events' => ['id', $context['event_ids']],
            'story_state_versions' => ['id', $context['state_version_ids']],
            'facts' => ['id', $context['fact_ids']],
            'memories' => ['id', $context['memory_ids']],
            'characters' => ['id', $context['character_ids']],
            'world_entities' => ['id', $context['world_entity_ids']],
        ];

        foreach ($checks as $table => [$column, $ids]) {
            if ($ids->isNotEmpty() && DB::table($table)->whereIn($column, $ids)->exists()) {
                throw ValidationException::withMessages(['deletion' => "{$table} 仍引用已删除范围，事务已回滚。"]);
            }
        }

        if (DB::table('foreshadowings')
            ->where('novel_id', $context['novel_id'])
            ->where(fn ($query) => $query->whereIn('setup_chapter_id', $context['chapter_ids'])->orWhereIn('payoff_chapter_id', $context['chapter_ids']))
            ->exists()) {
            throw ValidationException::withMessages(['deletion' => 'Foreshadowing 投影仍引用已删除章节，事务已回滚。']);
        }
    }

    /** @param array<string, mixed> $impact */
    private function recordAudit(Novel $novel, string $reason, ?int $actorId, array $impact): void
    {
        $settings = $novel->settings ?? [];
        $history = is_array($settings['chapter_range_deletions'] ?? null)
            ? $settings['chapter_range_deletions']
            : [];
        $history[] = [
            'range' => $impact['range'],
            'reason' => $reason,
            'actor_id' => $actorId,
            'impact' => $impact,
            'deleted_at' => now()->toIso8601String(),
        ];
        $settings['chapter_range_deletions'] = $history;
        $novel->update(['settings' => $settings]);
    }
}
