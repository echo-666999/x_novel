<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Jobs\ContinueAutoGenerationJob;
use App\Jobs\GenerateNovelOutlineJob;
use App\Jobs\RefreshNovelProjectionJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Novel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 从 PostgreSQL 中认领可机械恢复的断点。
 *
 * 服务恢复 Worker 丢失和 Canonical Commit 后的派发缺口，并按内容自愈合同关闭遗留修复；
 * 确定性业务冲突、Locked Fact 和人工暂停仍由 AutoStop 保持安全停止。
 */
final class AutomaticGenerationRecoveryService
{
    public function __construct(
        private readonly StalledRunRecoveryService $stalledRuns,
        private readonly CanonicalPostCommitDispatcher $postCommit,
        private readonly AutoStopService $autoStop,
        private readonly ContentSelfHealingService $contentSelfHealing,
        private readonly GenerationJobDispatcher $dispatcher,
        private readonly GenerationRunLease $runLease,
    ) {}

    /** @return array{queued_lease: int, marked: int, worker_lost: int, post_commit: int, exhausted: int, content_repair_expired: int} */
    public function recover(): array
    {
        // 新任务按独立身份恢复，不交给旧章节/大纲扫描器重新收费。
        $taskRecovery = app(GenerationRunCoordinator::class)->recoverTasks();
        app(BookMemoryService::class)->reconcile();
        $expiredRepairs = $this->contentSelfHealing->expireExceeded();
        $result = [
            'tasks' => $taskRecovery,
            'queued_lease' => $this->recoverExpiredQueuedOutlineRuns(),
            'marked' => $this->stalledRuns->markStalledRuns(),
            'worker_lost' => 0,
            'post_commit' => 0,
            'exhausted' => 0,
            'content_repair_expired' => $expiredRepairs->count(),
        ];
        foreach ($expiredRepairs as $run) {
            $this->autoStop->stopForFailure(
                (int) $run->chapter_id,
                new AiProviderException(
                    (string) $run->error_code,
                    (string) $run->error_message,
                    false,
                ),
            );
        }
        $requiredChainChapterIds = [];

        foreach ($this->workerLostRuns() as $run) {
            if (! $this->claimRunRecovery($run)) {
                $this->stopExhaustedRun($run);
                $result['exhausted']++;

                continue;
            }

            try {
                $this->stalledRuns->recover($run);
                $result['worker_lost']++;
                if ($run->stage === GenerationStage::MemorySummary && $run->chapter_id !== null) {
                    $requiredChainChapterIds[] = (int) $run->chapter_id;
                }
            } catch (Throwable $exception) {
                $this->recordRecoveryError($run, $exception);
            }
        }

        foreach ($this->novelsWithPostCommitWork() as $novel) {
            foreach (['summary', 'memory', 'projection', 'continuation'] as $stage) {
                if ($stage === 'continuation'
                    && is_array(data_get($novel->settings, 'post_commit_failures.summary'))) {
                    continue;
                }
                $failure = data_get($novel->settings, "post_commit_failures.{$stage}");
                if (! is_array($failure)) {
                    continue;
                }

                $claim = $this->claimPostCommitRecovery($novel, $stage);
                if ($claim === null) {
                    continue;
                }
                if ($claim === false) {
                    $this->autoStop->stop(
                        $novel,
                        'post_commit_recovery_exhausted',
                        "提交后 {$stage} 自动恢复次数已耗尽。",
                        '检查提交后失败诊断和对应 Generation Run。',
                    );
                    $result['exhausted']++;

                    continue;
                }

                if ($this->dispatchPostCommitRecovery($novel->fresh(), $stage)) {
                    $result['post_commit']++;
                    if ($stage === 'summary') {
                        $requiredChainChapterIds[] = (int) ($failure['chapter_id'] ?? 0);
                    }
                }
            }
        }

        // 进程在 Commit 成功、派发提交后任务前崩溃时不会留下 failure JSON；
        // 当前正式章缺少摘要本身就是可确定识别的恢复点。
        foreach ($this->canonicalChaptersMissingSummary() as $chapter) {
            if (in_array($chapter->getKey(), $requiredChainChapterIds, true)
                || $this->hasActiveSummaryRun($chapter)) {
                continue;
            }

            $stateVersion = $chapter->latestStateVersion;
            if ($stateVersion !== null) {
                // Canonical Commit 可能在任何提交后 Job 入队前崩溃。超过租约后补发完整链；
                // 各 Job 会重新核对 Canonical 来源，并按自身幂等规则执行。
                $this->postCommit->dispatch(
                    $chapter,
                    $stateVersion,
                    $this->summaryRestartSource($chapter),
                );
                $result['post_commit']++;
            }
        }

        return $result;
    }

