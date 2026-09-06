<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use RuntimeException;
use Throwable;

class FakeAiProvider implements AiProvider
{
    /** @var array<int, AiResponse|Throwable> */
    private array $queue = [];

    /** @var array<int, AiRequest> */
    private array $requests = [];

    public function enqueue(AiResponse|Throwable $result): self
    {
        $this->queue[] = $result;

        return $this;
    }

    public function generate(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $result = array_shift($this->queue);

        if ($result === null) {
            throw new RuntimeException('FakeAiProvider 没有可用的响应。');
        }

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    /** @return array<int, AiRequest> */
    public function requests(): array
    {
        return $this->requests;
    }
}
