<?php

namespace App\Services;

use App\Actions\Novels\ApplyNovelBlueprintAction;
use App\Actions\Novels\ReviseNovelIntentAction;
use App\AI\AiSettingsResolver;
use App\AI\AiSettingsService;
use App\AI\Exceptions\AiProviderException;
use App\Data\GenerationFailure;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ArtifactValidationStatus;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\AIModelPrice;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\NovelOutline;
use App\Models\Review;
use App\Support\GenerationDiagnosticRedactor;
use Illuminate\Support\Facades\DB;

/** 整书入口只调度粗阶段；UI、命令、补偿扫描共享持久断点，不另建执行链。 */
final class NovelBookWorkflow
{
    /** 用户控制也经过小说锁和 CAS；继续开篇转为整书，但保留授权历史。 */
    public function control(int $taskId, int $version, string $action, bool $dispatch = true): NovelGenerationTask
    {
        $initial = NovelGenerationTask::findOrFail($taskId);
        $task = DB::transaction(function () use ($initial, $version, $action): NovelGenerationTask {
            Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            if ($task->control_version !== $version) {
                throw new \LogicException('控制版本已变化，请刷新后重试。');
            }
            if ($action === 'recheck') {
                if ($task->current_phase !== 'preflight' || $task->status !== 'needs_attention') {
                    throw new \LogicException('只有预检配置失败可重新检查；创作问题请使用对应处理方式。');
                }
                $intent = GenerationArtifact::findOrFail($task->intent_artifact_id);
                $task->update(['status' => 'running', 'stop_reason' => null, 'control_version' => $version + 1,
                    'pending_transition' => ['stage' => 'preflight', 'scope' => 'book', 'contract' => ['book_workflow' => 1, 'intent_artifact_id' => $intent->id, 'recheck_version' => $version + 1], 'sources' => [['artifact_id' => $intent->id, 'checksum' => $intent->checksum]]]]);

                return $task;
            }
            if ($action === 'resume' && in_array($task->stop_reason, ['future_revision_not_ready', 'awaiting_manual_commit'], true)) {
                throw new \LogicException('当前需要完成提示中的领域操作，不能用继续按钮绕过。');
            }
            if ($action === 'resume' && $task->stop_reason === 'opening_complete') {
                $policy = $task->policy_snapshot;
                $policy['history'][] = ['previous_mode' => $task->mode, 'mode' => 'full_book', 'actor_id' => auth()->id(), 'at' => now()->toISOString()];
                $task->update(['mode' => 'full_book', 'policy_snapshot' => $policy, 'policy_revision' => $task->policy_revision + 1]);
            }

            return app(GenerationRunCoordinator::class)->control($task->id, $version, $action);
        });
        if (in_array($action, ['resume', 'recheck'], true)) {
            $this->advance($taskId, $dispatch);
        }

        return $task->fresh();
    }

    /** 一次提交所有明确矛盾的选择；选项来自当前候选，不能借此执行模型建议。 */
    public function choose(int $taskId, int $version, array $choices): NovelGenerationTask
    {
        $initial = NovelGenerationTask::findOrFail($taskId);
        DB::transaction(function () use ($initial, $version, $choices): void {
            Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            if ($task->stop_reason !== 'intent_conflict' || $task->control_version !== $version) {
                throw new \LogicException('矛盾来源已变化，请刷新后选择。');
            }
            $conflicts = data_get($task->stop_detail, 'conflicts', []);
            if (! $conflicts || count($choices) !== count($conflicts)) {
                throw new \LogicException('请一次完成当前列出的全部选择。');
            }
            $answers = [];
            foreach ($conflicts as $index => $conflict) {
                $choice = $choices[$index] ?? null;
                if (! in_array($choice, $conflict['options'], true)) {
                    throw new \LogicException('选择不属于当前问题的有效选项。');
                }
                $answers[] = ['id' => $conflict['id'], 'question' => $conflict['question'], 'answer' => $choice];
            }
            $intent = GenerationArtifact::findOrFail($task->intent_artifact_id)->data;
            app(ReviseNovelIntentAction::class)->handle($task->id, $version, $intent['premise'], [...$intent['explicit'], 'mode' => $task->mode, 'budget_limits' => $intent['budget']], $answers);
        });

        return NovelGenerationTask::findOrFail($taskId);
    }

