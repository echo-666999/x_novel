<?php

namespace App\Services;

use App\Actions\Novels\ApplyNovelBlueprintAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\ChapterStatus;
use App\Enums\NovelOutlineStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\NovelOutline;
use Illuminate\Support\Facades\DB;

/** 三层规划的前两层：只准备可执行窗口，远期未细化不阻断当前章。 */
final class NovelPlanningService
{
    /** 原文和设定不可变，首次蓝图与窗口复用相同来源的成功产物。 */
    public function fromFoundation(NovelGenerationTask $task, GenerationArtifact $foundation): ?GenerationRun
    {
        $blueprint = NovelOutline::where('novel_id', $task->novel_id)->where('kind', 'blueprint')
            ->whereHas('sourceArtifact', fn ($q) => $q->where('data->foundation_artifact_id', $foundation->id))->latest('version')->first();
        if (! $blueprint) {
            return app(NovelStageContract::class)->enqueue($task, 'blueprint', [
                'intent' => GenerationArtifact::findOrFail($task->intent_artifact_id)->data, 'foundation' => $foundation->data, 'target_words' => $task->target_words,
            ], [$this->source($foundation)], ['foundation_artifact_id' => $foundation->id]);
        }
        $window = NovelOutline::where('novel_id', $task->novel_id)->where('kind', 'goal_window')
            ->whereHas('sourceArtifact', fn ($q) => $q->where('data->blueprint_id', $blueprint->id))->latest('version')->first();
        if (! $window) {
            return $this->window($task, $blueprint);
        }
        app(ApplyNovelBlueprintAction::class)->initializeBook($task->id, $foundation->id, $blueprint->id, $window->id, $task->control_version);
        $task->refresh()->update(['status' => 'running', 'stop_reason' => null, 'stop_detail' => null, 'pending_transition' => ['stage' => 'book_control', 'chapter_boundary' => true]]);

        return app(NovelChapterPipeline::class)->start($task->fresh());
    }

    /** 目标不足时按正式进度补齐；只发送需要生成的槽位，保留未改变目标的身份。 */
    public function window(NovelGenerationTask $task, NovelOutline $blueprint): GenerationRun
    {
        $novel = $task->novel->fresh();
        $completed = (int) $novel->chapters()->where('status', ChapterStatus::Canonical)->sum('word_count');
        $first = ((int) $novel->chapters()->where('status', ChapterStatus::Canonical)->max('sequence')) + 1;
        $target = (int) data_get($novel->settings, 'generation.chapter_target_words', 3000);
        $remaining = max(1, $task->target_words - $completed);
        $count = min((int) config('generation.book.window_chapters', 5), max(1, (int) ceil($remaining / $target)));
        $current = NovelOutline::find($novel->current_goal_window_id);
        $retained = array_values(array_filter($current?->sourceArtifact?->data['goals'] ?? [], fn ($goal) => is_array($goal) && $goal['sequence'] >= $first));
        $retained = array_slice($retained, 0, $count);

        return app(NovelStageContract::class)->enqueue($task, 'window', ['blueprint' => $blueprint->sourceArtifact->data,
            'state' => $novel->canonicalStateVersion?->state ?? [], 'completed_words' => $completed, 'remaining_words' => $remaining,
            'first_sequence' => $first + count($retained), 'count' => max(1, $count - count($retained)), 'retained_goals' => $retained,
            'chapter_target_words' => $target, 'recent_summaries' => $novel->chapters()->where('status', ChapterStatus::Canonical)->latest('sequence')->limit(5)->get(['sequence', 'summary'])->toArray(),
        ], [$this->source($blueprint->sourceArtifact)], ['blueprint_id' => $blueprint->id]);
    }

