<?php

namespace App\Services;

use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\Enums\ArtifactType;
use App\Enums\ArtifactValidationStatus;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\NovelGenerationTask;
use App\Support\GenerationDiagnosticRedactor;
use Illuminate\Support\Str;

/** 修复仍使用原问题账本；结构修复不写正文，正文修复必须重新提取和审校。 */
final class BookChapterRepair
{
    public function source(GenerationIssue $issue, bool $eventsOnly = false): GenerationRun
    {
        $latest = GenerationRun::find($issue->latest_run_id);
        $completedReview = $latest?->status === RunStatus::Succeeded
            && data_get($latest->execution_contract, 'book_stage') === 'review';
        $failedVerification = ! $eventsOnly && $latest?->status === RunStatus::Failed
            && in_array(data_get($latest->execution_contract, 'book_stage'), ['extract', 'review'], true)
            && in_array($latest->error_code, ['book_schema_invalid', 'book_evidence_invalid', 'book_entity_reference_invalid', 'book_review_coverage_invalid'], true)
            && $latest->id !== $issue->first_run_id;

        return app(GenerationIssueService::class)->assertCurrent($issue,
            ! $failedVerification && ($completedReview || $eventsOnly || in_array($issue->error_code, ['book_review_rewrite', 'book_review_block'], true)),
            currentRepairFailure: $failedVerification);
    }

    public function prepare(GenerationIssue $issue, string $instructions, bool $eventsOnly = false): array
    {
        $source = $this->source($issue, $eventsOnly);
        $c = $source->execution_contract;
        $task = NovelGenerationTask::findOrFail($issue->generation_task_id);
        $phase = $c['book_stage'] ?? null;
        $requiresRevision = $source->status === RunStatus::Succeeded && $phase === 'review'
            && in_array(data_get(GenerationArtifact::find($source->result_artifact_id)?->data, 'decision'), ['BLOCK', 'REWRITE'], true);
        $savedProviderFailure = $source->error_code === 'provider_schema_validation_failed'
            && $source->artifacts()->where('type', ArtifactType::OperationRecord)->where('validation_status', 'rejected')
                ->where('data->error_code', 'provider_schema_validation_failed')->whereNotNull('content')->where('content', '!=', '')->exists();
        if (! $phase || (! $savedProviderFailure && ! $this->isSavedEventContractFailure($source) && ! in_array($issue->error_code, ['book_schema_invalid', 'book_evidence_invalid', 'book_review_coverage_invalid', 'book_entity_reference_invalid', 'book_output_truncated', 'book_truncation_unrecoverable', 'book_window_count_invalid', 'book_goal_reference_invalid', 'book_review_block', 'book_review_rewrite'], true))) {
            throw new \LogicException('该问题不能用局部模型修复；请查看具体人工建议。');
        }
        if ($eventsOnly) {
            if (! $requiresRevision) {
                throw new \LogicException('重新提取事件仅用于当前章节审校问题。');
            }
            $phase = 'extract';
            $input = $c['input'];
            unset($input['event_candidate'], $input['state_patch'], $input['rule_findings']);
            $input['repair_findings'] = GenerationArtifact::findOrFail($source->result_artifact_id)->data['findings'];
            $input['instructions'] = $instructions;
            $plan = ChapterPlan::findOrFail($c['chapter_plan_id']);
            $input['frozen_route'] = array_intersect_key($plan->admission_snapshot['routes']['extract'], array_flip(['provider', 'model', 'prompt', 'reasoning_reserve_tokens', 'output_tokens']));
            unset($c['event_artifact_id'], $c['patch_artifact_id']);
        } elseif ($requiresRevision) {
            // 章级上限跨问题、跨稿累计，换来源不会获得新的自动改写额度。
            $count = GenerationRun::where('generation_task_id', $task->id)->where('chapter_id', $source->chapter_id)->where('execution_contract->book_stage', 'rewrite')->count();
            if ($count >= (int) config('generation.book.max_rewrites', 2)) {
                throw new \LogicException('本章局部改写次数已耗尽，请人工保存新稿并复验；历史费用和尝试不会清空。');
            }
            $result = GenerationArtifact::findOrFail($source->result_artifact_id)->data;
            $findings = [...$result['findings'], ...($result['rule_findings'] ?? [])];
            $draft = GenerationArtifact::findOrFail($c['draft_artifact_id']);
            $keys = [];
            foreach ($findings as $finding) {
                if (! in_array($finding['severity'], ['important', 'hard'], true)) {
                    continue;
                }
                foreach (($finding['evidence'] ?? []) as $evidence) {
                    if (isset($evidence['paragraph_key'])) {
                        $keys[] = $evidence['paragraph_key'];
                    } elseif (($evidence['artifact_id'] ?? null) === $draft->id && isset($evidence['start_offset'])) {
                        foreach ($draft->data['paragraph_index'] as $paragraph) {
                            if ($evidence['start_offset'] >= $paragraph['offset'] && $evidence['start_offset'] < $paragraph['offset'] + $paragraph['length']) {
                                $keys[] = $paragraph['key'];
                            }
                        }
                    }
                }
            }
            $keys = array_values(array_unique($keys));
            if ($keys === []) {
                throw new \LogicException('缺少可定位的重要缺陷证据，请人工核对章节；不能无依据重写整章。');
            }
            $phase = 'rewrite';
            $input = [...$c['input'], 'repair_findings' => $findings, 'instructions' => $instructions, 'allowed_paragraphs' => $keys];
            $plan = ChapterPlan::findOrFail($c['chapter_plan_id']);
            $input['frozen_route'] = array_intersect_key($plan->admission_snapshot['routes']['rewrite'], array_flip(['provider', 'model', 'prompt', 'reasoning_reserve_tokens', 'output_tokens']));
            $c['allowed_paragraphs'] = $keys;
        } else {
            if ($source->status !== RunStatus::Failed) {
                throw new \LogicException('只能修复已经失败并保留响应的阶段。');
            }
            $failed = $source->artifacts()->latest('id')->firstOrFail();
            $input = [...$c['input'], 'repair' => ['error' => $source->error_code,
                'validation_message' => app(GenerationDiagnosticRedactor::class)->clean((string) $source->error_message),
                'candidate' => $failed->content, 'instructions' => $instructions]];
            $input['frozen_route'] = array_intersect_key($c, array_flip(['provider', 'model', 'prompt', 'reasoning_reserve_tokens', 'output_tokens']));
        }
        if (isset($input['context'])) {
            $input['context']['state_version'] = $source->state_version;
        }
        $request = app(NovelStageContract::class)->request($task, $phase, $input, ($phase === 'rewrite' || $eventsOnly) ? null : $c['output_tokens']);
        $request = new AiRequest(model: $request->model, provider: $request->provider, systemPrompt: $request->systemPrompt, prompt: $request->prompt,
            temperature: $request->temperature, maxTokens: $request->maxTokens, reasoningEffort: $request->reasoningEffort, responseSchema: $request->responseSchema, promptVersion: 'book-'.$phase.'-repair-v3', metadata: $request->metadata);
        $contract = array_replace($c, app(GenerationRequestContract::class)->freeze($request), ['book_stage' => $phase, 'input' => $input,
            'issue_action' => 'repair_book', 'issue_id' => $issue->id, 'repair_source_identity' => $issue->source_identity]);

        return $contract;
    }