    /** 在小说串行锁内读取真实断点；只调度已接通的阶段，未就绪时明确暂停。 */
    public function advance(int $taskId, bool $dispatch = true): ?GenerationRun
    {
        try {
            $run = DB::transaction(function () use ($taskId): ?GenerationRun {
                $initial = NovelGenerationTask::findOrFail($taskId);
                $novel = Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
                $task = NovelGenerationTask::whereKey($taskId)->lockForUpdate()->firstOrFail();
                if ($novel->current_generation_task_id !== $task->id || ! in_array($task->status, ['queued', 'running'], true)) {
                    return null;
                }
                $pending = $task->pending_transition ?? [];
                $blocked = GenerationIssue::where('generation_task_id', $task->id)->where('status', '!=', 'resolved')->whereIn('severity', ['error', 'critical'])
                    ->get()->contains(function ($issue) use ($pending): bool {
                        $source = GenerationRun::find($issue->first_run_id);
                        if (($pending['stage'] ?? null) === 'preflight' && isset($pending['contract']['recheck_version']) && $source?->stage === GenerationStage::Preflight) {
                            return false;
                        }

                        return ! data_get($issue->evidence, 'policy.retryable', false);
                    });
                if ($blocked) {
                    $task->update(['status' => 'needs_attention']);

                    return null;
                }

                if (($pending['stage'] ?? null) === 'book_control') {
                    $foundationRun = GenerationRun::findOrFail($pending['foundation_run_id']);
                    app(GenerationRunCoordinator::class)->assertSources($task, $foundationRun->source_manifest, $foundationRun->execution_contract);
                    $candidate = GenerationArtifact::findOrFail($foundationRun->result_artifact_id);
                    if ($foundationRun->generation_task_id !== $task->id || $foundationRun->status !== RunStatus::Succeeded || $candidate->validation_status !== ArtifactValidationStatus::Validated) {
                        throw new \LogicException('基础设定没有当前任务的成功执行与验证依据。');
                    }
                    app(NovelFoundationContract::class)->validate($candidate->data, GenerationArtifact::findOrFail($task->intent_artifact_id)->data);
                    $progress = $task->progress_snapshot ?? [];
                    $progress['foundation_artifact_id'] = $candidate->id;
                    $progress['resolved_length'] = $candidate->data['length'];
                    $task->update(['progress_snapshot' => $progress]);
                    if ($candidate->data['conflicts'] !== []) {
                        $task->update(['stop_detail' => ['message' => '有互相矛盾的明确要求，请一次选择后继续。', 'conflicts' => $candidate->data['conflicts']]]);
                        app(GenerationIssueService::class)->record($foundationRun, new GenerationFailure('intent_conflict', '明确创作要求存在矛盾，请在工作台集中选择。', false, ['conflicts' => $candidate->data['conflicts']], '选择要求'));

                        return null;
                    }
                    $task->update(['target_words' => $candidate->data['length']['target_words']]);
                    $novel->update(['target_words' => $task->target_words]);
                    $blueprint = NovelOutline::where('novel_id', $novel->id)->where('kind', 'blueprint')->where('status', 'draft')->whereHas('sourceArtifact', fn ($q) => $q->where('data->foundation_artifact_id', $candidate->id))->latest('version')->first();
                    $window = $blueprint ? NovelOutline::where('novel_id', $novel->id)->where('kind', 'goal_window')->where('status', 'draft')->whereHas('sourceArtifact', fn ($q) => $q->where('data->blueprint_id', $blueprint->id))->latest('version')->first() : null;
                    if ($blueprint && $window) {
                        app(ApplyNovelBlueprintAction::class)->initializeBook($task->id, $candidate->id, $blueprint->id, $window->id, $task->control_version);

                        return null;
                    }
                    // R04 才提供真实蓝图/目标窗口；这里保留真实断点，绝不伪造计划或调用旧四段大纲。
                    $task->update(['status' => 'paused', 'current_phase' => 'goal_window', 'stop_reason' => 'stage_not_ready',
                        'stop_detail' => ['message' => '基础设定已保存。全书蓝图与近期目标尚未接入，等待 R04 后从此处继续。'], 'control_version' => $task->control_version + 1]);

                    return null;
                }
                if (empty($pending['stage'])) {
                    $this->pauseAtBoundary($task, 'stage_not_ready', '当前章节执行阶段尚未接入，保留现有产物等待后续批次。');

                    return null;
                }
                $stage = GenerationStage::tryFrom($pending['stage']);
                if (! in_array($stage, [GenerationStage::Preflight, GenerationStage::FoundationBlueprint], true)) {
                    throw new \LogicException('当前阶段尚未接入新版整书入口。');
                }

                return app(GenerationRunCoordinator::class)->enqueue($task->id, $stage, $pending['scope'] ?? 'book', $pending['contract'] ?? [], $pending['sources'] ?? []);
            });
        } catch (\Throwable $exception) {
            $task = NovelGenerationTask::findOrFail($taskId);
            if (data_get($task->pending_transition, 'stage') === 'book_control') {
                $this->recordAdoptionFailure($task, $exception);
            }
            throw $exception;
        }
        if ($run && $dispatch && $run->status === RunStatus::Queued) {
            app(GenerationJobDispatcher::class)->dispatchRun($run->id);
        }

        return $run;
    }

