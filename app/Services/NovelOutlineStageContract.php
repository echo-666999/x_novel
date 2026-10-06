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

    public const ARC_BEATS_PROMPT_VERSION = 'novel-outline-arc-beats-v2';

    public const MAX_VOLUME_COUNT = 12;

    public const MAX_SHORT_TEXT_LENGTH = 255;

    public const MAX_TEXT_LENGTH = 2_000;

    public const MAX_LIST_ITEM_LENGTH = 1_000;

    public const MAX_LIST_ITEMS = 24;

    public const MAX_ARCS_PER_VOLUME = 24;

    public const MAX_BEATS_PER_ARC = 48;

    public const MAX_CANDIDATES_PER_BEAT = 16;

    public const MAX_MILESTONES_PER_BEAT = 24;

    public const MAX_FOUNDATION_CHARACTERS = 32;

    public const MAX_FOUNDATION_WORLD_ENTITIES = 64;

    public const MAX_FOUNDATION_FORESHADOWINGS = 64;

    public function __construct(private readonly TokenBudget $tokenBudget) {}

    /** Structure 只允许全书、Volume 与 Arc 字段；顺序由 Laravel 在校验后补入。 */
    public function structureSchema(int $volumeCount): array
    {
        $this->assertVolumeCount($volumeCount);

        $strings = $this->stringList();
        $arc = $this->object([
            'key' => ['type' => 'string', 'pattern' => '^arc-[0-9]{2,}$', 'maxLength' => 64],
            'type' => ['type' => 'string', 'enum' => ['main', 'subplot'], 'maxLength' => self::MAX_SHORT_TEXT_LENGTH],
            'title' => $this->shortText(),
            'goal' => $this->text(),
            'stakes' => $this->text(),
            'completion_conditions' => $this->stringList(minItems: 1),
        ]);
        $volume = $this->object([
            'key' => ['type' => 'string', 'pattern' => '^vol-[0-9]{2}$', 'maxLength' => 16],
            'title' => $this->shortText(),
            'goal' => $this->text(),
            'climax' => $this->text(),
            'target_words' => ['type' => 'integer', 'minimum' => 1],
            'arcs' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_ARCS_PER_VOLUME, 'items' => $arc],
        ]);

        return $this->object([
            'title' => $this->shortText(),
            'summary' => $this->text(),
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
        $strings = $this->stringList();
        $candidateBase = [
            'name' => $this->shortText(),
            'deduplication_basis' => $this->text(),
            'introduction_reason' => $this->text(),
            'target_scene_sequence' => ['type' => 'integer', 'minimum' => 1],
        ];
        $characterCandidate = $this->object($candidateBase + [
            'role' => $this->shortText(),
            'motivation' => $this->text(),
            'profile' => $strings,
            'personality' => $strings,
            'abilities' => $strings,
            'knowledge' => $strings,
        ]);
        $worldCandidate = $this->object($candidateBase + [
            'type' => ['type' => 'string', 'enum' => ['location', 'item', 'faction', 'organization', 'rule', 'concept'], 'maxLength' => self::MAX_SHORT_TEXT_LENGTH],
            'description' => $this->text(),
        ]);
        $beat = $this->object([
            'title' => $this->shortText(),
            'summary' => $this->text(),
            'chapter_budget' => $this->object([
                'min' => ['type' => 'integer', 'minimum' => 1],
                'max' => ['type' => ['integer', 'null'], 'minimum' => 1],
            ]),
            'acceptance_criteria' => $this->stringList(minItems: 1),
            'must_include' => $strings,
            'must_not_include' => $strings,
            'character_candidates' => ['type' => 'array', 'maxItems' => self::MAX_CANDIDATES_PER_BEAT, 'items' => $characterCandidate],
            'world_entity_candidates' => ['type' => 'array', 'maxItems' => self::MAX_CANDIDATES_PER_BEAT, 'items' => $worldCandidate],
        ]);

        return $this->object([
            'arc_key' => ['type' => 'string', 'pattern' => '^arc-[0-9]{2,}$', 'maxLength' => 64],
            'beats' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_BEATS_PER_ARC, 'items' => $beat],
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
            'title' => ['required', 'string', 'max:'.self::MAX_SHORT_TEXT_LENGTH],
            'summary' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            'must_include' => ['present', 'array', 'max:'.self::MAX_LIST_ITEMS],
            'must_include.*' => ['string', 'max:'.self::MAX_LIST_ITEM_LENGTH],
            'must_not_include' => ['present', 'array', 'max:'.self::MAX_LIST_ITEMS],
            'must_not_include.*' => ['string', 'max:'.self::MAX_LIST_ITEM_LENGTH],
            'volumes' => ['required', 'array', 'size:'.$volumeCount],
            'volumes.*' => ['array:key,title,goal,climax,target_words,arcs'],
            'volumes.*.key' => ['required', 'string', 'max:16', 'regex:/^vol-[0-9]{2}$/'],
            'volumes.*.title' => ['required', 'string', 'max:'.self::MAX_SHORT_TEXT_LENGTH],
            'volumes.*.goal' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            'volumes.*.climax' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            'volumes.*.target_words' => ['required', 'integer', 'min:1'],
            'volumes.*.arcs' => ['required', 'array', 'min:1', 'max:'.self::MAX_ARCS_PER_VOLUME],
            'volumes.*.arcs.*' => ['array:key,type,title,goal,stakes,completion_conditions'],
            'volumes.*.arcs.*.key' => ['required', 'string', 'max:64', 'regex:/^arc-[0-9]{2,}$/'],
            'volumes.*.arcs.*.type' => ['required', Rule::in(['main', 'subplot'])],
            'volumes.*.arcs.*.title' => ['required', 'string', 'max:'.self::MAX_SHORT_TEXT_LENGTH],
            'volumes.*.arcs.*.goal' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            'volumes.*.arcs.*.stakes' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            'volumes.*.arcs.*.completion_conditions' => ['required', 'array', 'min:1', 'max:'.self::MAX_LIST_ITEMS],
            'volumes.*.arcs.*.completion_conditions.*' => ['string', 'max:'.self::MAX_LIST_ITEM_LENGTH],
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
     * Beat 与 Candidate 的全书稳定 Key 由 Skeleton Assembly 统一分配；隔离的 Provider
     * 请求没有其他 Arc 的输出，不能安全地产生全书唯一标识。
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
            'arc_key' => ['required', 'string', 'max:64', 'regex:/^arc-[0-9]{2,}$/'],
            'beats' => ['required', 'array', 'min:1', 'max:'.self::MAX_BEATS_PER_ARC],
            'beats.*' => ['array:title,summary,chapter_budget,acceptance_criteria,must_include,must_not_include,character_candidates,world_entity_candidates'],
            'beats.*.title' => ['required', 'string', 'max:'.self::MAX_SHORT_TEXT_LENGTH],
            'beats.*.summary' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            'beats.*.chapter_budget' => ['required', 'array:min,max'],
            'beats.*.chapter_budget.min' => ['required', 'integer', 'min:1'],
            'beats.*.chapter_budget.max' => ['present', 'nullable', 'integer', 'min:1'],
            'beats.*.acceptance_criteria' => ['required', 'array', 'min:1', 'max:'.self::MAX_LIST_ITEMS],
            'beats.*.acceptance_criteria.*' => ['string', 'max:'.self::MAX_LIST_ITEM_LENGTH],
            'beats.*.must_include' => ['present', 'array', 'max:'.self::MAX_LIST_ITEMS],
            'beats.*.must_include.*' => ['string', 'max:'.self::MAX_LIST_ITEM_LENGTH],
            'beats.*.must_not_include' => ['present', 'array', 'max:'.self::MAX_LIST_ITEMS],
            'beats.*.must_not_include.*' => ['string', 'max:'.self::MAX_LIST_ITEM_LENGTH],
            'beats.*.character_candidates' => ['present', 'array', 'max:'.self::MAX_CANDIDATES_PER_BEAT],
            'beats.*.character_candidates.*' => ['array:name,deduplication_basis,introduction_reason,target_scene_sequence,role,motivation,profile,personality,abilities,knowledge'],
            'beats.*.character_candidates.*.name' => ['required', 'string', 'max:'.self::MAX_SHORT_TEXT_LENGTH],
            'beats.*.character_candidates.*.deduplication_basis' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            'beats.*.character_candidates.*.introduction_reason' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            'beats.*.character_candidates.*.target_scene_sequence' => ['required', 'integer', 'min:1'],
            'beats.*.character_candidates.*.role' => ['required', 'string', 'max:'.self::MAX_SHORT_TEXT_LENGTH],
            'beats.*.character_candidates.*.motivation' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            'beats.*.character_candidates.*.profile' => ['present', 'array', 'max:'.self::MAX_LIST_ITEMS],
            'beats.*.character_candidates.*.profile.*' => ['string', 'max:'.self::MAX_LIST_ITEM_LENGTH],
            'beats.*.character_candidates.*.personality' => ['present', 'array', 'max:'.self::MAX_LIST_ITEMS],
            'beats.*.character_candidates.*.personality.*' => ['string', 'max:'.self::MAX_LIST_ITEM_LENGTH],
            'beats.*.character_candidates.*.abilities' => ['present', 'array', 'max:'.self::MAX_LIST_ITEMS],
            'beats.*.character_candidates.*.abilities.*' => ['string', 'max:'.self::MAX_LIST_ITEM_LENGTH],
            'beats.*.character_candidates.*.knowledge' => ['present', 'array', 'max:'.self::MAX_LIST_ITEMS],
            'beats.*.character_candidates.*.knowledge.*' => ['string', 'max:'.self::MAX_LIST_ITEM_LENGTH],
            'beats.*.world_entity_candidates' => ['present', 'array', 'max:'.self::MAX_CANDIDATES_PER_BEAT],
            'beats.*.world_entity_candidates.*' => ['array:name,deduplication_basis,introduction_reason,target_scene_sequence,type,description'],
            'beats.*.world_entity_candidates.*.name' => ['required', 'string', 'max:'.self::MAX_SHORT_TEXT_LENGTH],
            'beats.*.world_entity_candidates.*.deduplication_basis' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            'beats.*.world_entity_candidates.*.introduction_reason' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            'beats.*.world_entity_candidates.*.target_scene_sequence' => ['required', 'integer', 'min:1'],
            'beats.*.world_entity_candidates.*.type' => ['required', Rule::in(['location', 'item', 'faction', 'organization', 'rule', 'concept'])],
            'beats.*.world_entity_candidates.*.description' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
        ])->validate();

        if ($valid['arc_key'] !== $targetArcKey) {
            throw ValidationException::withMessages(['arc_key' => "Arc Beats 必须只返回目标 Arc {$targetArcKey}。"]);
        }

        foreach ($valid['beats'] as $beatIndex => &$beat) {
            $beat['sequence'] = $beatIndex + 1;

            $minimum = (int) $beat['chapter_budget']['min'];
            $maximum = $beat['chapter_budget']['max'];
            if ($maximum !== null && (int) $maximum < $minimum) {
                throw ValidationException::withMessages(['beats' => 'Beat chapter_budget.max 不能小于 min。']);
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
     * @return array{estimated_input_tokens: int, output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int, context_window_tokens: int, model_max_output_tokens: int, remaining_context_tokens: int}
     */
    public function capacitySnapshot(
        array $requestInput,
        int $outputTokens,
        int $reasoningReserveTokens,
        int $contextWindowTokens,
        int $modelMaxOutputTokens,
    ): array {
        if ($outputTokens < 1 || $reasoningReserveTokens < 0 || $contextWindowTokens < 1 || $modelMaxOutputTokens < 1) {
            throw ValidationException::withMessages(['capacity' => '模型容量、输出 Token 和推理预留必须是有效整数。']);
        }

        $estimatedInput = $this->tokenBudget->estimate($requestInput);
        $remainingContext = max(0, $contextWindowTokens - $estimatedInput);
        $maxLegalOutput = min($modelMaxOutputTokens, $remainingContext);
        $maxCompletionTokens = $outputTokens + $reasoningReserveTokens;
        if ($maxCompletionTokens > $maxLegalOutput) {
            throw ValidationException::withMessages([
                'capacity' => "请求完成预算 {$maxCompletionTokens} Token（结构化输出 {$outputTokens} + 推理预留 {$reasoningReserveTokens}）超过当前输入下的最大合法输出 {$maxLegalOutput} Token。",
            ]);
        }

        return [
            'estimated_input_tokens' => $estimatedInput,
            'output_tokens' => $outputTokens,
            'reasoning_reserve_tokens' => $reasoningReserveTokens,
            'max_completion_tokens' => $maxCompletionTokens,
            'context_window_tokens' => $contextWindowTokens,
            'model_max_output_tokens' => $modelMaxOutputTokens,
            'remaining_context_tokens' => $remainingContext,
        ];
    }

    /** @return array{type: string, maxLength: int} */
    private function shortText(): array
    {
        return ['type' => 'string', 'maxLength' => self::MAX_SHORT_TEXT_LENGTH];
    }

    /** @return array{type: string, maxLength: int} */
    private function text(): array
    {
        return ['type' => 'string', 'maxLength' => self::MAX_TEXT_LENGTH];
    }

    /** @return array<string, mixed> */
    private function stringList(int $minItems = 0): array
    {
        return array_filter([
            'type' => 'array',
            'minItems' => $minItems > 0 ? $minItems : null,
            'maxItems' => self::MAX_LIST_ITEMS,
            'items' => ['type' => 'string', 'maxLength' => self::MAX_LIST_ITEM_LENGTH],
        ], static fn (mixed $value): bool => $value !== null);
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
