<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use Illuminate\Validation\ValidationException;

final class SceneDraftStructureRepairer
{
    public const PROMPT_VERSION = 'scene-support-fields-repair-v1';

    public function __construct(private readonly AiProvider $provider) {}

    /**
     * Repair only the machine-readable support fields. The prose and Coverage stay unchanged.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     * @return array{temporary_state_delta: array<string, mixed>, declared_events: array<int, array<string, mixed>>}
     */
    public function repair(array $payload, string $model, array $metadata, mixed $sceneTask, ?string $reasoningEffort = null): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= (int) config('generation.max_scene_structure_repair_attempts', 2); $attempt++) {
            $maxTokens = $attempt === 1
                ? (int) config('generation.scene_structure_repair_max_output_tokens', 1_000)
                : (int) config('generation.scene_structure_repair_retry_max_output_tokens', 4_000);
            $response = $this->provider->generate(new AiRequest(
                model: $model,
                reasoningEffort: $reasoningEffort,
                systemPrompt: '你是 XNovel 场景辅助字段修复器。不得改写正文、Coverage、场景结果或既定事实。只修复 temporary_state_delta 与 declared_events 的 JSON 语法和对象结构，不得引入输入之外的新信息。temporary_state_delta 必须是 JSON 对象字符串；无法可靠结构化时返回 {}。declared_events 的每一项必须是 JSON 对象字符串；无法可靠结构化的项应删除。只返回符合 Schema 的两个字段。',
                prompt: '请修复以下场景辅助字段：'.json_encode([
                    'scene_task' => $sceneTask,
                    'content' => is_string($payload['content'] ?? null) ? $payload['content'] : '',
                    'temporary_state_delta' => $payload['temporary_state_delta'] ?? null,
                    'declared_events' => $payload['declared_events'] ?? null,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.1,
                maxTokens: $maxTokens,
                responseSchema: self::schema(),
                promptVersion: self::PROMPT_VERSION,
                metadata: [...$metadata, 'scene_structure_repair_attempt' => $attempt],
            ));

            if ($response->structuredData === null) {
                $lastException = data_get($response->metadata, 'finish_reason') === 'length'
                    ? new AiProviderException(
                        'scene_structure_repair_output_truncated',
                        "场景辅助字段修复第 {$attempt} 次响应因输出 Token 用尽而被截断。",
                        false,
                    )
                    : new AiProviderException(
                        'scene_structure_repair_schema_invalid',
                        "场景辅助字段修复第 {$attempt} 次响应没有返回可解析的结构化结果。",
                        false,
                    );

                continue;
            }

            try {
                if (! self::hasExactKeys($response->structuredData)) {
                    throw ValidationException::withMessages([
                        'scene_support_fields' => '场景辅助字段修复结果必须只包含 temporary_state_delta 和 declared_events。',
                    ]);
                }

                return SceneDraftPayload::validateMachineFields(
                    $response->structuredData['temporary_state_delta'] ?? null,
                    $response->structuredData['declared_events'] ?? null,
                );
            } catch (ValidationException $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? new AiProviderException(
            'scene_structure_repair_failed',
            '场景辅助字段自动修复失败。',
            false,
        );
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['temporary_state_delta', 'declared_events'],
            'properties' => [
                'temporary_state_delta' => [
                    'type' => 'string',
                    'description' => 'A valid JSON object string. Use {} when reliable structured state is unavailable.',
                ],
                'declared_events' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'description' => 'One declared event encoded as a valid JSON object.',
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function hasExactKeys(array $payload): bool
    {
        $actual = array_keys($payload);
        $expected = ['temporary_state_delta', 'declared_events'];
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
