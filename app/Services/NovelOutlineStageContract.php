<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * OGR-003 的纯合同层：定义 Provider Schema、领域校验、局部顺序与请求容量门禁。
 *
 * Job 派发、Artifact 持久化和跨 Artifact Skeleton Assembly 属于 OGR-004。
 */
final class NovelOutlineStageContract
{
    public const STRUCTURE_PROMPT_VERSION = 'novel-outline-structure-v1';

    public const ARC_BEATS_PROMPT_VERSION = 'novel-outline-arc-beats-v1';

    /** Structure 最多覆盖 12 卷，沿用旧 Skeleton 的 12K 输出上限，但不再承担 Beat 输出。 */
    public const STRUCTURE_MAX_OUTPUT_TOKENS = 12_000;

    /** Arc Beats 单次只覆盖一个 Arc；8K 与现有 Foundation 上限一致并保留候选字段容量。 */
    public const ARC_BEATS_MAX_OUTPUT_TOKENS = 8_000;

    public const MAX_VOLUME_COUNT = 12;

    public function __construct(private readonly TokenBudget $tokenBudget) {}

    /** Structure 只允许全书、Volume 与 Arc 字段；顺序由 Laravel 在校验后补入。 */
    public function structureSchema(int $volumeCount): array
    {
        $this->assertVolumeCount($volumeCount);

        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $arc = $this->object([
            'key' => ['type' => 'string', 'pattern' => '^arc-[0-9]{2,}$'],
            'type' => ['type' => 'string', 'enum' => ['main', 'subplot']],
            'title' => ['type' => 'string'],
            'goal' => ['type' => 'string'],
            'stakes' => ['type' => 'string'],
            'completion_conditions' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
        ]);
        $volume = $this->object([
            'key' => ['type' => 'string', 'pattern' => '^vol-[0-9]{2}$'],
            'title' => ['type' => 'string'],
            'goal' => ['type' => 'string'],
            'climax' => ['type' => 'string'],
            'target_words' => ['type' => 'integer', 'minimum' => 1],
            'arcs' => ['type' => 'array', 'minItems' => 1, 'items' => $arc],
        ]);

        return $this->object([
            'title' => ['type' => 'string'],
            'summary' => ['type' => 'string'],
            'must_include' => $strings,
            'must_not_include' => $strings,
            'volumes' => [
                'type' => 'array',
                'minItems' => $volumeCount,
                'maxItems' => $volumeCount,
                'items' => $volume,
            ],
        ]);
    }

    /** Arc Beats 只允许目标 Arc 的 Beat、预算、验收条件和候选。 */
    public function arcBeatsSchema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $stableKey = ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*$'];
        $candidateBase = [
            'candidate_key' => $stableKey,
            'name' => ['type' => 'string'],
            'deduplication_basis' => ['type' => 'string'],
            'introduction_reason' => ['type' => 'string'],
            'target_scene_sequence' => ['type' => 'integer', 'minimum' => 1],
        ];
        $characterCandidate = $this->object($candidateBase + [
            'role' => ['type' => 'string'],
            'motivation' => ['type' => 'string'],
            'profile' => $strings,
            'personality' => $strings,
            'abilities' => $strings,
            'knowledge' => $strings,
        ]);
        $worldCandidate = $this->object($candidateBase + [
            'type' => ['type' => 'string', 'enum' => ['location', 'item', 'faction', 'organization', 'rule', 'concept']],
            'description' => ['type' => 'string'],
        ]);
        $beat = $this->object([
            'key' => ['type' => 'string', 'pattern' => '^beat-[0-9]{2,}$'],
            'title' => ['type' => 'string'],
            'summary' => ['type' => 'string'],
            'chapter_budget' => $this->object([
                'min' => ['type' => 'integer', 'minimum' => 1],
                'max' => ['type' => ['integer', 'null'], 'minimum' => 1],
            ]),
            'acceptance_criteria' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
            'must_include' => $strings,
            'must_not_include' => $strings,
            'character_candidates' => ['type' => 'array', 'items' => $characterCandidate],
            'world_entity_candidates' => ['type' => 'array', 'items' => $worldCandidate],
        ]);

