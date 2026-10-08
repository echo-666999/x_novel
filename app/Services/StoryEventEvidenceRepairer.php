<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\StructuredOutput;
use App\Enums\AiStage;
use Illuminate\Validation\ValidationException;

/** 校正 Story Event 的逐字证据，并按 Extractor Run 冻结的独立预算执行。 */
final class StoryEventEvidenceRepairer
{
    private const PROMPT_VERSION = 'event-evidence-repair-v1';

    public function __construct(
        private readonly AiProvider $provider,
        private readonly StoryEventEvidenceQuoteResolver $quoteResolver,
        private readonly GenerationOutputCapacityGuard $outputCapacity,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $metadata
     * @return array<int, array<string, mixed>>
     */
    public function repair(array $event, string $content, string $provider, string $model, array $metadata, int $eventIndex, ?string $reasoningEffort = null, ?callable $beforeRequest = null, int $startingAttempt = 1, ?array $resumeStructuredData = null, ?callable $afterResponse = null): array
    {
        $evidence = $event['evidence'] ?? null;

        if (! is_array($evidence) || $evidence === []) {
            throw ValidationException::withMessages(['evidence' => 'Story Event Evidence 不能为空。']);
        }

        $lastException = null;
        if ($resumeStructuredData !== null) {
            try {
                return $this->validatedEvidence($resumeStructuredData, $evidence, $content);
            } catch (ValidationException $exception) {
                $lastException = $exception;
            }
        }

        for ($attempt = $startingAttempt; $attempt <= (int) config('generation.max_event_evidence_repair_attempts', 2); $attempt++) {
            $tier = $attempt === 1 ? 'initial' : 'retry';
            // Event Evidence 的推理预留独立冻结，避免可见输出额度被隐藏推理全部挤占。
            $repairContract = $this->outputCapacity->frozenRepairBudgetFromMetadata(
                $metadata,
                AiStage::Extractor,
                'event_evidence',
                $tier,
            );
            $budget = $repairContract['budget'];
            $beforeRequest?->__invoke('event_evidence_repair');
            $request = new AiRequest(
                model: $model,
                provider: $provider,
                reasoningEffort: $reasoningEffort,
                systemPrompt: '你是 XNovel Story Event 证据校对器。不得修改事件类型、主体、payload、story_time、confidence 或章节正文。按原 evidence 顺序为每项返回一个 quote；每个 quote 必须是 content 中一段连续文本的逐字复制，保留原标点和段落换行，不得概括、改写、拼接不连续句子或补字。',
                prompt: '请修正以下 Story Event evidence quote：'.json_encode([
                    'event' => collect($event)->except('evidence')->all(),
                    'current_evidence' => $evidence,
                    'content' => $content,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.2,
                maxTokens: $budget['max_completion_tokens'],
                responseSchema: self::responseSchema(count($evidence)),
                promptVersion: self::PROMPT_VERSION,
                metadata: [...$metadata, 'event_evidence_repair_attempt' => $attempt, 'event_index' => $eventIndex, 'repair_budget_tier' => $tier],
            );
            // Evidence 修复不得使用默认 Provider；它必须沿用 Extractor 的冻结 Route 与容量。
            $this->outputCapacity->assertRequestFromMetadata(
                $request,
                AiStage::Extractor,
                'event_evidence_repair',
                $budget,
                $repairContract['tiers'],
            );
            $response = $this->provider->generate($request);
            $afterResponse?->__invoke($response->structuredData, $attempt);

            $quotes = data_get($response->structuredData, 'quotes');

            if (! is_array($quotes) || count($quotes) !== count($evidence)) {
                try {
                    // 修复请求同样使用统一分类，保留推理耗尽、可见截断和证据不足三种事实。
                    StructuredOutput::require($response, 'event_evidence_repair', 'Story Event Evidence 修复结果');
                    $lastException = new AiProviderException(
                        'event_evidence_repair_schema_invalid',
                        "Story Event evidence 修复第 {$attempt} 次响应没有返回完整的 quotes。",
                        false,
                    );
                } catch (AiProviderException $exception) {
                    $lastException = $exception;
                }

                continue;
            }

            try {
                return $this->validatedEvidence($response->structuredData, $evidence, $content);
            } catch (ValidationException $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? ValidationException::withMessages([
            'evidence' => 'Story Event evidence 修复失败。',
        ]);
    }

    /** @param array<string, mixed> $structuredData @param array<int, mixed> $evidence @return array<int, array<string, mixed>> */
    private function validatedEvidence(array $structuredData, array $evidence, string $content): array
    {
        $quotes = data_get($structuredData, 'quotes');
        if (! is_array($quotes) || count($quotes) !== count($evidence)) {
            throw ValidationException::withMessages(['evidence' => 'Story Event evidence 修复响应结构无效。']);
        }

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
