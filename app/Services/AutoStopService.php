<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Exceptions\GenerationPreflightException;
use App\Models\Chapter;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Throwable;

class AutoStopService
{
    public function __construct(private readonly GenerationFailurePolicy $failurePolicy) {}

    /** 所有阻断都写入同一持久化诊断，禁止以空映射静默结束流水线。 */
    public function stop(Novel $novel, string $code, string $reason, string $recommendedAction, bool $disableAutoGeneration = true): Novel
    {
        return DB::transaction(function () use ($novel, $code, $reason, $recommendedAction, $disableAutoGeneration): Novel {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $settings = $lockedNovel->settings ?? [];
            if ($disableAutoGeneration) {
                $settings['auto_generate'] = false;
            }
            $settings['auto_stop'] = [
                'code' => $code,
                'reason' => $reason,
                'recommended_action' => $recommendedAction,
                'stopped_at' => now()->toISOString(),
            ];
            $lockedNovel->update(['settings' => $settings]);

            return $lockedNovel->refresh();
        });
    }

    public function stopForReview(Chapter $chapter, ReviewDecision $decision, bool $hardConflict = false): void
    {
        if ($hardConflict) {
            $this->stop($chapter->novel, 'hard_conflict', '确定性状态校验发现 Hard Conflict。', '检查 State Validation Findings，修正草稿或事实冲突。');
        } elseif ($decision === ReviewDecision::NeedsAttention) {
            $this->stop($chapter->novel, 'needs_attention', '章节审校需要人工处理。', '打开 Review Inbox 处理 Findings。');
        } elseif ($decision === ReviewDecision::Block) {
            $this->stop($chapter->novel, 'review_blocked', '章节审校已阻塞。', '检查阻塞原因并修正章节或故事状态。');
        }
    }

    public function stopForFailure(int $chapterId, ?Throwable $exception): void
    {
        $chapter = Chapter::query()->with('novel')->find($chapterId);
        if ($chapter === null) {
            return;
        }

        $latestFailedRun = $chapter->generationRuns()
            ->where('status', RunStatus::Failed)
            ->latest('id')
            ->first();
        $useLatestFailedRun = $latestFailedRun !== null
            && ! $exception instanceof AiProviderException
            && ! $exception instanceof GenerationPreflightException
            && ($exception === null || $latestFailedRun->error_message === $exception->getMessage());
        $errorCode = match (true) {
            $exception instanceof AiProviderException => $exception->errorCode,
            $exception instanceof GenerationPreflightException => $exception->reason,
            $useLatestFailedRun => (string) $latestFailedRun->error_code,
            default => 'generation_failed',
        };
        $failure = $useLatestFailedRun
            ? $this->failurePolicy->forRun($latestFailedRun)
            : ($exception === null
                ? null
                : $this->failurePolicy->fromException($exception, $errorCode));
        $category = (string) data_get($failure?->metadata, 'category', 'manual_attention');
        [$code, $reason, $action] = match (true) {
            $failure?->retryable === true => ['provider_retry_exhausted', 'Provider 或基础设施重试次数已耗尽。', $failure->recommendedAction],
            $errorCode === 'rewrite_exhausted' => ['rewrite_exhausted', 'Rewrite 次数已耗尽。', '人工编辑章节或调整 Review Findings。'],
            str_starts_with((string) $errorCode, 'content_repair_') => ['content_repair_limit', '内容自愈失败或达到硬上限。', '检查内容自愈 Run 的来源、诊断、费用和耗时后显式重启。'],
            str_starts_with((string) $errorCode, 'budget_') => ['budget_limit', '生成已达到预算 Hard Limit。', '检查并调整小说或全局预算。'],
            $errorCode === 'state_version_conflict' => ['state_version_conflict', 'Canonical Story State 版本已经变化。', '基于最新 Story State 重建 Context 后恢复。'],
            $errorCode === 'pipeline_progression_failed' => ['pipeline_progression_failed', '生成阶段已成功，但后续流程推进失败。', '查看成功 Run 的推进异常，修复条件后继续流水线。'],
            $errorCode === 'blocked_review' => ['review_blocked', '存在被审校阻塞的章节。', '打开 Review Inbox 处理阻塞章节。'],
            in_array($errorCode, ['current_volume_missing', 'volume_gate_failed'], true) => ['volume_gate', '当前分卷不允许继续自动生成。', '检查分卷状态与下一章规划。'],
            $errorCode === 'critical_foreshadowing_overdue' => ['critical_foreshadowing_overdue', 'Critical 伏笔已经逾期，自动 Planner 已停止。', '在当前章节建立明确兑现的修复计划，或先完成人工授权的延期/放弃。'],
            in_array($errorCode, ['ending_audit_block', 'critical_closure_debt'], true) => ['ending_audit_block', 'Ending Audit 阻止继续生成。', '处理关键 Closure Debt 后重新检查。'],
            $category === 'provider_configuration' => ['provider_configuration', 'Provider 或模型路由配置无效。', $failure?->recommendedAction ?: '修复 Provider 配置后重新启动当前阶段。'],
            $category === 'structured_output' => ['structured_output_invalid', '模型输出在允许的修复和重试后仍无法通过结构校验。', $failure?->recommendedAction ?: '检查 Prompt、Schema 与冻结模型能力后重建当前阶段。'],
            $category === 'domain_validation' => ['domain_validation_failed', '生成结果未通过确定性业务校验。', $failure?->recommendedAction ?: '根据校验错误重建当前阶段输入。'],
            $category === 'state_conflict' => ['state_version_conflict', '生成来源与当前 Canonical Story State 不一致。', '基于最新 Story State 重建 Context 后恢复。'],
            $category === 'worker_lost' => ['worker_lost', '生成任务失去 Worker，自动恢复未能完成。', '检查恢复诊断和最新持久化阶段。'],
            default => [
                $errorCode !== '' ? $errorCode : 'generation_failed',
                $failure?->message ?: ($exception?->getMessage() ?: '生成流程发生未分类的终止错误。'),
                $failure?->recommendedAction ?: '检查最新 Generation Run 与持久化诊断后重建失败阶段。',
            ],
        };

        $this->stop($chapter->novel, $code, $reason, $action);
    }