    /** 派生数字、目标 key 和内容 hash 由代码计算，不依赖模型手工汇总。 */
    public function normalize(GenerationRun $run, array $data): array
    {
        $c = $run->execution_contract;
        if ($c['book_stage'] === 'blueprint') {
            $target = $c['input']['target_words'];
            $n = count($data['turning_points']);
            $data['allocation'] = [];
            foreach ($data['turning_points'] as $i => $point) {
                $data['allocation'][] = ['key' => 'turn-'.($i + 1), 'objective' => $point, 'target_words' => intdiv($target, $n) + ($i < $target % $n ? 1 : 0)];
            }
            $data['reader_promises'] = array_map(fn ($p, $i) => ['key' => 'promise-'.($i + 1), 'description' => $p], $data['reader_promises'], array_keys($data['reader_promises']));

            return ['schema_version' => 'book-blueprint-v1', 'foundation_artifact_id' => $c['foundation_artifact_id'], ...$data];
        }
        $input = $c['input'];
        if (count($data['goals']) !== $input['count']) {
            throw new AiProviderException('book_window_count_invalid', '近期目标数量与待补槽位不一致，请只修正缺失目标。', false);
        }
        $keys = array_column($input['blueprint']['reader_promises'], 'key');
        $retained = $input['retained_goals'];
        $remaining = max(1, $input['remaining_words'] - array_sum(array_column($retained, 'target_words')));
        foreach ($data['goals'] as $i => &$goal) {
            if (array_diff($goal['promise_refs'], $keys)) {
                throw new AiProviderException('book_goal_reference_invalid', '近期目标引用了不存在的读者承诺。', false);
            }
            $goal['sequence'] = $input['first_sequence'] + $i;
            $goal['key'] = 'chapter-'.$goal['sequence'];
            $goal['target_words'] = min($input['chapter_target_words'], max(1, $remaining - $i * $input['chapter_target_words']));
            $goal['content_hash'] = app(GenerationInputIdentity::class)->hash($goal);
        }
        unset($goal);

        return ['schema_version' => 'book-window-v1', 'blueprint_id' => $c['blueprint_id'], 'based_on_state_version_id' => $c['state_version_id'], 'goals' => [...$retained, ...$data['goals']]];
    }

    /** 成功产物落版本索引和当前指针同事务；Crash 后重放不重复建版本。 */
    public function adoptResult(NovelGenerationTask $task, GenerationRun $run): ?GenerationRun
    {
        $artifact = GenerationArtifact::findOrFail($run->result_artifact_id);
        $kind = $run->execution_contract['book_stage'] === 'blueprint' ? 'blueprint' : 'goal_window';
        $outline = NovelOutline::firstOrCreate(['novel_id' => $task->novel_id, 'source_artifact_id' => $artifact->id, 'kind' => $kind], [
            'version' => ((int) NovelOutline::where('novel_id', $task->novel_id)->where('kind', $kind)->max('version')) + 1,
            'status' => 'draft', 'source' => 'ai', 'schema_version' => 1, 'title' => $kind === 'blueprint' ? '全书蓝图' : '近期目标',
            'summary' => $artifact->data['mainline'] ?? '滚动章节目标', 'checksum' => $artifact->checksum,
        ]);
        if ($kind === 'blueprint') {
            return $this->window($task, $outline);
        }
        if (! $task->novel->canonical_state_version_id) {
            $blueprint = NovelOutline::findOrFail($artifact->data['blueprint_id']);
            $foundation = GenerationArtifact::findOrFail($blueprint->sourceArtifact->data['foundation_artifact_id']);

            return $this->fromFoundation($task, $foundation);
        }
        $this->adoptWindow($task->id, $outline->id, $task->control_version);

        return app(NovelChapterPipeline::class)->start($task->fresh());
    }

    /** 只在章节边界切换窗口；正在写的章节不被未来目标修订强行失效。 */
    public function adoptWindow(int $taskId, int $outlineId, int $version): NovelOutline
    {
        return DB::transaction(function () use ($taskId, $outlineId, $version) {
            $initial = NovelGenerationTask::findOrFail($taskId);
            $novel = Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($taskId)->lockForUpdate()->firstOrFail();
            $outline = NovelOutline::whereKey($outlineId)->lockForUpdate()->firstOrFail();
            if ($novel->current_goal_window_id === $outline->id) {
                return $outline;
            }
            if ($task->control_version !== $version || $task->status !== 'running' || $outline->novel_id !== $novel->id || $outline->kind !== 'goal_window') {
                throw new \LogicException('任务控制版本或目标归属不匹配，不能采用。');
            }
            if ($novel->chapters()->whereNotIn('status', ['canonical', 'void'])->exists()) {
                throw new \LogicException('当前章节仍在写作，未来窗口保留为候选，章节边界再采用。');
            }
            $data = $outline->sourceArtifact->data;
            if ($data['blueprint_id'] !== $novel->current_blueprint_id) {
                throw new \LogicException('窗口的正式状态或蓝图已过期，请保留候选并重新规划。');
            }
            app(GenerationRunCoordinator::class)->assertSources($task, [$this->source($outline->sourceArtifact)]);
            if ($data['based_on_state_version_id'] !== $novel->canonical_state_version_id) {
                // 目标是未来意图；边界生成新来源版本，章计划仍须基于新正式状态重新校验。
                $rebased = $this->revision($task, $outline, [...$data, 'based_on_state_version_id' => $novel->canonical_state_version_id]);
                $outline->update(['status' => NovelOutlineStatus::Superseded]);
                $outline = $rebased;
            }
            NovelOutline::whereKey($novel->current_goal_window_id)->update(['status' => NovelOutlineStatus::Superseded]);
            $outline->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
            $novel->update(['current_goal_window_id' => $outline->id]);

            return $outline;
        });
    }

