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
        public ?string $systemPrompt = null,
        public array $messages = [],
        public ?string $prompt = null,
        public float $temperature = 0.7,
        public int $maxTokens = 2_000,
        public ?array $responseSchema = null,
        public array $metadata = [],
    ) {}

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