    /** 旧记录保留原错误码；只在同源候选可重现明确结构错误时开放修复，未知异常不泛化重试。 */
    private function isSavedEventContractFailure(GenerationRun $source): bool
    {
        if ($source->status !== RunStatus::Failed || $source->error_code !== 'stage_execution_failed'
            || data_get($source->execution_contract, 'book_stage') !== 'extract') {
            return false;
        }
        $candidate = $source->artifacts()->where('type', ArtifactType::EventCandidate)->latest('id')->first();
        if (! $candidate) {
            return false;
        }
        try {
            app(NovelChapterPipeline::class)->validateOutput($source, $candidate->data);
        } catch (AiProviderException $exception) {
            return $exception->errorCode === 'book_schema_invalid';
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /** 仅重建失败交接的容量合同，复用原计划和正文，不重复请求 Planner/Writer。 */
    public function rebuild(GenerationIssue $issue, NovelGenerationTask $task): GenerationRun
    {
        $source = app(GenerationIssueService::class)->assertCurrent($issue);
        if (data_get($source->execution_contract, 'transition.canonical_chapter_id') && in_array($issue->category, ['capacity', 'configuration', 'summary'], true)) {
            // 正式提交已成功，仅重建缺失摘要的请求准入，正文与提交凭证不重放。
            $chapter = Chapter::where('novel_id', $task->novel_id)->findOrFail($source->execution_contract['transition']['canonical_chapter_id']);
            $next = app(BookLifecycle::class)->summary($task, $chapter);
            if (! $next) {
                throw new \LogicException('摘要来源已发生变化，请刷新检查。');
            }
            $issue->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_reason' => '正式章节保留，摘要配置与容量准入已重新验证。摘要内容仍等待模型生成与证据校验。', 'verification' => ['admission_run_id' => $next->id]]);

            return $next;
        }
        if ($issue->category !== 'capacity') {
            throw new \LogicException('只有容量问题可重建准入；其他问题请使用对应修复。');
        }
        $completed = GenerationRun::findOrFail(data_get($source->execution_contract, 'transition.completed_run_id'));
        app(GenerationRunCoordinator::class)->assertSources($task, $completed->source_manifest, $completed->execution_contract);
        $next = app(NovelChapterPipeline::class)->rebuildAdmission($task, $completed);
        $issue->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_reason' => '保留原计划和正文，新的容量合同预检通过；内容仍须后续独立复验。', 'verification' => ['admission_run_id' => $next->id]]);

        return $next;
    }

    /** 仅有明确错误和合法局部范围才自动修一次；停止信息留在原问题中。 */
    public function attempt(GenerationRun $source): ?GenerationRun
    {
        $source->refresh();
        $issue = GenerationIssue::find($source->issue_id);
        if (! $issue || ! data_get($source->execution_contract, 'book_stage') || data_get($source->execution_contract, 'issue_action')
            || $issue->attempt_count > 0 || NovelGenerationTask::findOrFail($issue->generation_task_id)->status === 'paused') {
            return null;
        }
        try {
            $preview = app(GenerationIssueActions::class)->preview($issue, 'repair_book');
            if (! $preview['available']) {
                return null;
            }

            return app(GenerationIssueActions::class)->execute($issue->id, 'repair_book', $issue->fingerprint, $preview['control_version'], (string) Str::uuid(), dispatch: false);
        } catch (\Throwable $e) {
            // 修复入口失败不能吞掉原异常，也不能新建递归修复任务。
            $evidence = $issue->fresh()->evidence;
            $evidence['automatic_stop'] = app(GenerationDiagnosticRedactor::class)->clean($e->getMessage());
            $issue->update(['evidence' => $evidence]);

            return null;
        }
    }

    /** 新稿链仅可暂时越过自己的原问题；其他阻塞仍然有效。 */
    public function ownsContinuation(GenerationIssue $issue, array $pending): bool
    {
        $run = GenerationRun::find($pending['completed_run_id'] ?? $pending['run_id'] ?? 0);

        return $issue->status === 'repairing' && $run && (int) data_get($run->execution_contract, 'repair_chain_issue_id') === $issue->id;
    }

    /** 必须新稿的独立审校通过才结案；保留原问题身份及所有收费尝试。 */
    public function closeChain(GenerationRun $run): void
    {
        $artifact = GenerationArtifact::findOrFail($run->result_artifact_id);
        if ($run->status !== RunStatus::Succeeded || data_get($run->execution_contract, 'book_stage') !== 'review' || ($artifact->data['decision'] ?? null) !== 'PASS' || $artifact->validation_status !== ArtifactValidationStatus::Validated) {
            return;
        }
        app(GenerationRunCoordinator::class)->assertSources(NovelGenerationTask::findOrFail($run->generation_task_id), $run->source_manifest, $run->execution_contract);
        // 人工新稿同样以当前完整链的 PASS 为唯一结案依据。
        $task = NovelGenerationTask::findOrFail($run->generation_task_id);
        foreach (($task->progress_snapshot['manual_verification_issue_ids'] ?? []) as $manualId) {
            $manual = GenerationIssue::find($manualId);
            if ($manual && data_get($manual->evidence, 'manual_revision.draft_id') === data_get($run->execution_contract, 'draft_artifact_id')) {
                $manual->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by_run_id' => $run->id, 'resolution_reason' => '人工新稿重新提取与独立审校通过。', 'verification' => ['review_artifact_id' => $run->result_artifact_id]]);
            }
        }
        $id = data_get($run->execution_contract, 'repair_chain_issue_id');
        if (! $id) {
            return;
        }
        $issue = GenerationIssue::findOrFail($id);
        $artifact = GenerationArtifact::findOrFail($run->result_artifact_id);
        if ($artifact->data['decision'] !== 'PASS') {
            return;
        }
        app(GenerationRunCoordinator::class)->assertSources(NovelGenerationTask::findOrFail($run->generation_task_id), $run->source_manifest, $run->execution_contract);
        $issue->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by_run_id' => $run->id, 'resolution_reason' => data_get($run->execution_contract, 'manual_evidence_revision') ? '保存的独立审校响应经唯一逐字定位修正及完整校验通过，原判断未修改。' : '新稿已重新提取、确定性校验及独立审校通过。',
            'verification' => ['draft_artifact_id' => $artifact->data['source_artifact_id'], 'review_artifact_id' => $artifact->id, 'event_artifact_id' => $artifact->data['event_artifact_id'], 'patch_artifact_id' => $artifact->data['patch_artifact_id']]]);
    }
}
