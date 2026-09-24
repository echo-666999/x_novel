<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use Illuminate\Validation\ValidationException;

final class PlanCoverageEvidenceRepairer
{
    public const PROMPT_VERSION = 'coverage-evidence-repair-v1';

    public function __construct(private readonly AiProvider $provider) {}

    /**
     * @param  array<string, mixed>  $coverage
     * @param  array<string, mixed>  $metadata
     * @return array<string, array{status: string, evidence: string|null}>
     */
    public function repair(array $coverage, string $content, string $model, array $metadata, mixed $task, string $path, ?string $reasoningEffort = null): array
    {
        for ($attempt = 1; $attempt <= (int) config('generation.max_coverage_repair_attempts', 1); $attempt++) {
            $maxTokens = $attempt === 1
                ? (int) config('generation.coverage_repair_max_output_tokens', 1_000)
                : (int) config('generation.coverage_repair_retry_max_output_tokens', 4_000);
            $response = $this->provider->generate(new AiRequest(
                model: $model,
                reasoningEffort: $reasoningEffort,
                systemPrompt: '你是 XNovel Coverage 证据校对器。不得修改正文，也不得改变 goal、conflict、turn、outcome 的 status。status=fulfilled 或 contradicted 时，evidence 必须从 content 中选择一段连续文本并逐字复制，保留原标点和段落换行；不得概括、改写、拼接不连续句子或补字。status=missing 时 evidence 必须为 null。只返回符合 Schema 的 Coverage 对象。',
                prompt: '请修正以下 Coverage 的 evidence：'.json_encode([
                    'task' => $task,
                    'content' => $content,
                    'coverage' => $coverage,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.2,
                maxTokens: $maxTokens,
                responseSchema: PlanCoverage::schema(),
                promptVersion: self::PROMPT_VERSION,
                metadata: [...$metadata, 'coverage_repair_attempt' => $attempt, 'coverage_path' => $path],
            ));

            if ($response->structuredData === null) {
                continue;
            }

            foreach (PlanCoverage::ELEMENTS as $element) {
                if (data_get($response->structuredData, "{$element}.status") !== data_get($coverage, "{$element}.status")) {
                    throw ValidationException::withMessages([
                        "{$path}.{$element}.status" => "{$path}.{$element}.status：Coverage 证据修复不得改变原 status。",
                    ]);
                }
            }

            try {
                return PlanCoverage::validate($response->structuredData, $content, $path);
            } catch (ValidationException) {
                continue;
            }
        }

        return PlanCoverage::fallbackUnverifiableEvidenceToMissing($coverage, $content, $path);
    }
}