    /** 重新投递未被 Worker 认领的 Outline 主批次；租约认领与次数累计均以 PostgreSQL 为准。 */
    private function recoverExpiredQueuedOutlineRuns(): int
    {
        $recovered = 0;
        $runs = GenerationRun::query()->whereNotIn('pipeline_version', ['pipeline-v1', 'book-derived-v1'])
            ->where('status', RunStatus::Queued)
            ->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)
            ->where('lease_expires_at', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            $claimed = DB::transaction(function () use ($run): ?GenerationRun {
                $locked = GenerationRun::query()->whereNotIn('pipeline_version', ['pipeline-v1', 'book-derived-v1'])->lockForUpdate()->find($run->getKey());
                if ($locked === null || $locked->status !== RunStatus::Queued || ! $this->runLease->isExpired($locked)) {
                    return null;
                }

                $snapshot = $locked->context_snapshot ?? [];
                $attempts = (int) data_get($snapshot, 'automatic_recovery.queued_lease_attempts', 0);
                if ($attempts >= $this->maxAttempts()) {
                    data_set($snapshot, 'automatic_recovery.exhausted_at', now()->toISOString());
                    $locked->update([
                        'status' => RunStatus::Failed,
                        'error_code' => 'queued_run_lease_expired',
                        'error_message' => 'Queued Run 多次未被 Worker 认领，自动恢复次数已耗尽。',
                        'error_retryable' => false,
                        'error_metadata' => ['category' => 'worker_lost'],
                        'context_snapshot' => $snapshot,
                        'lease_expires_at' => null,
                        'lease_token' => null,
                        'finished_at' => now(),
                    ]);

                    return null;
                }

                data_set($snapshot, 'automatic_recovery.queued_lease_attempts', $attempts + 1);
                data_set($snapshot, 'automatic_recovery.last_queued_recovery_at', now()->toISOString());
                $locked->update(['context_snapshot' => $snapshot]);

                return $this->runLease->queue($locked);
            }, 3);

            if ($claimed === null) {
                continue;
            }

            $volumeCount = data_get($claimed->context_snapshot, 'requested_volume_count');
            if (! is_numeric($volumeCount) || (int) $volumeCount < 1) {
                $claimed->update([
                    'status' => RunStatus::Failed,
                    'error_code' => 'queued_run_contract_invalid',
                    'error_message' => 'Queued Outline Run 缺少冻结的分卷数量。',
                    'error_retryable' => false,
                    'error_metadata' => ['category' => 'domain_validation'],
                    'lease_expires_at' => null,
                    'lease_token' => null,
                    'finished_at' => now(),
                ]);

                continue;
            }

            $job = new GenerateNovelOutlineJob($claimed->novel_id, (int) $volumeCount);
            if ($this->dispatcher->dispatch($job) || $this->dispatcher->isPending($job)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    /** @return Collection<int, GenerationRun> */
    private function workerLostRuns()
    {
        return GenerationRun::query()->whereNotIn('pipeline_version', ['pipeline-v1', 'book-derived-v1'])
            ->where('status', RunStatus::Failed)
            ->where('error_code', StalledRunRecoveryService::ERROR_CODE)
            ->whereNull('context_snapshot->recovery->claimed_at')
            ->whereNull('context_snapshot->automatic_recovery->exhausted_at')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('generation_runs as newer_runs')
                    ->whereColumn('newer_runs.stage', 'generation_runs.stage')
                    ->whereColumn('newer_runs.scope_type', 'generation_runs.scope_type')
                    ->whereColumn('newer_runs.scope_id', 'generation_runs.scope_id')
                    ->whereColumn('newer_runs.id', '>', 'generation_runs.id');
            })
            ->whereHas('novel', fn ($query) => $query->whereIn('status', [NovelStatus::Generating, NovelStatus::Completing]))
            ->orderBy('id')
            ->get();
    }

