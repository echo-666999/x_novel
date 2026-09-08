<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Enums\ReviewDecision;
use App\Exceptions\GenerationPreflightException;
use App\Models\Chapter;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Throwable;

class AutoStopService
{
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

        $errorCode = $exception instanceof AiProviderException ? $exception->errorCode : null;
        [$code, $reason, $action] = match (true) {
            $exception instanceof AiProviderException && $exception->retryable => ['provider_retry_exhausted', 'Provider 重试次数已耗尽。', '检查 Provider 状态后从失败阶段重试。'],
            $errorCode === 'rewrite_exhausted' => ['rewrite_exhausted', 'Rewrite 次数已耗尽。', '人工编辑章节或调整 Review Findings。'],
            str_starts_with((string) $errorCode, 'budget_') => ['budget_limit', '生成已达到预算 Hard Limit。', '检查并调整小说或全局预算。'],
            $errorCode === 'state_version_conflict' => ['state_version_conflict', 'Canonical Story State 版本已经变化。', '基于最新 Story State 重建 Context 后恢复。'],
            $errorCode === 'blocked_review' => ['review_blocked', '存在被审校阻塞的章节。', '打开 Review Inbox 处理阻塞章节。'],
            in_array($errorCode, ['current_volume_missing', 'volume_gate_failed'], true) => ['volume_gate', '当前分卷不允许继续自动生成。', '检查分卷状态与下一章规划。'],
            in_array($errorCode, ['ending_audit_block', 'critical_closure_debt'], true) => ['ending_audit_block', 'Ending Audit 阻止继续生成。', '处理关键 Closure Debt 后重新检查。'],
            default => [null, null, null],
        };

        if ($code !== null) {
            $this->stop($chapter->novel, $code, $reason, $action);
        }
    }

    public function stopForPreflight(Novel $novel, Throwable $exception): void
    {
        $code = $exception instanceof GenerationPreflightException ? $exception->reason
            : ($exception instanceof AiProviderException ? $exception->errorCode : null);
        $proxy = $exception instanceof AiProviderException
            ? $exception
            : new AiProviderException((string) $code, $exception->getMessage(), false);
        $chapterId = $novel->chapters()->latest('sequence')->value('id');

        if ($chapterId !== null) {
            $this->stopForFailure((int) $chapterId, $proxy);
        }
    }
}
