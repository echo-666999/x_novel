<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Models\Novel;
use App\Services\GenerationFailurePolicy;
use App\Services\NovelPlanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerateNovelOutlineJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 330;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $novelId,
        public readonly int $volumeCount,
    ) {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'novel-outline:'.$this->novelId;
    }

    public function handle(NovelPlanner $planner): void
    {
        try {
            // 全书规划通常超过 Web 请求时限，必须在 generation Worker 中执行。
            $planner->generate(Novel::query()->findOrFail($this->novelId), $this->volumeCount);
        } catch (AiProviderException $exception) {
            // 相同 Token 上限下重试截断响应只会重复产生费用，必须先调整请求预算。
            if (! $exception->retryable || $exception->errorCode === 'novel_plan_output_truncated') {
                $this->fail($exception);

                return;
            }

            throw $exception;
        } catch (ValidationException $exception) {
            // Schema 或领域校验失败需要修正输入，重复调用模型不会自行恢复。
            $this->fail($exception);
        } catch (Throwable $exception) {
            $failure = app(GenerationFailurePolicy::class)
                ->fromException($exception, 'novel_planning_code_failure');

            if ($failure->retryable) {
                throw $exception;
            }

            $this->fail($exception);
        }
    }
}
