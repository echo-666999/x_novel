<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use Illuminate\Validation\ValidationException;

final class ForeshadowingCoverageEvidenceRepairer
{
    public const PROMPT_VERSION = 'foreshadowing-coverage-evidence-repair-v1';

    public function __construct(private readonly AiProvider $provider) {}

    /**
     * @param  array<int, array<string, mixed>>  $coverage
     * @param  array<int, array<string, mixed>>  $expectations
     * @param  array<string, mixed>  $metadata
     * @return array<int, array<string, mixed>>
     */
    public function repair(
        array $coverage,
        string $content,
        array $expectations,
        string $model,
        array $metadata,
        string $path,
        ?string $reasoningEffort = null,
    ): array {
        for ($attempt = 1; $attempt <= (int) config('generation.max_coverage_repair_attempts', 1); $attempt++) {
            $maxTokens = $attempt === 1
                ? (int) config('generation.coverage_repair_max_output_tokens', 1_000)
                : (int) config('generation.coverage_repair_retry_max_output_tokens', 4_000);
            $response = $this->provider->generate(new AiRequest(
                model: $model,
                reasoningEffort: $reasoningEffort,
                systemPrompt: '你是 XNovel 伏笔 Coverage 证据校对器。不得修改正文，也不得改变数组顺序、foreshadowing_id、action 或 status。status=fulfilled 或 contradicted 时，evidence 必须从 content 中选择一段连续文本并逐字复制；不得概括、改写、拼接不连续句子或用主题相近措辞替代动作证据。status=missing 时 evidence 必须为 null。只返回符合 Schema 的数组。',
                prompt: '请只修正以下伏笔 Coverage 的 evidence：'.json_encode([
                    'expectations' => $expectations,
                    'content' => $content,
                    'coverage' => $coverage,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.2,
                maxTokens: $maxTokens,
                responseSchema: ForeshadowingCoverage::schema(),
                promptVersion: self::PROMPT_VERSION,
                metadata: [...$metadata, 'coverage_repair_attempt' => $attempt, 'coverage_path' => $path],
            ));

            if (! is_array($response->structuredData)) {
                continue;
            }

            $this->assertClaimsUnchanged($coverage, $response->structuredData, $path);

            try {
                return ForeshadowingCoverage::validate(
                    $response->structuredData,
                    $content,
                    $expectations,
                    $path,
                );
            } catch (ValidationException) {
                continue;
            }
        }

        return ForeshadowingCoverage::fallbackUnverifiableEvidenceToMissing(
            $coverage,
            $content,
            $expectations,
            $path,
        );
    }

    /** @param array<int, array<string, mixed>> $before
     * @param  array<int, array<string, mixed>>  $after
     */
    private function assertClaimsUnchanged(array $before, array $after, string $path): void
    {
        $claims = fn (array $items): array => collect($items)->map(fn (mixed $item): array => [
            'foreshadowing_id' => is_array($item) ? ($item['foreshadowing_id'] ?? null) : null,
            'action' => is_array($item) ? ($item['action'] ?? null) : null,
            'status' => is_array($item) ? ($item['status'] ?? null) : null,
        ])->all();

        if ($claims($before) !== $claims($after)) {
            throw ValidationException::withMessages([
                $path => '伏笔 Coverage 证据修复不得改变数组顺序、伏笔 ID、动作或原 status。',
            ]);
        }
    }
}
