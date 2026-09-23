<?php

namespace App\Services;

use App\Enums\WorldEntityType;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use Illuminate\Validation\ValidationException;

class PlanningReviewAudit
{
    /** @return array<string, array<string, mixed>> */
    public static function schema(): array
    {
        $evidence = ['type' => ['string', 'null']];

        return [
            'arc_beat_audits' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['arc_id', 'beat_key', 'status', 'evidence', 'scene_id'],
                'properties' => [
                    'arc_id' => ['type' => 'integer', 'minimum' => 1],
                    'beat_key' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['fulfilled', 'missing', 'contradicted']],
                    'evidence' => $evidence,
                    'scene_id' => ['type' => ['integer', 'null']],
                ],
            ]],
            'arc_completion_audits' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['arc_id', 'status', 'evidence'],
                'properties' => [
                    'arc_id' => ['type' => 'integer', 'minimum' => 1],
                    'status' => ['type' => 'string', 'enum' => ['fulfilled', 'not_met']],
                    'evidence' => $evidence,
                ],
            ]],
            'world_entity_candidate_audits' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['candidate_key', 'status', 'evidence', 'scene_id'],
                'properties' => [
                    'candidate_key' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['introduced', 'missing', 'contradicted']],
                    'evidence' => $evidence,
                    'scene_id' => ['type' => ['integer', 'null']],
                ],
            ]],
            'character_candidate_audits' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['candidate_key', 'status', 'evidence', 'scene_id'],
                'properties' => [
                    'candidate_key' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['introduced', 'missing', 'contradicted']],
                    'evidence' => $evidence,
                    'scene_id' => ['type' => ['integer', 'null']],
                ],
            ]],
            'unapproved_world_entities' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['name', 'type', 'evidence', 'scene_id'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => array_column(WorldEntityType::cases(), 'value')],
                    'evidence' => ['type' => 'string'],
                    'scene_id' => ['type' => ['integer', 'null']],
                ],
            ]],
            'unapproved_characters' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['name', 'evidence', 'scene_id'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'evidence' => ['type' => 'string'],
                    'scene_id' => ['type' => ['integer', 'null']],
                ],
            ]],
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function validate(array $payload, Chapter $chapter, GenerationArtifact $draft): array
    {
        $plan = $chapter->latestPlan;
        if ($plan === null) {
            throw ValidationException::withMessages(['planning_audits' => '规划验收缺少 Chapter Plan。']);
        }

        $sceneIds = $chapter->scenes->keyBy('sequence')->map->getKey();
        $payload['arc_beat_audits'] = $this->validateContractAudits(
            $payload['arc_beat_audits'] ?? null,
            $plan->arc_contributions ?? [],
            'arc_id',
            'beat_key',
            $sceneIds->all(),
            $draft,
            ['fulfilled', 'missing', 'contradicted'],
            'status',
        );
        $payload['character_candidate_audits'] = $this->validateContractAudits(
            $payload['character_candidate_audits'] ?? null,
            $plan->character_candidates ?? [],
            null,
            'candidate_key',
            $sceneIds->all(),
            $draft,
            ['introduced', 'missing', 'contradicted'],
            'status',
        );
        $payload['world_entity_candidate_audits'] = $this->validateContractAudits(
            $payload['world_entity_candidate_audits'] ?? null,
            $plan->world_entity_candidates ?? [],
            null,
            'candidate_key',
            $sceneIds->all(),
            $draft,
            ['introduced', 'missing', 'contradicted'],
            'status',
        );

        $arcIds = collect($plan->arc_contributions ?? [])->pluck('arc_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $completionAudits = collect($payload['arc_completion_audits'] ?? null);
        if (! is_array($payload['arc_completion_audits'] ?? null)
            || $completionAudits->pluck('arc_id')->map(fn ($id): int => (int) $id)->values()->all() !== $arcIds->all()) {
            throw ValidationException::withMessages(['arc_completion_audits' => 'Arc Completion 验收必须按本章 Arc 顺序完整返回。']);
        }
        foreach ($completionAudits as $audit) {
            if (! is_array($audit) || ! $this->hasExactKeys($audit, ['arc_id', 'status', 'evidence'])
                || ! in_array($audit['status'], ['fulfilled', 'not_met'], true)) {
                throw ValidationException::withMessages(['arc_completion_audits' => 'Arc Completion 验收结构无效。']);
            }
            $this->validateEvidence($audit['status'] === 'fulfilled', $audit['evidence'], $draft, 'arc_completion_audits');
        }

        if (! is_array($payload['unapproved_world_entities'] ?? null)) {
            throw ValidationException::withMessages(['unapproved_world_entities' => '未批准世界实体检查必须返回数组。']);
        }
        foreach ($payload['unapproved_world_entities'] as $candidate) {
            if (! is_array($candidate)
                || ! $this->hasExactKeys($candidate, ['name', 'type', 'evidence', 'scene_id'])
                || WorldEntityType::tryFrom((string) ($candidate['type'] ?? '')) === null
                || ! is_string($candidate['name'] ?? null) || blank($candidate['name'])
                || ! is_string($candidate['evidence'] ?? null) || ! str_contains((string) $draft->content, $candidate['evidence'])
                || ($candidate['scene_id'] !== null && ! in_array($candidate['scene_id'], $sceneIds->all(), true))) {
                throw ValidationException::withMessages(['unapproved_world_entities' => '未批准世界实体必须提供合法类型、Scene 和正文逐字证据。']);
            }
        }
        if (! is_array($payload['unapproved_characters'] ?? null)) {
            throw ValidationException::withMessages(['unapproved_characters' => '未批准人物检查必须返回数组。']);
        }
        foreach ($payload['unapproved_characters'] as $candidate) {
            if (! is_array($candidate)
                || ! $this->hasExactKeys($candidate, ['name', 'evidence', 'scene_id'])
                || ! is_string($candidate['name'] ?? null) || blank($candidate['name'])
                || ! is_string($candidate['evidence'] ?? null) || ! str_contains((string) $draft->content, $candidate['evidence'])
                || ($candidate['scene_id'] !== null && ! in_array($candidate['scene_id'], $sceneIds->all(), true))) {
                throw ValidationException::withMessages(['unapproved_characters' => '未批准人物必须提供合法名称、Scene 和正文逐字证据。']);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
    public function findings(array $payload): array
    {
        $findings = [];
        foreach ($payload['arc_beat_audits'] as $audit) {
            if ($audit['status'] !== 'fulfilled') {
                $findings[] = $this->finding('ARC_BEAT_NOT_FULFILLED', 'progress', $audit['scene_id'], '声明的 Story Arc Beat 未被正文验收，不会计入 Canonical Progress。', $audit['evidence']);
            }
        }
        foreach ($payload['character_candidate_audits'] as $audit) {
            if ($audit['status'] !== 'introduced') {
                $findings[] = $this->finding('CHARACTER_CANDIDATE_NOT_INTRODUCED', 'plan', $audit['scene_id'], '计划中的人物候选未在正文中完成引入，不会创建正式人物。', $audit['evidence']);
            }
        }
        foreach ($payload['world_entity_candidate_audits'] as $audit) {
            if ($audit['status'] !== 'introduced') {
                $findings[] = $this->finding('WORLD_ENTITY_CANDIDATE_NOT_INTRODUCED', 'plan', $audit['scene_id'], '计划中的世界实体候选未在正文中完成引入，不会创建正式实体。', $audit['evidence']);
            }
        }
        foreach ($payload['unapproved_world_entities'] as $entity) {
            $findings[] = $this->finding('UNAPPROVED_WORLD_ENTITY', 'plan', $entity['scene_id'], "正文引入了未在 Chapter Plan 批准的重大世界实体「{$entity['name']}」。", $entity['evidence'], true);
        }
        foreach ($payload['unapproved_characters'] as $character) {
            $findings[] = $this->finding('UNAPPROVED_CHARACTER', 'plan', $character['scene_id'], "正文引入了未在 Chapter Plan 批准的持续性人物「{$character['name']}」。", $character['evidence'], true);
        }

        return $findings;
    }

    /**
     * @param  array<int, mixed>  $contracts
     * @param  array<int, int>  $sceneIds
     * @param  array<int, string>  $statuses
     * @return array<int, array<string, mixed>>
     */
    private function validateContractAudits(mixed $audits, array $contracts, ?string $parentKey, string $key, array $sceneIds, GenerationArtifact $draft, array $statuses, string $statusKey): array
    {
        if (! is_array($audits) || count($audits) !== count($contracts)) {
            throw ValidationException::withMessages([$key => '规划契约验收必须逐项完整返回。']);
        }

        foreach (array_values($contracts) as $index => $contract) {
            $audit = $audits[$index] ?? null;
            $expectedSceneId = $sceneIds[(int) ($contract['target_scene_sequence'] ?? 0)] ?? null;
            $expectedKeys = array_values(array_filter([$parentKey, $key, $statusKey, 'evidence', 'scene_id']));
            if (! is_array($audit) || ! $this->hasExactKeys($audit, $expectedKeys)
                || ($parentKey !== null && (int) ($audit[$parentKey] ?? 0) !== (int) ($contract[$parentKey] ?? 0))
                || ($audit[$key] ?? null) !== ($contract[$key] ?? null)
                || ! in_array($audit[$statusKey] ?? null, $statuses, true)
                || ($audit['scene_id'] ?? null) !== $expectedSceneId) {
                throw ValidationException::withMessages([$key => '规划契约验收的标识、顺序或目标 Scene 不一致。']);
            }
            $fulfilled = in_array($audit[$statusKey], ['fulfilled', 'introduced'], true);
            if ($fulfilled || $audit[$statusKey] === 'contradicted') {
                $audit['evidence'] = $this->normalizeQuotedEvidence($audit['evidence'] ?? null, (string) $draft->content);
                $audits[$index] = $audit;
            }
            $this->validateEvidence($fulfilled || $audit[$statusKey] === 'contradicted', $audit['evidence'] ?? null, $draft, $key);
        }

        return $audits;
    }

    private function normalizeQuotedEvidence(mixed $evidence, string $draft): mixed
    {
        if (! is_string($evidence) || str_contains($draft, $evidence)) {
            return $evidence;
        }

        $trimmed = trim($evidence, " \t\n\r\0\x0B\"'“”‘’");
        if ($trimmed !== '' && str_contains($draft, $trimmed)) {
            return $trimmed;
        }

        $candidates = preg_split('/(?:……|…{1,}|\.{3,}|[；;])/u', $trimmed) ?: [];

        return collect($candidates)
            ->map(fn (string $candidate): string => trim($candidate, " \t\n\r\0\x0B\"'“”‘’"))
            ->filter(fn (string $candidate): bool => $candidate !== '' && str_contains($draft, $candidate))
            ->sortByDesc(fn (string $candidate): int => mb_strlen($candidate))
            ->first() ?? $evidence;
    }

    private function validateEvidence(bool $required, mixed $evidence, GenerationArtifact $draft, string $field): void
    {
        if ($required && (! is_string($evidence) || blank($evidence) || ! str_contains((string) $draft->content, $evidence))) {
            throw ValidationException::withMessages([$field => '已完成或冲突的验收必须提供正文逐字证据。']);
        }
        if (! $required && $evidence !== null) {
            throw ValidationException::withMessages([$field => '未完成验收的 evidence 必须为 null。']);
        }
    }

    /** @param array<string, mixed> $value @param array<int, string> $keys */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    /** @return array<string, mixed> */
    private function finding(string $code, string $dimension, ?int $sceneId, string $message, ?string $evidence, bool $human = false): array
    {
        return [
            'code' => $code,
            'dimension' => $dimension,
            'severity' => 'error',
            'scene_id' => $sceneId,
            'scope' => $sceneId === null ? 'chapter' : 'scene',
            'auto_fixable' => ! $human,
            'requires_human_decision' => $human,
            'message' => $message,
            'evidence' => $evidence ?? '正文未提供满足验收条件的证据。',
            'source' => 'planning_contract_review',
        ];
    }
}
