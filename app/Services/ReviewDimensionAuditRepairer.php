<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\NarrativeProsePolicy;
use App\Enums\ArtifactType;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use Illuminate\Support\Str;
use Throwable;

final class ReviewDimensionAuditRepairer
{
    public const PROMPT_VERSION = 'review-schema-repair-v3';

    public function __construct(private readonly AiProvider $provider) {}

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $findingCodes
     * @return array<string, mixed>
     */
    public function repair(GenerationRun $run, string $dimension, array $audit, array $context, array $findingCodes, string $model, ?string $reasoningEffort = null): array
    {
        $input = [
            'dimension' => $dimension,
            'audit' => $audit,
            'draft' => $context['draft'] ?? null,
            'chapter_plan' => $context['chapter_plan'] ?? null,
            'scenes' => $context['scenes'] ?? [],
            'model' => $model,
            'reasoning_effort' => $reasoningEffort,
            'prompt_version' => self::PROMPT_VERSION,
        ];
        $inputHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        if ($existing = $this->existing($run, $inputHash)) {
            return (array) data_get($existing->data, 'result', []);
        }

        $logId = (string) Str::uuid();

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $model,
                reasoningEffort: $reasoningEffort,
                systemPrompt: '你是 XNovel Review Schema 修复器。只复核指定的一个质量维度，不得修改正文、Chapter Plan、评分、其他维度或 Canonical 数据。原审计声称该维度存在问题，但 findings 没有对应项。请根据正文和计划判断：确有实质问题时返回完整、可执行且有逐字证据的 findings；没有实质问题时返回空数组，并用简体中文重写 summary。不得为了维持原 status 而编造问题。正文能够在保持 Chapter Plan、Canonical 数据和既定剧情结果不变的前提下修复时，必须设置 auto_fixable=true、requires_human_decision=false；只有答案依赖缺失的 Canonical 事实或用户必须选择的剧情方向时，才设置 requires_human_decision=true。'.NarrativeProsePolicy::reviewing(),
                prompt: '请修复以下单维度审计：'.json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: .2,
                maxTokens: (int) config('generation.review_schema_repair_max_output_tokens', 1_500),
                responseSchema: $this->schema($dimension, $findingCodes),
                promptVersion: self::PROMPT_VERSION,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $run->novel_id,
                    'chapter_id' => $run->chapter_id,
                    'stage' => 'review_schema_repair',
                    'review_dimension' => $dimension,
                    'ai_request_log_id' => $logId,
                ],
            ));
            $data = $response->structuredData;

            if (! is_array($data)
                || collect(array_keys($data))->sort()->values()->all() !== ['findings', 'summary']
                || ! is_string($data['summary'])
                || blank($data['summary'])
                || ! is_array($data['findings'])) {
                throw new \UnexpectedValueException('Review Schema Repair 返回结构无效。');
            }

            $result = [
                'status' => 'succeeded',
                'dimension' => $dimension,
                'model' => $model,
                'prompt_version' => self::PROMPT_VERSION,
                'summary' => $data['summary'],
                'findings' => $data['findings'],
                'ai_request_log_id' => $logId,
                'provider_request_id' => $response->providerRequestId,
            ];
        } catch (Throwable $exception) {
            $result = [
                'status' => 'failed',
                'dimension' => $dimension,
                'model' => $model,
                'prompt_version' => self::PROMPT_VERSION,
                'findings' => [],
                'ai_request_log_id' => $logId,
                'error_code' => $exception instanceof AiProviderException
                    ? $exception->errorCode
                    : 'review_schema_repair_failed',
                'error_message' => $exception->getMessage(),
            ];
        }

        $this->persist($run, $inputHash, $result);

        return $result;
    }

    /** @param array<string, string> $findingCodes */
    private function schema(string $dimension, array $findingCodes): array
    {
        $codes = array_keys(array_filter($findingCodes, fn (string $mapped): bool => $mapped === $dimension));

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'findings'],
            'properties' => [
                'summary' => ['type' => 'string', 'minLength' => 1],
                'findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['code', 'dimension', 'severity', 'scene_id', 'scope', 'auto_fixable', 'requires_human_decision', 'message', 'evidence'],
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => $codes],
                            'dimension' => ['type' => 'string', 'enum' => [$dimension]],
                            'severity' => ['type' => 'string', 'enum' => ['warning', 'error']],
                            'scene_id' => ['type' => ['integer', 'null']],
                            'scope' => ['type' => 'string', 'enum' => ['paragraph', 'scene', 'chapter']],
                            'auto_fixable' => ['type' => 'boolean'],
                            'requires_human_decision' => ['type' => 'boolean'],
                            'message' => ['type' => 'string', 'minLength' => 1],
                            'evidence' => ['type' => 'string', 'minLength' => 1],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function existing(GenerationRun $run, string $inputHash): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->where('type', ArtifactType::Context)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $run->chapter_id))
            ->latest('id')
            ->get()
            ->first(fn (GenerationArtifact $artifact): bool => data_get($artifact->data, 'role') === 'review_schema_repair'
                && data_get($artifact->data, 'input_hash') === $inputHash);
    }

    /** @param array<string, mixed> $result */
    private function persist(GenerationRun $run, string $inputHash, array $result): void
    {
        $data = ['role' => 'review_schema_repair', 'input_hash' => $inputHash, 'result' => $result];
        $run->artifacts()->create([
            'type' => ArtifactType::Context,
            'version' => ((int) $run->artifacts()->where('type', ArtifactType::Context)->max('version')) + 1,
            'content' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data' => $data,
            'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
    }
}
