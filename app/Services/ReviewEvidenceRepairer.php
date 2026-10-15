<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\StructuredOutput;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use Illuminate\Support\Facades\DB;
use Throwable;

/** 只修复审校引用的正文证据，不允许改动模型已经给出的质量判断。 */
final class ReviewEvidenceRepairer
{
    public const PROMPT_VERSION = 'review-evidence-repair-v1';

    private const AUDIT_FIELDS = [
        'chapter_plan_completion', 'milestone_completion', 'beat_exit', 'handoff_readiness',
        'arc_beat_audits', 'arc_completion_audits', 'character_candidate_audits', 'world_entity_candidate_audits',
    ];

    public function __construct(
        private readonly AiProvider $provider,
        private readonly GenerationOutputCapacityGuard $outputCapacity,
        private readonly GenerationFailurePolicy $failurePolicy,
    ) {}

    /** @param array<string, mixed> $payload @param array<string, mixed> $context @return array<int, array<string, mixed>> */
    public function invalidAudits(array $payload, GenerationArtifact $draft, array $context, ?Chapter $chapter = null): array
    {
        $invalid = [];
        $chapter?->loadMissing('scenes.currentArtifact');
        foreach (self::AUDIT_FIELDS as $field) {
            $audits = $field === 'chapter_plan_completion' ? [$payload[$field] ?? null] : ($payload[$field] ?? []);
            if (! is_array($audits)) {
                continue;
            }
            foreach ($audits as $index => $audit) {
                if (! is_array($audit) || ! in_array($audit['status'] ?? null, ['fulfilled', 'introduced', 'contradicted'], true)) {
                    continue;
                }
                $evidence = $audit['evidence'] ?? null;
                $normalized = is_string($evidence)
                    ? PlanCoverage::resolveRepresentativeExactEvidence((string) $draft->content, $evidence)
                    : null;
                $targetSequence = $chapter === null ? null : $this->targetSceneSequence($field, (int) $index, $context);
                $targetContent = $targetSequence === null ? null : $chapter?->scenes
                    ->firstWhere('sequence', $targetSequence)?->currentArtifact?->content;
                $source = $targetSequence === null ? (string) $draft->content : (string) $targetContent;
                if (is_string($normalized) && $normalized !== '' && str_contains($source, $normalized)) {
                    continue;
                }
                $invalid[] = [
                    'path' => $field === 'chapter_plan_completion' ? $field : "{$field}.{$index}",
                    'status' => $audit['status'],
                    'invalid_evidence' => $evidence,
                    'requirement' => $this->requirement($field, (int) $index, $context),
                    'target_scene_sequence' => $targetSequence,
                    'target_scene_content' => $targetContent,
                ];
            }
        }

        return $invalid;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, mixed>>  $invalid
     * @return array{payload: array<string, mixed>, generation_run_id: int, artifact_id: int}
     */
    public function repair(
        GenerationRun $parentRun,
        Chapter $chapter,
        GenerationArtifact $draft,
        array $payload,
        array $context,
        array $invalid,
    ): array {
        $identity = [
            'parent_review_input_hash' => $parentRun->input_hash,
            'source_artifact_id' => $draft->getKey(),
            'source_artifact_checksum' => $draft->checksum,
            'payload_checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'invalid' => $invalid,
            'prompt_version' => self::PROMPT_VERSION,
        ];
        $inputHash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if ($existing = $this->existing($chapter, $inputHash)) {
            return [
                'payload' => $this->apply($payload, $invalid, (array) data_get($existing->data, 'repairs'), $draft),
                'generation_run_id' => (int) $existing->generation_run_id,
                'artifact_id' => (int) $existing->getKey(),
            ];
        }

        $run = DB::transaction(function () use ($parentRun, $chapter, $draft, $context, $identity, $inputHash): GenerationRun {
            if (GenerationRun::query()->where('stage', GenerationStage::ReviewRepair)->where('input_hash', $inputHash)->exists()) {
                throw new AiProviderException('review_evidence_repair_unavailable', '相同审校证据已尝试修复，不能重复调用模型。', false);
            }

            return GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scope_type' => 'review_evidence_repair',
                'scope_id' => $parentRun->getKey(),
                'stage' => GenerationStage::ReviewRepair,
                'status' => RunStatus::Running,
                'attempt' => 1,
                'idempotency_key' => "review-evidence-repair:{$chapter->getKey()}:{$inputHash}",
                'input_hash' => $inputHash,
                'state_version' => $parentRun->state_version,
                'bible_version' => $parentRun->bible_version,
                'prompt_version' => self::PROMPT_VERSION,
                'provider' => $parentRun->provider,
                'model_policy' => $parentRun->model_policy,
                'context_snapshot' => [
                    'parent_review_run_id' => $parentRun->getKey(),
                    'source_artifact_id' => $draft->getKey(),
                    'repair_input' => $identity,
                    'generation_preferences' => $context['generation_preferences'] ?? [],
                ],
                'started_at' => now(),
            ]);
        });