    /** 未来方向修订保留原设定硬要求，创建待采用蓝图，不打断正在写的章节。 */
    public function reviseBlueprint(int $taskId, int $version, int $blueprintId, string $mainline): NovelOutline
    {
        return DB::transaction(function () use ($taskId, $version, $blueprintId, $mainline) {
            $initial = NovelGenerationTask::findOrFail($taskId);
            $novel = Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($taskId)->lockForUpdate()->firstOrFail();
            $old = NovelOutline::where('novel_id', $novel->id)->findOrFail($blueprintId);
            if ($task->control_version !== $version || $novel->current_blueprint_id !== $old->id || blank($mainline) || mb_strlen($mainline) > 4000 || $old->sourceArtifact->data['mainline'] === $mainline) {
                throw new \LogicException('蓝图来源已改变或方向没有有效变化，请保留输入后核对。');
            }
            $candidate = $this->revision($task, $old, [...$old->sourceArtifact->data, 'mainline' => $mainline]);
            $progress = $task->progress_snapshot;
            $progress['pending_blueprint_id'] = $candidate->id;
            $task->update(['progress_snapshot' => $progress, 'control_version' => $version + 1]);

            return $candidate;
        });
    }

    /** 章节边界采用未来蓝图；只重建未执行目标，正式历史与旧稿从不改写。 */
    public function adoptPendingBlueprint(NovelGenerationTask $task): ?GenerationRun
    {
        $id = data_get($task->progress_snapshot, 'pending_blueprint_id');
        if (! $id) {
            return null;
        }
        $novel = $task->novel->fresh();
        if ($novel->chapters()->whereNotIn('status', ['canonical', 'void'])->exists()) {
            return null;
        }
        $blueprint = NovelOutline::where('novel_id', $novel->id)->where('kind', 'blueprint')->findOrFail($id);
        app(GenerationRunCoordinator::class)->assertSources($task, [$this->source($blueprint->sourceArtifact)]);
        NovelOutline::whereKey($novel->current_blueprint_id)->update(['status' => NovelOutlineStatus::Superseded]);
        NovelOutline::whereKey($novel->current_goal_window_id)->update(['status' => NovelOutlineStatus::Superseded]);
        $blueprint->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
        $novel->update(['current_blueprint_id' => $blueprint->id, 'current_goal_window_id' => null]);
        $progress = $task->progress_snapshot;
        unset($progress['pending_blueprint_id'],$progress['pending_window_id']);
        $task->update(['progress_snapshot' => $progress]);

        return $this->window($task->fresh(), $blueprint);
    }

