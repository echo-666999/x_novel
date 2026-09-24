<?php

namespace App\AI\Data;

final readonly class AiRequest
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $responseSchema
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $model,
        public ?string $provider = null,
        public ?string $systemPrompt = null,
        public array $messages = [],
        public ?string $prompt = null,
        public float $temperature = 0.7,
        public int $maxTokens = 2_000,
        public ?string $reasoningEffort = null,
        public ?array $responseSchema = null,
        public ?string $promptVersion = null,
        public array $metadata = [],
    ) {}

    public function withProvider(string $provider): self
    {
        return new self(
            model: $this->model,
            provider: $provider,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            prompt: $this->prompt,
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            reasoningEffort: $this->reasoningEffort,
            responseSchema: $this->responseSchema,
            promptVersion: $this->promptVersion,
            metadata: $this->metadata,
        );
    }

    /** @return array<int, array{role: string, content: string}> */
    public function resolvedMessages(): array
    {
        $messages = $this->messages;

        if (filled($this->systemPrompt)) {
            array_unshift($messages, ['role' => 'system', 'content' => $this->systemPrompt]);
        }

        if (filled($this->prompt)) {
            $messages[] = ['role' => 'user', 'content' => $this->prompt];
        }

        return $messages;
    }
}