        try {
            $contract = $this->outputCapacity->frozenRepairBudget($run, AiStage::Reviewer, 'review_schema', 'initial');
            $budget = $contract['budget'];
            $request = new AiRequest(
                model: (string) $run->model_policy,
                provider: (string) $run->provider,
                reasoningEffort: data_get($run->context_snapshot, 'generation_preferences.frozen_route.reasoning_effort'),
                systemPrompt: '你是 XNovel 审校证据修复器。只修复列出的逐字证据，不能修改审校状态、评分、Findings、正文或故事事实。普通审计逐项从 draft 原文复制连续文字；有 target_scene_sequence 时只能从对应 target_scene_content 复制，目标场景没有证据则返回 null。不要添加包裹引号或省略号；若原文不能证明已满足条件，返回 null。冲突证据必须引用真实冲突原文。严格按输入顺序返回。',
                prompt: json_encode(['invalid_audits' => $invalid, 'draft' => $draft->content], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: .1,
                maxTokens: $budget['max_completion_tokens'],
                responseSchema: [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['repairs'],
                    'properties' => ['repairs' => [
                        'type' => 'array', 'minItems' => count($invalid), 'maxItems' => count($invalid),
                        'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['evidence'],
                            'properties' => ['evidence' => ['type' => ['string', 'null']]]],
                    ]],
                ],
                promptVersion: self::PROMPT_VERSION,
                metadata: [
                    'generation_run_id' => $run->getKey(), 'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(), 'stage' => AiStage::Reviewer->value,
                    'substage' => 'review_evidence_repair',
                ],
            );
            $this->outputCapacity->assertRequestFromMetadata($request, AiStage::Reviewer, 'review_evidence_repair', $budget, $contract['tiers']);
            $response = $this->provider->generate($request);
            $result = StructuredOutput::require($response, 'review_evidence_repair', 'Review Evidence Repair');
            $repairs = $result['repairs'] ?? null;
            if (! is_array($repairs) || count($repairs) !== count($invalid)) {
                throw new AiProviderException('review_evidence_repair_invalid', '审校证据修复数量与原审校不一致。', false);
            }
            $repaired = $this->apply($payload, $invalid, $repairs, $draft);
            $data = [
                'role' => 'review_evidence_repair', 'parent_review_run_id' => $parentRun->getKey(),
                'source_artifact_id' => $draft->getKey(), 'invalid' => $invalid, 'repairs' => $repairs,
            ];
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::Context, 'version' => 1, 'content' => $encoded,
                'data' => $data, 'checksum' => hash('sha256', $encoded),
            ]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return ['payload' => $repaired, 'generation_run_id' => (int) $run->getKey(), 'artifact_id' => (int) $artifact->getKey()];
        } catch (Throwable $exception) {
            $this->failurePolicy->record($run, $exception, 'review_evidence_repair_failed');
            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload @param array<int, array<string, mixed>> $invalid @param array<int, mixed> $repairs @return array<string, mixed> */
    public function apply(array $payload, array $invalid, array $repairs, GenerationArtifact $draft): array
    {
        foreach ($invalid as $index => $item) {
            $value = $repairs[$index] ?? null;
            if (! is_array($value) || ! array_key_exists('evidence', $value) || ! is_null($value['evidence']) && ! is_string($value['evidence'])) {
                throw new AiProviderException('review_evidence_repair_invalid', '审校证据修复格式无效。', false);
            }
            $evidence = $value['evidence'];
            if (is_string($evidence)) {
                $evidence = PlanCoverage::resolveRepresentativeExactEvidence((string) $draft->content, $evidence);
            }
            $source = ($item['target_scene_sequence'] ?? null) === null
                ? (string) $draft->content
                : (string) ($item['target_scene_content'] ?? '');
            if (is_string($evidence) && $evidence !== '' && str_contains($source, $evidence)) {
                data_set($payload, $item['path'].'.evidence', $evidence);

                continue;
            }
            if ($item['status'] === 'contradicted') {
                throw new AiProviderException('review_evidence_repair_invalid', '冲突证据无法由当前正文验证。', false);
            }
            data_set($payload, $item['path'].'.status', in_array($item['path'], ['chapter_plan_completion'], true)
                || str_starts_with($item['path'], 'milestone_completion.')
                || str_starts_with($item['path'], 'beat_exit.')
                || str_starts_with($item['path'], 'handoff_readiness.')
                || str_starts_with($item['path'], 'arc_completion_audits.') ? 'not_met' : 'missing');
            data_set($payload, $item['path'].'.evidence', null);
        }

        return $payload;
    }

    private function existing(Chapter $chapter, string $inputHash): ?GenerationArtifact
    {
        return GenerationArtifact::query()->where('type', ArtifactType::Context)->where('input_hash', $inputHash)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey())
                ->where('stage', GenerationStage::ReviewRepair)->where('status', RunStatus::Succeeded))
            ->latest('id')->get()->first(fn (GenerationArtifact $artifact): bool => data_get($artifact->data, 'role') === 'review_evidence_repair');
    }

    /** @param array<string, mixed> $context */
    private function requirement(string $field, int $index, array $context): mixed
    {
        return match ($field) {
            'chapter_plan_completion' => data_get($context, 'chapter_plan.chapter_function'),
            'milestone_completion' => data_get($context, "outline_completion_contract.milestone_criteria.{$index}"),
            'beat_exit' => data_get($context, "outline_completion_contract.beat_exit_criteria.{$index}"),
            'handoff_readiness' => data_get($context, "outline_completion_contract.handoff_checks.{$index}.requirement"),
            'arc_beat_audits' => data_get($context, "chapter_plan.arc_contributions.{$index}.acceptance_criteria"),
            'arc_completion_audits' => data_get($context, "arc_completion_contract.{$index}.completion_conditions"),
            'character_candidate_audits' => data_get($context, "chapter_plan.character_candidates.{$index}.introduction_reason"),
            'world_entity_candidate_audits' => data_get($context, "chapter_plan.world_entity_candidates.{$index}.introduction_reason"),
            default => null,
        };
    }

    /** @param array<string, mixed> $context */
    private function targetSceneSequence(string $field, int $index, array $context): ?int
    {
        $sequence = match ($field) {
            'arc_beat_audits' => data_get($context, "chapter_plan.arc_contributions.{$index}.target_scene_sequence"),
            'character_candidate_audits' => data_get($context, "chapter_plan.character_candidates.{$index}.target_scene_sequence"),
            'world_entity_candidate_audits' => data_get($context, "chapter_plan.world_entity_candidates.{$index}.target_scene_sequence"),
            default => null,
        };

        return is_numeric($sequence) && (int) $sequence > 0 ? (int) $sequence : null;
    }
}