    /** 人工修改目标只创建新版本；当前目标变更须明确重建，未来目标等待章节边界。 */
    public function reviseGoal(int $taskId, int $version, int $windowId, string $key, string $objective, bool $replanCurrent = false): NovelOutline
    {
        return DB::transaction(function () use ($taskId, $version, $windowId, $key, $objective, $replanCurrent) {
            $initial = NovelGenerationTask::findOrFail($taskId);
            $novel = Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($taskId)->lockForUpdate()->firstOrFail();
            $old = NovelOutline::where('novel_id', $novel->id)->findOrFail($windowId);
            if ($task->control_version !== $version || $novel->current_goal_window_id !== $old->id || trim($objective) === '' || mb_strlen($objective) > 4000) {
                throw new \LogicException('来源版本已改变或目标内容无效，请保留输入后核对。');
            }
            // 同一边界前的多次未来修订累积到候选，避免后一次编辑丢失前一次目标。
            $base = data_get($task->progress_snapshot, 'pending_window_id')
                ? NovelOutline::where('novel_id', $novel->id)->where('kind', 'goal_window')->findOrFail($task->progress_snapshot['pending_window_id'])
                : $old;
            $data = $base->sourceArtifact->data;
            $index = array_search($key, array_column($data['goals'], 'key'), true);
            if ($index === false || $data['goals'][$index]['objective'] === $objective) {
                throw new \LogicException('目标不存在或没有变化。');
            }
            $chapter = $novel->chapters()->whereNotIn('status', ['canonical', 'void'])->first();
            $affected = $chapter && $data['goals'][$index]['sequence'] === $chapter->sequence;
            if ($affected && ! $replanCurrent) {
                throw new \LogicException('这会改变当前章来源，请明确选择重建当前计划；旧稿保留为历史。');
            }
            $data['goals'][$index]['objective'] = $objective;
            unset($data['goals'][$index]['content_hash']);
            $data['goals'][$index]['content_hash'] = app(GenerationInputIdentity::class)->hash($data['goals'][$index]);
            $candidate = $this->revision($task, $base, $data);
            if ($affected) {
                // 旧在途响应由计划和目标身份隔离，未发送的收费请求直接取消。
                GenerationRun::where('generation_task_id', $task->id)->where('chapter_id', $chapter->id)->where('status', 'queued')->update(['status' => 'cancelled', 'finished_at' => now()]);
                GenerationIssue::where('generation_task_id', $task->id)->where('chapter_id', $chapter->id)->where('status', '!=', 'resolved')->get()->each(fn ($issue) => $issue->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_reason' => '用户修订当前目标，原来源失效；不表示原稿通过。', 'verification' => ['kind' => 'source_superseded', 'new_window_id' => $candidate->id]]));
                $progress = $task->progress_snapshot;
                unset($progress['pending_window_id']);
                $task->update(['progress_snapshot' => $progress]);
                $chapter->update(['current_plan_id' => null, 'current_draft_artifact_id' => null, 'status' => 'planned']);
                $old->update(['status' => NovelOutlineStatus::Superseded]);
                $candidate->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
                $novel->update(['current_goal_window_id' => $candidate->id]);
                $task->update(['status' => 'running', 'control_version' => $version + 1, 'stop_reason' => null, 'pending_transition' => ['stage' => 'book_control', 'chapter_boundary' => true]]);
            } else {
                $progress = $task->progress_snapshot;
                $progress['pending_window_id'] = $candidate->id;
                $task->update(['progress_snapshot' => $progress, 'control_version' => $version + 1]);
            }

            return $candidate;
        });
    }

    /** 规划修订本身不调用模型，保留不可变来源、操作者与旧版。 */
    private function revision(NovelGenerationTask $task, NovelOutline $old, array $data): NovelOutline
    {
        $hash = app(GenerationInputIdentity::class)->hash([$old->id, $data]);
        $run = GenerationRun::firstOrCreate(['idempotency_key' => 'book-window-edit:'.$task->id.':'.$hash], [
            'novel_id' => $task->novel_id, 'generation_task_id' => $task->id, 'pipeline_version' => 'pipeline-v1', 'stage' => $old->kind === 'blueprint' ? 'foundation_blueprint' : 'goal_window',
            'scope_type' => 'novel', 'scope_id' => $task->novel_id, 'scope_key' => 'window-edit', 'logical_step_key' => $hash, 'input_hash' => $hash,
            'execution_contract' => ['actor_id' => auth()->id(), 'previous_window_id' => $old->id], 'source_manifest' => [$this->source($old->sourceArtifact)],
            'status' => 'succeeded', 'attempt' => 1, 'started_at' => now(), 'finished_at' => now(),
        ]);
        $artifact = $run->artifacts()->firstOrCreate(['type' => $old->kind === 'blueprint' ? 'outline_blueprint' : 'goal_window', 'checksum' => $hash], ['data' => $data, 'source_manifest' => [$this->source($old->sourceArtifact)], 'validation_status' => 'validated']);
        $run->update(['result_artifact_id' => $artifact->id]);

        return NovelOutline::firstOrCreate(['novel_id' => $task->novel_id, 'kind' => $old->kind, 'source_artifact_id' => $artifact->id], [
            'version' => ((int) NovelOutline::where('novel_id', $task->novel_id)->where('kind', $old->kind)->max('version')) + 1,
            'status' => 'draft', 'source' => 'manual', 'schema_version' => 1, 'title' => $old->kind === 'blueprint' ? '全书方向修订' : '近期目标修订', 'summary' => '人工版本修订，原要求与历史不变', 'checksum' => $hash,
        ]);
    }

    /** 目标按内容身份比较，补充远期内容不会改变当前章的冻结引用。 */
    public function assertGoalSources(NovelGenerationTask $task, array $refs): void
    {
        $window = NovelOutline::findOrFail($task->novel->current_goal_window_id)->sourceArtifact->data;
        foreach ($refs as $ref) {
            $goal = collect($window['goals'])->firstWhere('key', $ref['key']);
            if (! $goal || ! hash_equals($goal['content_hash'], $ref['hash'])) {
                throw new \LogicException('当前章依赖的近期目标内容已变化，请重建章计划。');
            }
        }
    }

    /** 构造数据库可核验的不可变来源引用。 */
    public function source(GenerationArtifact $artifact): array
    {
        return ['artifact_id' => $artifact->id, 'checksum' => $artifact->checksum];
    }
}