        return $this->object([
            'arc_key' => ['type' => 'string', 'pattern' => '^arc-[0-9]{2,}$'],
            'beats' => ['type' => 'array', 'minItems' => 1, 'items' => $beat],
        ]);
    }

    /**
     * 校验 Structure 严格字段和全局 Key，并按数组顺序补同级 sequence。
     *
     * @return array<string, mixed>
     */
    public function validateStructure(array $data, int $volumeCount): array
    {
        $this->assertVolumeCount($volumeCount);
        $this->assertSchemaShape($data, $this->structureSchema($volumeCount));

        $valid = Validator::make($data, [
            'title' => ['required', 'string'],
            'summary' => ['required', 'string'],
            'must_include' => ['present', 'array'],
            'must_include.*' => ['string'],
            'must_not_include' => ['present', 'array'],
            'must_not_include.*' => ['string'],
            'volumes' => ['required', 'array', 'size:'.$volumeCount],
            'volumes.*' => ['array:key,title,goal,climax,target_words,arcs'],
            'volumes.*.key' => ['required', 'string', 'regex:/^vol-[0-9]{2}$/'],
            'volumes.*.title' => ['required', 'string'],
            'volumes.*.goal' => ['required', 'string'],
            'volumes.*.climax' => ['required', 'string'],
            'volumes.*.target_words' => ['required', 'integer', 'min:1'],
            'volumes.*.arcs' => ['required', 'array', 'min:1'],
            'volumes.*.arcs.*' => ['array:key,type,title,goal,stakes,completion_conditions'],
            'volumes.*.arcs.*.key' => ['required', 'string', 'regex:/^arc-[0-9]{2,}$/'],
            'volumes.*.arcs.*.type' => ['required', Rule::in(['main', 'subplot'])],
            'volumes.*.arcs.*.title' => ['required', 'string'],
            'volumes.*.arcs.*.goal' => ['required', 'string'],
            'volumes.*.arcs.*.stakes' => ['required', 'string'],
            'volumes.*.arcs.*.completion_conditions' => ['required', 'array', 'min:1'],
            'volumes.*.arcs.*.completion_conditions.*' => ['string'],
        ])->validate();

        $volumeKeys = [];
        $arcKeys = [];
        foreach ($valid['volumes'] as $volumeIndex => &$volume) {
            $this->assertUniqueKey((string) $volume['key'], $volumeKeys, 'Volume');
            $volume['sequence'] = $volumeIndex + 1;
            foreach ($volume['arcs'] as $arcIndex => &$arc) {
                $this->assertUniqueKey((string) $arc['key'], $arcKeys, 'Arc');
                $arc['sequence'] = $arcIndex + 1;
            }
            unset($arc);
        }
        unset($volume);

        return $valid;
    }

    /**
     * 校验单个目标 Arc 的 Beats，并按响应数组顺序补局部 sequence。
     *
     * @param  array<string, mixed>  $structure  已通过 validateStructure() 的完整 Structure
     * @return array<string, mixed>
     */
    public function validateArcBeats(array $data, string $targetArcKey, array $structure): array
    {
        if ($this->findArc($structure, $targetArcKey) === null) {
            throw ValidationException::withMessages(['arc_key' => "Structure 不包含目标 Arc {$targetArcKey}。"]);
        }

        $this->assertSchemaShape($data, $this->arcBeatsSchema());

        $valid = Validator::make($data, [
            'arc_key' => ['required', 'string', 'regex:/^arc-[0-9]{2,}$/'],
            'beats' => ['required', 'array', 'min:1'],
            'beats.*' => ['array:key,title,summary,chapter_budget,acceptance_criteria,must_include,must_not_include,character_candidates,world_entity_candidates'],
            'beats.*.key' => ['required', 'string', 'regex:/^beat-[0-9]{2,}$/'],
            'beats.*.title' => ['required', 'string'],
            'beats.*.summary' => ['required', 'string'],
            'beats.*.chapter_budget' => ['required', 'array:min,max'],
            'beats.*.chapter_budget.min' => ['required', 'integer', 'min:1'],
            'beats.*.chapter_budget.max' => ['present', 'nullable', 'integer', 'min:1'],
            'beats.*.acceptance_criteria' => ['required', 'array', 'min:1'],
            'beats.*.acceptance_criteria.*' => ['string'],
            'beats.*.must_include' => ['present', 'array'],
            'beats.*.must_include.*' => ['string'],
            'beats.*.must_not_include' => ['present', 'array'],
            'beats.*.must_not_include.*' => ['string'],
            'beats.*.character_candidates' => ['present', 'array'],
            'beats.*.character_candidates.*' => ['array:candidate_key,name,deduplication_basis,introduction_reason,target_scene_sequence,role,motivation,profile,personality,abilities,knowledge'],
            'beats.*.character_candidates.*.candidate_key' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'beats.*.character_candidates.*.name' => ['required', 'string'],
            'beats.*.character_candidates.*.deduplication_basis' => ['required', 'string'],
            'beats.*.character_candidates.*.introduction_reason' => ['required', 'string'],
            'beats.*.character_candidates.*.target_scene_sequence' => ['required', 'integer', 'min:1'],
            'beats.*.character_candidates.*.role' => ['required', 'string'],
            'beats.*.character_candidates.*.motivation' => ['required', 'string'],
            'beats.*.character_candidates.*.profile' => ['present', 'array'],
            'beats.*.character_candidates.*.profile.*' => ['string'],
            'beats.*.character_candidates.*.personality' => ['present', 'array'],
            'beats.*.character_candidates.*.personality.*' => ['string'],
            'beats.*.character_candidates.*.abilities' => ['present', 'array'],
            'beats.*.character_candidates.*.abilities.*' => ['string'],
            'beats.*.character_candidates.*.knowledge' => ['present', 'array'],
            'beats.*.character_candidates.*.knowledge.*' => ['string'],
            'beats.*.world_entity_candidates' => ['present', 'array'],
            'beats.*.world_entity_candidates.*' => ['array:candidate_key,name,deduplication_basis,introduction_reason,target_scene_sequence,type,description'],
            'beats.*.world_entity_candidates.*.candidate_key' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'beats.*.world_entity_candidates.*.name' => ['required', 'string'],
            'beats.*.world_entity_candidates.*.deduplication_basis' => ['required', 'string'],
            'beats.*.world_entity_candidates.*.introduction_reason' => ['required', 'string'],
            'beats.*.world_entity_candidates.*.target_scene_sequence' => ['required', 'integer', 'min:1'],
            'beats.*.world_entity_candidates.*.type' => ['required', Rule::in(['location', 'item', 'faction', 'organization', 'rule', 'concept'])],
            'beats.*.world_entity_candidates.*.description' => ['required', 'string'],
        ])->validate();

        if ($valid['arc_key'] !== $targetArcKey) {
            throw ValidationException::withMessages(['arc_key' => "Arc Beats 必须只返回目标 Arc {$targetArcKey}。"]);
        }

        $beatKeys = [];
        $candidateKeys = [];
        foreach ($valid['beats'] as $beatIndex => &$beat) {
            $this->assertUniqueKey((string) $beat['key'], $beatKeys, 'Beat');
            $beat['sequence'] = $beatIndex + 1;

            $minimum = (int) $beat['chapter_budget']['min'];
            $maximum = $beat['chapter_budget']['max'];
            if ($maximum !== null && (int) $maximum < $minimum) {
                throw ValidationException::withMessages(['beats' => 'Beat chapter_budget.max 不能小于 min。']);
            }

            foreach ([...$beat['character_candidates'], ...$beat['world_entity_candidates']] as $candidate) {
                $this->assertUniqueKey((string) $candidate['candidate_key'], $candidateKeys, 'Candidate');
            }
        }
        unset($beat);

        return $valid;
    }

    /**
     * 构建单 Arc 的最小输入：Foundation 摘要、完整 Structure、目标 Arc 与相邻 Arc 摘要。
     * API 不接收历史 Arc Beats，因此不会把已生成 Beat 累积回每次 Prompt。
     *
     * @return array<string, mixed>
     */
    public function arcBeatsContext(array $foundation, array $structure, string $targetArcKey): array
    {
        $arcs = $this->flattenArcs($structure);
        $targetIndex = array_search($targetArcKey, array_column($arcs, 'key'), true);
        if ($targetIndex === false) {
            throw ValidationException::withMessages(['arc_key' => "Structure 不包含目标 Arc {$targetArcKey}。"]);
        }

        return [
            'foundation_summary' => $this->foundationSummary($foundation),
            'structure' => $structure,
            'target_arc' => $arcs[$targetIndex],
            'adjacent_arcs' => [
                'previous' => $targetIndex > 0 ? $this->arcSummary($arcs[$targetIndex - 1]) : null,
                'next' => $targetIndex < count($arcs) - 1 ? $this->arcSummary($arcs[$targetIndex + 1]) : null,
            ],
        ];
    }

    /**
     * 以完整请求输入估算容量；调用方必须提供冻结模型的真实上下文窗口和最大合法输出。
     *
     * @return array{estimated_input_tokens: int, requested_output_tokens: int, context_window_tokens: int, model_max_output_tokens: int, remaining_context_tokens: int}
     */
    public function capacitySnapshot(
        array $requestInput,
        int $requestedOutputTokens,
        int $contextWindowTokens,
        int $modelMaxOutputTokens,
    ): array {
        if ($requestedOutputTokens < 1 || $contextWindowTokens < 1 || $modelMaxOutputTokens < 1) {
            throw ValidationException::withMessages(['capacity' => '模型容量和请求输出 Token 必须为正整数。']);
        }

        $estimatedInput = $this->tokenBudget->estimate($requestInput);
        $remainingContext = max(0, $contextWindowTokens - $estimatedInput);
        $maxLegalOutput = min($modelMaxOutputTokens, $remainingContext);
        if ($requestedOutputTokens > $maxLegalOutput) {
            throw ValidationException::withMessages([
                'capacity' => "请求输出 {$requestedOutputTokens} Token 超过当前输入下的最大合法输出 {$maxLegalOutput} Token。",
            ]);
        }

        return [
            'estimated_input_tokens' => $estimatedInput,
            'requested_output_tokens' => $requestedOutputTokens,
            'context_window_tokens' => $contextWindowTokens,
            'model_max_output_tokens' => $modelMaxOutputTokens,
            'remaining_context_tokens' => $remainingContext,
        ];
    }

    /** @return array<string, mixed> */
    private function foundationSummary(array $foundation): array
    {
        return [
            'bible' => Arr::only((array) ($foundation['bible'] ?? []), [
                'logline', 'themes', 'tone', 'pov', 'tense', 'taboos', 'hard_constraints', 'ending_contract', 'style_profile',
            ]),
            'characters' => collect($foundation['characters'] ?? [])->map(fn (array $character): array => Arr::only(
                $character,
                ['name', 'role', 'motivation', 'current_state'],
            ))->values()->all(),
            'world_entities' => collect($foundation['world_entities'] ?? [])->map(fn (array $entity): array => Arr::only(
                $entity,
                ['type', 'name', 'description', 'rules', 'current_state'],
            ))->values()->all(),
            'foreshadowings' => collect($foundation['foreshadowings'] ?? [])->map(fn (array $item): array => Arr::only(
                $item,
                ['title', 'description', 'promised_payoff', 'importance', 'owner_arc_key'],
            ))->values()->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function flattenArcs(array $structure): array
    {
        $arcs = [];
        foreach ($structure['volumes'] ?? [] as $volume) {
            foreach ($volume['arcs'] ?? [] as $arc) {
                $arcs[] = [
                    'volume_key' => $volume['key'],
                    'volume_sequence' => $volume['sequence'] ?? null,
                    ...$arc,
                ];
            }
        }

        return $arcs;
    }

    /** @return array<string, mixed>|null */
    private function findArc(array $structure, string $arcKey): ?array
    {
        return collect($this->flattenArcs($structure))->firstWhere('key', $arcKey);
    }

    /** @return array<string, mixed> */
    private function arcSummary(array $arc): array
    {
        return Arr::only($arc, [
            'volume_key', 'volume_sequence', 'key', 'sequence', 'type', 'title', 'goal', 'stakes', 'completion_conditions',
        ]);
    }

    /** @param array<string, true> $keys */
    private function assertUniqueKey(string $key, array &$keys, string $type): void
    {
        if (isset($keys[$key])) {
            throw ValidationException::withMessages(['outline' => "{$type} Key 重复：{$key}"]);
        }

        $keys[$key] = true;
    }

    private function assertVolumeCount(int $volumeCount): void
    {
        if ($volumeCount < 1 || $volumeCount > self::MAX_VOLUME_COUNT) {
            throw ValidationException::withMessages(['volume_count' => '预计分卷数必须在 1 至 12 之间。']);
        }
    }

    /** 在领域校验前拒绝 Provider 输出的缺失字段和所有层级额外字段。 */
    private function assertSchemaShape(mixed $value, array $schema, string $path = '$'): void
    {
        $type = $schema['type'] ?? null;
        if ($type === 'object' && is_array($value)) {
            $allowed = array_keys($schema['properties'] ?? []);
            $extra = array_diff(array_keys($value), $allowed);
            $missing = array_diff($schema['required'] ?? [], array_keys($value));
            if ($extra !== [] || $missing !== []) {
                throw ValidationException::withMessages([$path => 'Structured Output 字段与严格 Schema 不一致。']);
            }

            foreach ($schema['properties'] as $key => $child) {
                $this->assertSchemaShape($value[$key], $child, $path.'.'.$key);
            }

            return;
        }

        if ($type === 'array' && is_array($value) && is_array($schema['items'] ?? null)) {
            foreach ($value as $index => $item) {
                $this->assertSchemaShape($item, $schema['items'], $path.'.'.$index);
            }
        }
    }

    /** 构造 required/property 完全对齐的 Strict Structured Output 对象。 */
    private function object(array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }
}
