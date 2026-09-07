<?php

namespace App\AI\Providers;

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingRequest;
use App\AI\Data\EmbeddingResponse;
use RuntimeException;
use Throwable;

class FakeEmbeddingProvider implements EmbeddingProvider
{
    /** @var array<int, EmbeddingResponse|Throwable> */
    private array $queue = [];

    /** @var array<int, EmbeddingRequest> */
    private array $requests = [];

    public function enqueue(EmbeddingResponse|Throwable $result): self
    {
        $this->queue[] = $result;

        return $this;
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $this->requests[] = $request;
        $result = array_shift($this->queue);

        if ($result === null) {
            throw new RuntimeException('FakeEmbeddingProvider 没有可用的响应。');
        }

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    /** @return array<int, EmbeddingRequest> */
    public function requests(): array
    {
        return $this->requests;
    }
}
