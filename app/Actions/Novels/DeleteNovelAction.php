<?php

namespace App\Actions\Novels;

use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Models\Novel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeleteNovelAction
{
    /** @return array<string, mixed> */
    public function impact(Novel $novel): array
    {
        $target = Novel::query()->find($novel->getKey());

        if ($target === null) {
            return $this->emptyImpact();
        }

        return $this->publicImpact($this->context($target));
    }

    /** @return array<string, mixed> */
    public function execute(Novel $novel, string $expectedTitle, string $reason, ?int $actorId = null): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => '必须填写删除原因。']);
        }

        if (mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => '删除原因不能超过 2000 个字符。']);
        }

        $novelId = (int) $novel->getKey();
        $knownTitle = (string) $novel->title;

        $result = DB::transaction(function () use ($novelId, $expectedTitle, $reason, $actorId): array {
            $target = Novel::query()->lockForUpdate()->find($novelId);

            if ($target === null) {
                return ['status' => 'already_deleted', 'novel_id' => $novelId];
            }

            if (! hash_equals((string) $target->title, $expectedTitle)) {
                throw ValidationException::withMessages([
                    'expected_title' => '确认标题与小说完整标题不一致。',
                ]);
            }

            if ($target->status !== NovelStatus::Paused) {
                throw ValidationException::withMessages([
                    'novel' => '删除整部小说前，必须先暂停小说。',
                ]);
            }

            $activeRun = $target->generationRuns()
                ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
                ->lockForUpdate()
                ->first(['id']) !== null;

            if ($activeRun) {
                throw ValidationException::withMessages([
                    'runs' => '小说仍有排队中或运行中的 Generation Run，不能删除小说。',
                ]);
            }

            $context = $this->context($target, lock: true);
            $impact = $this->publicImpact($context);

            $target->update([
                'canonical_state_version_id' => null,
                'current_outline_id' => null,
                'current_chapter_sequence' => null,
            ]);

            DB::table('usage_records')->whereIn('id', $context['usage_ids'])->delete();
            DB::table('reviews')->whereIn('id', $context['review_ids'])->delete();
            DB::table('memories')->whereIn('id', $context['memory_ids'])->delete();
            DB::table('facts')->whereIn('id', $context['fact_ids'])->delete();
            DB::table('story_events')->whereIn('id', $context['event_ids'])->delete();
            DB::table('story_state_versions')->whereIn('id', $context['state_version_ids'])->delete();
            DB::table('foreshadowings')->whereIn('id', $context['foreshadowing_ids'])->delete();
            DB::table('chapter_plans')->whereIn('id', $context['plan_ids'])->delete();
            DB::table('characters')->whereIn('id', $context['character_ids'])->delete();
            DB::table('world_entities')->whereIn('id', $context['world_entity_ids'])->delete();

            DB::table('generation_runs')
                ->whereIn('id', $context['run_ids'])
                ->update(['chapter_id' => null, 'scene_id' => null]);
            DB::table('scenes')->whereIn('id', $context['scene_ids'])->delete();
            DB::table('chapters')->whereIn('id', $context['chapter_ids'])->delete();
            DB::table('story_arcs')->whereIn('id', $context['story_arc_ids'])->delete();
            DB::table('volumes')->whereIn('id', $context['volume_ids'])->delete();

            DB::table('novel_outline_beats')
                ->whereIn('id', $context['outline_beat_ids'])
                ->update(['handoff_next_beat_id' => null]);
            DB::table('novel_outlines')->whereIn('id', $context['outline_ids'])->delete();

            DB::table('generation_artifacts')->whereIn('id', $context['artifact_ids'])->delete();
            DB::table('generation_runs')->whereIn('id', $context['run_ids'])->delete();
            DB::table('novel_bibles')->whereIn('id', $context['bible_ids'])->delete();
            DB::table('novels')->where('id', $novelId)->delete();

            $this->assertDeleted($context);

            return [
                ...$impact,
                'status' => 'deleted',
                'novel_id' => $novelId,
                'title' => $target->title,
                'actor_id' => $actorId,
                'reason' => $reason,
            ];
        }, 3);

        Log::warning('Novel physically deleted.', [
            'novel_id' => $novelId,
            'title' => $result['title'] ?? $knownTitle,
            'actor_id' => $actorId,
            'reason' => $reason,
            'counts' => collect($result)->only(array_keys($this->countLabels()))->all(),
            'status' => $result['status'],
        ]);

        return $result;
    }

    /** @return array<string, mixed> */
    private function context(Novel $novel, bool $lock = false): array
    {
        $novelId = $novel->getKey();
        $ids = function (string $table, string $column, Collection|array $values, string $id = 'id') use ($lock): Collection {
            $query = DB::table($table)->whereIn($column, $values);
            if ($lock) {
                $query->lockForUpdate();
            }

            return $query->pluck($id);
        };
        $direct = function (string $table) use ($novelId, $lock): Collection {
            $query = DB::table($table)->where('novel_id', $novelId);
            if ($lock) {
                $query->lockForUpdate();
            }

            return $query->pluck('id');
        };

        $chapterIds = $direct('chapters');
        $sceneIds = $ids('scenes', 'chapter_id', $chapterIds);
        $runIds = $direct('generation_runs');
        $artifactIds = $ids('generation_artifacts', 'generation_run_id', $runIds);
        $outlineIds = $direct('novel_outlines');

        $usageQuery = DB::table('usage_records')->where(function ($query) use ($novelId, $chapterIds, $runIds): void {
            $query->where('novel_id', $novelId)
                ->orWhereIn('chapter_id', $chapterIds)
                ->orWhereIn('generation_run_id', $runIds);
        });
        if ($lock) {
            $usageQuery->lockForUpdate();
        }

        $reviewQuery = DB::table('reviews')->where(function ($query) use ($runIds, $artifactIds): void {
            $query->whereIn('generation_run_id', $runIds)
                ->orWhereIn('artifact_id', $artifactIds);
        });
        if ($lock) {
            $reviewQuery->lockForUpdate();
        }

        return [
            'novel_id' => $novelId,
            'bible_ids' => $direct('novel_bibles'),
            'outline_ids' => $outlineIds,
            'outline_volume_ids' => $ids('novel_outline_volumes', 'novel_outline_id', $outlineIds),
            'outline_arc_ids' => $ids('novel_outline_arcs', 'novel_outline_id', $outlineIds),
            'outline_beat_ids' => $ids('novel_outline_beats', 'novel_outline_id', $outlineIds),
            'outline_milestone_ids' => $ids('novel_outline_milestones', 'novel_outline_id', $outlineIds),
            'volume_ids' => $direct('volumes'),
            'story_arc_ids' => $direct('story_arcs'),
            'chapter_ids' => $chapterIds,
            'plan_ids' => $ids('chapter_plans', 'chapter_id', $chapterIds),
            'scene_ids' => $sceneIds,
            'character_ids' => $direct('characters'),
            'world_entity_ids' => $direct('world_entities'),
            'foreshadowing_ids' => $direct('foreshadowings'),
            'state_version_ids' => $direct('story_state_versions'),
            'fact_ids' => $direct('facts'),
            'event_ids' => $direct('story_events'),
            'memory_ids' => $direct('memories'),
            'run_ids' => $runIds,
            'artifact_ids' => $artifactIds,
            'review_ids' => $reviewQuery->pluck('id'),
            'usage_ids' => $usageQuery->pluck('id'),
        ];
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function publicImpact(array $context): array
    {
        $impact = ['status' => 'ready'];

        foreach ($this->countLabels() as $key => $contextKey) {
            $impact[$key] = $context[$contextKey]->count();
        }

        return $impact;
    }

    /** @return array<string, string> */
    private function countLabels(): array
    {
        return [
            'bibles' => 'bible_ids',
            'outlines' => 'outline_ids',
            'outline_volumes' => 'outline_volume_ids',
            'outline_arcs' => 'outline_arc_ids',
            'outline_beats' => 'outline_beat_ids',
            'outline_milestones' => 'outline_milestone_ids',
            'volumes' => 'volume_ids',
            'story_arcs' => 'story_arc_ids',
            'chapters' => 'chapter_ids',
            'plans' => 'plan_ids',
            'scenes' => 'scene_ids',
            'characters' => 'character_ids',
            'world_entities' => 'world_entity_ids',
            'foreshadowings' => 'foreshadowing_ids',
            'state_versions' => 'state_version_ids',
            'facts' => 'fact_ids',
            'events' => 'event_ids',
            'memories' => 'memory_ids',
            'runs' => 'run_ids',
            'artifacts' => 'artifact_ids',
            'reviews' => 'review_ids',
            'usage' => 'usage_ids',
        ];
    }

    /** @return array<string, mixed> */
    private function emptyImpact(): array
    {
        return [
            'status' => 'already_deleted',
            ...array_fill_keys(array_keys($this->countLabels()), 0),
        ];
    }

    /** @param array<string, mixed> $context */
    protected function assertDeleted(array $context): void
    {
        $checks = [
            'novels' => collect([$context['novel_id']]),
            'novel_bibles' => $context['bible_ids'],
            'novel_outlines' => $context['outline_ids'],
            'novel_outline_volumes' => $context['outline_volume_ids'],
            'novel_outline_arcs' => $context['outline_arc_ids'],
            'novel_outline_beats' => $context['outline_beat_ids'],
            'novel_outline_milestones' => $context['outline_milestone_ids'],
            'volumes' => $context['volume_ids'],
            'story_arcs' => $context['story_arc_ids'],
            'chapters' => $context['chapter_ids'],
            'chapter_plans' => $context['plan_ids'],
            'scenes' => $context['scene_ids'],
            'characters' => $context['character_ids'],
            'world_entities' => $context['world_entity_ids'],
            'foreshadowings' => $context['foreshadowing_ids'],
            'story_state_versions' => $context['state_version_ids'],
            'facts' => $context['fact_ids'],
            'story_events' => $context['event_ids'],
            'memories' => $context['memory_ids'],
            'generation_runs' => $context['run_ids'],
            'generation_artifacts' => $context['artifact_ids'],
            'reviews' => $context['review_ids'],
            'usage_records' => $context['usage_ids'],
        ];

        foreach ($checks as $table => $ids) {
            if ($ids->isNotEmpty() && DB::table($table)->whereIn('id', $ids)->exists()) {
                throw ValidationException::withMessages([
                    'deletion' => "{$table} 仍有目标小说数据残留，事务已回滚。",
                ]);
            }
        }

        foreach (['novel_bibles', 'novel_outlines', 'volumes', 'story_arcs', 'chapters', 'characters', 'world_entities', 'foreshadowings', 'story_state_versions', 'facts', 'story_events', 'memories', 'generation_runs'] as $table) {
            if (DB::table($table)->where('novel_id', $context['novel_id'])->exists()) {
                throw ValidationException::withMessages([
                    'deletion' => "{$table}.novel_id 仍有目标小说数据残留，事务已回滚。",
                ]);
            }
        }
    }
}
