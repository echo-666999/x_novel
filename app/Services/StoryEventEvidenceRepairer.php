<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use Illuminate\Validation\ValidationException;

final class StoryEventEvidenceRepairer
{
    private const PROMPT_VERSION = 'event-evidence-repair-v1';

    public function __construct(
        private readonly AiProvider $provider,
        private readonly StoryEventEvidenceQuoteResolver $quoteResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $metadata
     * @return array<int, array<string, mixed>>
     */
    public function repair(array $event, string $content, string $model, array $metadata, int $eventIndex, ?string $reasoningEffort = null): array
    {
        $evidence = $event['evidence'] ?? null;

        if (! is_array($evidence) || $evidence === []) {
            throw ValidationException::withMessages(['evidence' => 'Story Event Evidence 不能为空。']);
        }

        $lastException = null;

        for ($attempt = 1; $attempt <= (int) config('generation.max_event_evidence_repair_attempts', 2); $attempt++) {
            $maxTokens = $attempt === 1
                ? (int) config('generation.event_evidence_repair_max_output_tokens', 1_000)
                : (int) config('generation.event_evidence_repair_retry_max_output_tokens', 4_000);
            $response = $this->provider->generate(new AiRequest(
                model: $model,
                reasoningEffort: $reasoningEffort,
                systemPrompt: '你是 XNovel Story Event 证据校对器。不得修改事件类型、主体、payload、story_time、confidence 或章节正文。按原 evidence 顺序为每项返回一个 quote；每个 quote 必须是 content 中一段连续文本的逐字复制，保留原标点和段落换行，不得概括、改写、拼接不连续句子或补字。',
                prompt: '请修正以下 Story Event evidence quote：'.json_encode([
                    'event' => collect($event)->except('evidence')->all(),
                    'current_evidence' => $evidence,
                    'content' => $content,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.2,
                maxTokens: $maxTokens,
                responseSchema: self::responseSchema(count($evidence)),
                promptVersion: self::PROMPT_VERSION,
                metadata: [...$metadata, 'event_evidence_repair_attempt' => $attempt, 'event_index' => $eventIndex],
            ));

            $quotes = data_get($response->structuredData, 'quotes');

            if (! is_array($quotes) || count($quotes) !== count($evidence)) {
                $lastException = data_get($response->metadata, 'finish_reason') === 'length'
                    ? new AiProviderException(
                        'event_evidence_repair_output_truncated',
                        "Story Event evidence 修复第 {$attempt} 次响应因输出 Token 用尽而被截断。",
                        false,
                    )
                    : new AiProviderException(
                        'event_evidence_repair_schema_invalid',
                        "Story Event evidence 修复第 {$attempt} 次响应没有返回可解析的结构化结果。",
                        false,
                    );

                continue;
            }

            try {
                $repairedEvidence = collect($evidence)->values()->map(function (mixed $item, int $index) use ($quotes, $content): ?array {
                    if (! is_array($item) || ! is_string($quotes[$index] ?? null)) {
                        return null;
                    }

                    $quote = $this->quoteResolver->resolve($content, $quotes[$index]);

                    if ($quote === '' || ! str_contains($content, $quote)) {
                        return null;
                    }

                    return [
                        ...$item,
                        'quote' => $quote,
                        'start_offset' => null,
                        'end_offset' => null,
                    ];
                })->filter()->values()->all();

                if ($repairedEvidence === []) {
                    throw ValidationException::withMessages(['evidence' => '修复后的 Candidate Evidence quote 必须逐字来自当前 Chapter Draft。']);
                }

                return $repairedEvidence;
            } catch (ValidationException $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? ValidationException::withMessages([
            'evidence' => 'Story Event evidence 修复失败。',
        ]);
    }

    /** @return array<string, mixed> */
    public static function responseSchema(int $evidenceCount): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['quotes'],
            'properties' => [
                'quotes' => [
                    'type' => 'array',
                    'minItems' => $evidenceCount,
                    'maxItems' => $evidenceCount,
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
