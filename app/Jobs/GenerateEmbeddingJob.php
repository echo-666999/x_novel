<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
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

    public int $timeout = 60;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $memoryId)
    {
        $this->onQueue('default');
    }

    public function handle(MemoryEmbedder $embedder): void
    {
        try {
            $embedder->embed($this->memoryId);
        } catch (AiProviderException $exception) {
            if (! $exception->retryable) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(MemoryEmbedder::class)->markTerminalFailure(
            $this->memoryId,
            $exception ?? new AiProviderException('embedding_failed', 'Memory 向量化失败。', false),
        );
    }
}