    private function claimRunRecovery(GenerationRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $locked = GenerationRun::query()->whereNotIn('pipeline_version', ['pipeline-v1', 'book-derived-v1'])->lockForUpdate()->findOrFail($run->getKey());
            $snapshot = $locked->context_snapshot ?? [];
            $attempts = (int) data_get($snapshot, 'automatic_recovery.attempts', 0);
            if ($attempts >= $this->maxAttempts()) {
                data_set($snapshot, 'automatic_recovery.exhausted_at', now()->toISOString());
                $locked->update(['context_snapshot' => $snapshot]);

                return false;
            }

            data_set($snapshot, 'automatic_recovery.attempts', $attempts + 1);
            data_set($snapshot, 'automatic_recovery.last_attempted_at', now()->toISOString());
            $locked->update(['context_snapshot' => $snapshot]);

            return true;
        });
    }

    private function recordRecoveryError(GenerationRun $run, Throwable $exception): void
    {
        $snapshot = $run->fresh()->context_snapshot ?? [];
        data_set($snapshot, 'automatic_recovery.last_error', $exception->getMessage());
        data_set($snapshot, 'automatic_recovery.last_failed_at', now()->toISOString());
        $run->update(['context_snapshot' => $snapshot]);
    }

    private function stopExhaustedRun(GenerationRun $run): void
    {
        if ($run->chapter_id !== null) {
            $this->autoStop->stopForFailure((int) $run->chapter_id, null);
        }
    }

    /** @return Collection<int, Novel> */
    private function novelsWithPostCommitWork()
    {
        return Novel::query()->whereNull('current_generation_task_id')
            ->whereIn('status', [NovelStatus::Generating, NovelStatus::Completing])
            ->whereNotNull('settings->post_commit_failures')
            ->get();
    }

    /** null 表示故障已被并发任务解决，false 才表示确实达到恢复上限。 */
    private function claimPostCommitRecovery(Novel $novel, string $stage): ?bool
    {
        return DB::transaction(function () use ($novel, $stage): ?bool {
            $locked = Novel::query()->whereNull('current_generation_task_id')->lockForUpdate()->findOrFail($novel->getKey());
            $settings = $locked->settings ?? [];
            $failure = data_get($settings, "post_commit_failures.{$stage}");
            if (! is_array($failure)) {
                return null;
            }
            if (filled($failure['exhausted_at'] ?? null)) {
                return null;
            }

            $lastRecovery = $this->parseTime($failure['last_recovery_at'] ?? null);
            $failedAt = $this->parseTime($failure['failed_at'] ?? null);
            if ($lastRecovery !== null
                && ($failedAt === null || ! $failedAt->gt($lastRecovery))
                && $lastRecovery->gt(now()->subSeconds($this->recoveryCooldownSeconds()))) {
                return null;
            }

            $attempts = (int) ($failure['recovery_attempts'] ?? 0);
            if ($attempts >= $this->maxAttempts()) {
                data_set($settings, "post_commit_failures.{$stage}.exhausted_at", now()->toISOString());
                $locked->update(['settings' => $settings]);

                return false;
            }

            data_set($settings, "post_commit_failures.{$stage}.recovery_attempts", $attempts + 1);
            data_set($settings, "post_commit_failures.{$stage}.last_recovery_at", now()->toISOString());
            $locked->update(['settings' => $settings]);

            return true;
        });
    }

    private function dispatchPostCommitRecovery(Novel $novel, string $stage): bool
    {
        $failure = (array) data_get($novel->settings, "post_commit_failures.{$stage}", []);
        $chapter = Chapter::query()->with(['novel', 'latestStateVersion'])->find((int) ($failure['chapter_id'] ?? 0));
        if ($chapter === null
            || $chapter->status !== ChapterStatus::Canonical
            || (int) $chapter->canonical_artifact_id !== (int) ($failure['canonical_artifact_id'] ?? 0)) {
            $this->autoStop->resolvePostCommitFailure($novel, $stage);

            return false;
        }

        return match ($stage) {
            'summary' => $this->dispatchSummary($chapter),
            'memory' => $this->dispatchMemory($chapter),
            'projection' => $this->dispatchProjection($chapter, (int) ($failure['state_version_id'] ?? 0)),
            'continuation' => $this->dispatchContinuation($chapter, (int) ($failure['state_version_id'] ?? 0)),
            default => false,
        };
    }

    private function dispatchSummary(Chapter $chapter): bool
    {
        if (filled($chapter->summary) || $chapter->latestStateVersion === null) {
            $this->autoStop->resolvePostCommitFailure($chapter->novel, 'summary');

            return false;
        }

        $this->postCommit->dispatchRequired($chapter, $chapter->latestStateVersion, $this->summaryRestartSource($chapter));

        return true;
    }

    private function dispatchMemory(Chapter $chapter): bool
    {
        UpdateMemoryJob::dispatch($chapter->getKey());

        return true;
    }

    private function dispatchProjection(Chapter $chapter, int $stateVersionId): bool
    {
        if ($stateVersionId < 1 || (int) $chapter->novel->canonical_state_version_id !== $stateVersionId) {
            $this->autoStop->resolvePostCommitFailure($chapter->novel, 'projection');

            return false;
        }

        RefreshNovelProjectionJob::dispatch($chapter->novel_id, $stateVersionId);

        return true;
    }

    private function dispatchContinuation(Chapter $chapter, int $stateVersionId): bool
    {
        if (! (bool) data_get($chapter->novel->settings, 'auto_generate', false)) {
            $this->autoStop->resolvePostCommitFailure($chapter->novel, 'continuation');

            return false;
        }

        if (blank($chapter->summary)
            || $stateVersionId < 1
            || (int) $chapter->novel->canonical_state_version_id !== $stateVersionId) {
            return false;
        }

        ContinueAutoGenerationJob::dispatch(
            $chapter->getKey(),
            (int) $chapter->canonical_artifact_id,
            $stateVersionId,
        );

        return true;
    }

    /** @return Collection<int, Chapter> */
    private function canonicalChaptersMissingSummary()
    {
        return Chapter::query()
            ->where('status', ChapterStatus::Canonical)
            ->whereNull('summary')
            ->whereNotNull('canonical_artifact_id')
            ->where('updated_at', '<=', app(GenerationRunLease::class)->cutoff())
            ->whereHas('novel', fn ($query) => $query
                ->whereIn('status', [NovelStatus::Generating, NovelStatus::Completing])
                ->whereColumn('current_chapter_sequence', 'chapters.sequence'))
            ->with(['novel', 'latestStateVersion'])
            ->get();
    }

    private function hasActiveSummaryRun(Chapter $chapter): bool
    {
        return $chapter->generationRuns()
            ->where('stage', GenerationStage::MemorySummary)
            ->where('scope_type', 'chapter_summary')
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->where('updated_at', '>', app(GenerationRunLease::class)->cutoff())
            ->exists();
    }

    private function summaryRestartSource(Chapter $chapter): ?int
    {
        $latest = $chapter->generationRuns()
            ->where('stage', GenerationStage::MemorySummary)
            ->where('scope_type', 'chapter_summary')
            ->where('status', RunStatus::Failed)
            ->latest('id')
            ->first();
        if ($latest === null) {
            return null;
        }

        $original = data_get($latest->context_snapshot, 'generation_preferences.restart_source_run_id');
        if (is_numeric($original)) {
            return (int) $original;
        }

        $code = (string) $latest->error_code;

        return str_contains($code, '_reasoning_budget_exhausted')
            || str_contains($code, '_output_truncated')
            || str_contains($code, '_completion_budget_exhausted')
            || str_contains($code, '_output_budget_exhausted')
                ? $latest->getKey()
                : null;
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('generation.automatic_recovery.max_attempts', 3));
    }

    private function recoveryCooldownSeconds(): int
    {
        return max(60, (int) config('generation.pending_job_seconds', 900));
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
