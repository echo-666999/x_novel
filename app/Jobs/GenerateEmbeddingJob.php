<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Models\Memory;
use App\Services\BookMemoryService;
use App\Services\GenerationFailurePolicy;
use App\Services\MemoryEmbedder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 330;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $memoryId)
    {
        $this->onQueue('default');
    }

    public function handle(MemoryEmbedder $embedder): void
    {
        if (! Memory::query()->whereKey($this->memoryId)->exists()) {
            return;
        }

        if (Memory::findOrFail($this->memoryId)->novel->current_generation_task_id) {
            app(BookMemoryService::class)->embed($this->memoryId);

            return;
        }
        try {
            $embedder->embed($this->memoryId);
        } catch (AiProviderException $exception) {
            if (! $exception->retryable) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        } catch (Throwable $exception) {
            $failure = app(GenerationFailurePolicy::class)
                ->fromException($exception, 'embedding_code_failure');

            if ($failure->retryable) {
                throw $exception;
            }

            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $memory = Memory::find($this->memoryId);
        if ($memory?->novel->current_generation_task_id) {
            if (! $memory->embeddingRun?->issue_id) {
                app(BookMemoryService::class)->recordFailure($memory->novel, 'memory:'.$memory->id, $exception ?? new \RuntimeException('派生队列未能完成，请检查模型连接与队列日志。'));
            }

            return;
        }
        app(MemoryEmbedder::class)->markTerminalFailure(
            $this->memoryId,
            $exception ?? new AiProviderException('embedding_failed', 'Memory 向量化失败。', false),
        );
    }
}
