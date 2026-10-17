<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Jobs\ExecuteGenerationRunJob;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/** 队列投递与数据库执行身份分离，Redis 缓存不是业务完成凭证。 */
class GenerationJobDispatcher
{
    public function dispatch(ShouldQueue&ShouldBeUnique $job): bool
    {
        if (! $this->reserve($job)) {
            return false;
        }

        try {
            $pendingDispatch = dispatch($job);
            unset($pendingDispatch);
        } catch (Throwable $exception) {
            $this->release($job);

            throw $exception;
        }

        return true;
    }

    public function reserve(ShouldQueue&ShouldBeUnique $job): bool
    {
        return Cache::add(
            $this->cacheKey($job),
            now()->toISOString(),
            (int) config('generation.pending_job_seconds', 900),
        );
    }

    public function isPending(ShouldQueue&ShouldBeUnique $job): bool
    {
        return Cache::has($this->cacheKey($job));
    }

    public function release(ShouldQueue&ShouldBeUnique $job): void
    {
        Cache::forget($this->cacheKey($job));
    }

    private function cacheKey(ShouldQueue&ShouldBeUnique $job): string
    {
        if (! method_exists($job, 'uniqueId')) {
            throw new InvalidArgumentException($job::class.' 必须定义 uniqueId()。');
        }

        return 'generation-job:pending:'.hash('sha256', $job::class.'|'.$job->uniqueId());
    }

    /** 新链同一 queued Run 可重复补派，实际执行权由 PostgreSQL CAS 保证。 */
    public function dispatchRun(int $runId): string
    {
        $ready = DB::transaction(function () use ($runId): bool {
            $identity = GenerationRun::findOrFail($runId);
            // 与认领、暂停保持相同锁顺序，避免投递与控制相互死锁。
            $novel = Novel::whereKey($identity->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($identity->generation_task_id)->lockForUpdate()->first();
            $run = GenerationRun::whereKey($runId)->lockForUpdate()->firstOrFail();
            if (! $task || (int) $novel->current_generation_task_id !== $task->id || ! in_array($task->status, ['queued', 'running'], true) || $run->status !== RunStatus::Queued || $run->next_attempt_at?->isFuture() || $run->dispatch_expires_at?->isFuture()) {
                return false;
            }
            $run->update(['dispatch_expires_at' => now()->addSeconds((int) config('generation.execution.dispatch_seconds', 120))]);

            return true;
        });
        if (! $ready) {
            return 'already_exists_or_blocked';
        }
        try {
            ExecuteGenerationRunJob::dispatch($runId)->afterCommit();
        } catch (Throwable $exception) {
            GenerationRun::whereKey($runId)->update(['dispatch_expires_at' => null, 'error_code' => 'dispatch_failed', 'error_message' => '队列派发失败，请检查队列连接。']);
            report($exception);

            return 'failed';
        }

        return DB::transactionLevel() > 0 ? 'after_commit_pending' : 'enqueued';
    }
}