    /** 采用失败发生在成功模型响应之后，须独立记账，不能把成功响应错误地当成复验通过。 */
    private function recordAdoptionFailure(NovelGenerationTask $initial, \Throwable $exception): void
    {
        DB::transaction(function () use ($initial, $exception): void {
            Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $contract = ['intent_artifact_id' => $task->intent_artifact_id, 'transition' => $task->pending_transition];
            $hash = app(GenerationInputIdentity::class)->hash($contract);
            $key = 'adoption-failure:'.$task->id.':'.$hash;
            $intent = GenerationArtifact::findOrFail($task->intent_artifact_id);
            $message = app(GenerationDiagnosticRedactor::class)->clean($exception->getMessage());
            $run = GenerationRun::firstOrCreate(['idempotency_key' => hash('sha256', $key)], [
                'novel_id' => $task->novel_id, 'generation_task_id' => $task->id, 'scope_type' => 'novel', 'scope_id' => $task->novel_id,
                'scope_key' => 'initialization', 'stage' => GenerationStage::GoalWindow, 'pipeline_version' => 'pipeline-v1', 'logical_step_key' => $key,
                'input_hash' => $hash, 'attempt' => 1, 'status' => RunStatus::Failed, 'execution_contract' => $contract,
                'source_manifest' => [['artifact_id' => $intent->id, 'checksum' => $intent->checksum]],
                'error_code' => 'initialization_validation_failed', 'error_message' => $message, 'error_retryable' => false, 'started_at' => now(), 'finished_at' => now()]);
            app(GenerationIssueService::class)->record($run);
            $task->refresh()->update(['stop_detail' => ['message' => '初始化未采用，已整体回滚。请查看问题中的字段或来源错误，修正候选后重新核验。', 'run_id' => $run->id]]);
        });
    }

