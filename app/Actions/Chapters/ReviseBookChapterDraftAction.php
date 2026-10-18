<?php

namespace App\Actions\Chapters;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Services\GenerationInputIdentity;
use App\Services\GenerationJobDispatcher;
use App\Services\GenerationRunCoordinator;
use App\Services\NovelBookWorkflow;
use App\Services\NovelChapterPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 人工编辑保存为新稿，原文和旧评审留档；绝不把保存按钮等同于正式批准。 */
final class ReviseBookChapterDraftAction
{
    public function handle(int $chapterId, int $expectedDraftId, string $expectedHash, int $controlVersion, string $body, string $operationKey, bool $dispatch = true): GenerationArtifact
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if ($body === '' || mb_strlen($body) > 100000 || ! Str::isUuid($operationKey)) {
            throw new \LogicException('正文不能为空、不能超过十万字，操作标识必须有效。');
        }
        [$draft, $next] = DB::transaction(function () use ($chapterId, $expectedDraftId, $expectedHash, $controlVersion, $body, $operationKey): array {
            $initial = Chapter::findOrFail($chapterId);
            $novel = Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($novel->current_generation_task_id)->lockForUpdate()->firstOrFail();
            $chapter = Chapter::whereKey($chapterId)->lockForUpdate()->firstOrFail();
            $hash = app(GenerationInputIdentity::class)->hash([$chapterId, $expectedDraftId, $expectedHash, $controlVersion, $body]);
            $existing = GenerationRun::where('idempotency_key', 'book-edit:'.$operationKey)->first();
            if ($existing) {
                if ($existing->input_hash !== $hash) {
                    throw new \LogicException('相同操作标识不能保存不同内容。');
                }

                return [GenerationArtifact::findOrFail($existing->result_artifact_id), null];
            }
            if ($task->control_version !== $controlVersion || $chapter->current_draft_artifact_id !== $expectedDraftId || $chapter->canonical_artifact_id
                || in_array($task->status, ['completed', 'cancelled', 'failed'], true)) {
                throw new \LogicException('当前稿或任务状态已变化；输入已保留，请对照新版本后重试。');
            }
            $old = GenerationArtifact::findOrFail($expectedDraftId);
            if (! hash_equals($old->checksum, $expectedHash) || $body === $old->content) {
                throw new \LogicException('原稿哈希不符或正文没有变化，请保留输入并核对。');
            }
            $plan = ChapterPlan::findOrFail($chapter->current_plan_id);
            $contract = ['book_workflow' => 1, 'chapter_id' => $chapter->id, 'chapter_plan_id' => $plan->id, 'state_version_id' => $plan->source_state_version_id, 'goal_refs' => $plan->goal_refs, 'intent_artifact_id' => $task->intent_artifact_id, 'actor_id' => auth()->id()];
            $sources = [['artifact_id' => $old->id, 'checksum' => $old->checksum, 'chapter_id' => $chapter->id, 'pointer' => 'current_draft_artifact_id']];
            app(GenerationRunCoordinator::class)->assertSources($task, $sources, $contract);
            // 取消尚未发出的旧请求；在途响应仍由来源检查隔离，不覆盖人工稿。
            GenerationRun::where('generation_task_id', $task->id)->where('chapter_id', $chapterId)->where('status', 'queued')->update(['status' => 'cancelled', 'finished_at' => now()]);
            $run = GenerationRun::create(['novel_id' => $novel->id, 'generation_task_id' => $task->id, 'chapter_id' => $chapterId, 'stage' => GenerationStage::ChapterRecovery,
                'scope_type' => 'chapter', 'scope_id' => $chapterId, 'pipeline_version' => 'pipeline-v1', 'scope_key' => 'chapter:'.$chapterId,
                'logical_step_key' => 'book-edit:'.$operationKey, 'idempotency_key' => 'book-edit:'.$operationKey, 'input_hash' => $hash,
                'execution_contract' => $contract, 'source_manifest' => $sources, 'status' => RunStatus::Succeeded, 'attempt' => 1, 'started_at' => now(), 'finished_at' => now()]);
            $draft = app(NovelChapterPipeline::class)->draft($run, $body, $sources, ArtifactType::RewriteDraft);
            $run->update(['result_artifact_id' => $draft->id]);
            $chapter->update(['current_draft_artifact_id' => $draft->id, 'status' => 'review']);
            // 原问题不标为通过，关联到新稿复验；全部旧问题在新稿 PASS 时一并验证结案。
            $issues = GenerationIssue::where('generation_task_id', $task->id)->where('chapter_id', $chapterId)->where('status', '!=', 'resolved')->get();
            foreach ($issues as $issue) {
                $evidence = $issue->evidence;
                $evidence['manual_revision'] = ['run_id' => $run->id, 'draft_id' => $draft->id, 'previous_draft_id' => $old->id];
                $issue->update(['status' => 'repairing', 'evidence' => $evidence]);
            }
            $progress = $task->progress_snapshot;
            $progress['manual_verification_issue_ids'] = $issues->modelKeys();
            $task->update(['status' => 'running', 'stop_reason' => null, 'control_version' => $controlVersion + 1, 'progress_snapshot' => $progress,
                'pending_transition' => ['stage' => 'book_control', 'manual_draft_id' => $draft->id]]);

            // 保存与启动复验分离：容量或在途任务阻止复验时，人工文字仍必须持久保存。
            return [$draft, null];
        });
        try {
            $next = app(NovelBookWorkflow::class)->advance($draft->generationRun->generation_task_id, false);
        } catch (\Throwable $e) {
            // 统一工作流已记录复验异常；不把已经保存的新稿谎报为保存失败。
            report($e);
        }
        if ($dispatch && $next) {
            app(GenerationJobDispatcher::class)->dispatchRun($next->id);
        }

        return $draft;
    }
}