    public function stopForPreflight(Novel $novel, Throwable $exception): void
    {
        $code = $exception instanceof GenerationPreflightException ? $exception->reason
            : ($exception instanceof AiProviderException ? $exception->errorCode : 'generation_preflight_failed');
        $chapterId = $novel->chapters()->latest('sequence')->value('id');

        if ($chapterId !== null) {
            $this->stopForFailure((int) $chapterId, $exception);

            return;
        }

        $failure = $this->failurePolicy->fromException($exception, $code);
        $this->stop(
            $novel,
            $code,
            $failure->message !== '' ? $failure->message : '生成前置检查失败。',
            $failure->recommendedAction,
        );
    }

    /** 记录可独立恢复的提交后副作用，不把小说误标为已停止。 */
    public function recordPostCommitFailure(Chapter $chapter, string $stage, Throwable $exception, array $context = []): void
    {
        $failure = $this->failurePolicy->fromException($exception, "post_commit_{$stage}_failed");

        DB::transaction(function () use ($chapter, $stage, $failure, $context): void {
            $novel = Novel::query()->lockForUpdate()->findOrFail($chapter->novel_id);
            $settings = $novel->settings ?? [];
            $current = (array) data_get($settings, "post_commit_failures.{$stage}", []);
            $sameSource = (int) ($current['chapter_id'] ?? 0) === $chapter->getKey()
                && (int) ($current['canonical_artifact_id'] ?? 0) === (int) $chapter->canonical_artifact_id;
            data_set($settings, "post_commit_failures.{$stage}", [
                'code' => $failure->code,
                'reason' => $failure->message,
                'recommended_action' => $failure->recommendedAction,
                'chapter_id' => $chapter->getKey(),
                'canonical_artifact_id' => $chapter->canonical_artifact_id,
                'state_version_id' => $novel->canonical_state_version_id,
                'occurrences' => ($sameSource ? max(0, (int) ($current['occurrences'] ?? 0)) : 0) + 1,
                'recovery_attempts' => $sameSource ? max(0, (int) ($current['recovery_attempts'] ?? 0)) : 0,
                'failed_at' => now()->toISOString(),
                ...$context,
            ]);
            $novel->update(['settings' => $settings]);
        });
    }

    public function resolvePostCommitFailure(Novel $novel, string $stage): void
    {
        DB::transaction(function () use ($novel, $stage): void {
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $settings = $locked->settings ?? [];
            if (data_get($settings, "post_commit_failures.{$stage}") === null) {
                return;
            }

            data_forget($settings, "post_commit_failures.{$stage}");
            if ((array) data_get($settings, 'post_commit_failures', []) === []) {
                unset($settings['post_commit_failures']);
            }
            $locked->update(['settings' => $settings]);
        });
    }
}