    /** 预检不请求模型；授权范围取实际可用路由，失败由统一 Issue 展示。 */
    public function preflight(GenerationRun $run, array $lease): void
    {
        $task = NovelGenerationTask::findOrFail($run->generation_task_id);
        $intent = GenerationArtifact::findOrFail($task->intent_artifact_id);
        if ($intent->data['target_conflict'] ?? false) {
            $targets = array_values(array_unique([...$intent->data['premise_targets'], $intent->data['target_words']]));
            $task->update(['stop_detail' => ['message' => '篇幅要求存在差异，请选择本书目标。', 'conflicts' => [['id' => 'target_words', 'question' => '采用哪个总篇幅目标？', 'options' => array_map(fn ($n) => '采用 '.$n.' 字', $targets)]]]]);
            throw new AiProviderException('intent_conflict', '概述篇幅与明确填写的目标不同，请编辑创作选项统一要求后继续。', false);
        }
        $models = [];
        foreach ([AiStage::OutlineFoundation, AiStage::Planner, AiStage::Writer, AiStage::Extractor, AiStage::Reviewer] as $stage) {
            $route = app(AiSettingsResolver::class)->resolve($stage, $task->novel);
            // 能力来自已验证的实际记录；不会把路由存在解释成 Worker 或供应商在线。
            app(AiSettingsService::class)->assertProviderAvailable($route->provider);
            $profile = AIModelPrice::findEnabledForRoute($route->provider, $route->model);
            if (! $profile) {
                throw new AiProviderException('model_capacity_unknown', $stage->getLabel().' 缺少已验证的模型能力。', false);
            }
            $profile->capacitySnapshot();
            $models[] = ['provider' => $route->provider, 'model' => $route->model, 'stage' => $stage->value];
        }
        $contract = app(GenerationRequestContract::class)->freeze(app(NovelFoundationContract::class)->request($task));
        $contract += ['book_workflow' => 1, 'intent_artifact_id' => $intent->id, 'state_version_id' => $task->novel->canonical_state_version_id];
        DB::transaction(function () use ($task, $run, $lease, $models, $contract, $intent): void {
            Novel::whereKey($task->novel_id)->lockForUpdate()->firstOrFail();
            $locked = NovelGenerationTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $artifact = app(GenerationRunCoordinator::class)->completeExecution($run->id, $lease['token'], $lease['control_version'], ArtifactType::OperationRecord,
                ['checks' => ['task_identity' => 'valid', 'sources' => 'valid', 'model_routes' => 'valid'], 'scope' => 'book_preflight', 'service_connectivity' => 'not_probed'],
                next: ['stage' => 'foundation_blueprint', 'scope' => 'book', 'contract' => $contract, 'sources' => [['artifact_id' => $intent->id, 'checksum' => $intent->checksum]]]);
            if ($artifact->validation_status === ArtifactValidationStatus::Validated) {
                $policy = $locked->policy_snapshot;
                $policy['models'] = $models;
                $locked->update(['policy_snapshot' => $policy]);
                // 配置修复只关闭同意图的预检问题，后续内容问题不得被一起清除。
                GenerationIssue::where('generation_task_id', $task->id)->where('status', '!=', 'resolved')->get()->each(function ($issue) use ($intent, $run, $artifact): void {
                    $origin = GenerationRun::find($issue->first_run_id);
                    if ($origin?->stage === GenerationStage::Preflight && data_get($origin->execution_contract, 'intent_artifact_id') === $intent->id) {
                        $issue->update(['status' => 'resolved', 'resolved_by_run_id' => $run->id, 'resolved_at' => now(), 'resolution_reason' => '当前意图的配置预检重新执行通过。', 'verification' => ['kind' => 'preflight_recheck', 'artifact_id' => $artifact->id, 'checksum' => $artifact->checksum]]);
                    }
                });
            }
        });
    }

    /** 章节边界只读取正式结果；等待人工提交不是失败，开篇统计排除草稿。 */
    public function chapterBoundary(int $taskId): string
    {
        $initial = NovelGenerationTask::findOrFail($taskId);

        return DB::transaction(function () use ($initial): string {
            $novel = Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            if ($novel->current_generation_task_id !== $task->id || $task->status !== 'running') {
                return 'stopped';
            }
            $canonical = $novel->chapters()->where('status', ChapterStatus::Canonical)->get();
            if ($task->mode === 'opening' && $canonical->count() >= $task->opening_chapter_count && $canonical->every(fn ($chapter) => filled($chapter->summary))) {
                $this->pauseAtBoundary($task, 'opening_complete', '开篇正式章节及摘要已完成。可继续整本，或先调整未来方向。');

                return 'opening_complete';
            }
            $chapter = $novel->chapters()->whereKey($task->current_chapter_id)->first();
            if ($task->mode === 'manual_review' && $chapter && $chapter->current_draft_artifact_id && ! $chapter->canonical_artifact_id
                && Review::whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->id))->where('decision', 'PASS')->whereHas('artifact', fn ($q) => $q->where('validation_status', 'validated')->where('data->source_artifact_id', $chapter->current_draft_artifact_id))->whereHas('generationRun', fn ($q) => $q->where('status', 'succeeded')->where('state_version', $novel->canonicalStateVersion?->version))->exists()) {
                $this->pauseAtBoundary($task, 'awaiting_manual_commit', '审校已通过，等待你的正式提交。');

                return 'awaiting_manual_commit';
            }

            return 'continue';
        });
    }

    private function pauseAtBoundary(NovelGenerationTask $task, string $reason, string $message): void
    {
        $task->update(['status' => 'paused', 'stop_reason' => $reason, 'stop_detail' => ['message' => $message], 'control_version' => $task->control_version + 1]);
    }
}
