<?php

namespace App\Services;

use App\AI\Data\AiRequest;

/** 为一次真实 Provider 请求生成可复现、可校验的完整 Prompt 合同。 */
final class PromptExecutionContract
{
    /** @return array{schema_version: int, prompt_version: string|null, system_prompt: string|null, messages: array<int, array{role: string, content: string}>, temperature: float, max_tokens: int, reasoning_effort: string|null, response_schema: array<string, mixed>|null, checksum: string} */
    public function make(AiRequest $request): array
    {
        $payload = [
            'schema_version' => (int) config('prompts.reproducibility.schema_version', 1),
            'prompt_version' => $request->promptVersion,
            'system_prompt' => $request->systemPrompt,
            'messages' => $request->messages,
            'prompt' => $request->prompt,
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxTokens,
            'reasoning_effort' => $request->reasoningEffort,
            'response_schema' => $request->responseSchema,
        ];

        return [
            ...$payload,
            'checksum' => hash('sha256', json_encode(
                app(GenerationStageFingerprint::class)->normalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            )),
        ];
    }
}
