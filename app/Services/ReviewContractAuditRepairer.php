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

/**
 * 用独立 Run/Artifact 修复主审校遗漏的冻结合同数组；不得改写正文、评分或 Findings。
 */
final class ReviewContractAuditRepairer
{
    public const PROMPT_VERSION = 'review-contract-repair-v1';

    public function __construct(
        private readonly AiProvider $provider,
        private readonly GenerationOutputCapacityGuard $outputCapacity,
        private readonly GenerationFailurePolicy $failurePolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $fields
     * @param  array<string, mixed>  $context
     * @param  array<string, int>  $counts
     * @return array{audits: array<string, mixed>, generation_run_id: int, artifact_id: int}
     */
    public function repair(
        GenerationRun $parentRun,
        Chapter $chapter,
        GenerationArtifact $draft,
        array $payload,
        array $fields,
        array $context,
        array $counts,
    ): array {
        $contracts = $this->contracts($context);
        $providerInput = [
            'fields' => array_values($fields),
            'expected_counts' => collect($counts)->only($fields)->all(),
            'contracts' => collect($contracts)->only($fields)->all(),
            'invalid_response' => collect($payload)->only($fields)->all(),
            'draft' => $draft->content,
        ];
        $trackingProviderInput = $providerInput;
        unset($trackingProviderInput['draft']);
        $trackingInput = [
            'parent_review_input_hash' => $parentRun->input_hash,
            'source_artifact_id' => $draft->getKey(),
            'source_artifact_checksum' => $draft->checksum,
            'provider_input' => $trackingProviderInput,
            'prompt_version' => self::PROMPT_VERSION,
        ];
        $encodedTrackingInput = json_encode($trackingInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encodedProviderInput = json_encode($providerInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $inputHash = hash('sha256', $encodedTrackingInput);

        if ($existing = $this->existing($chapter, $inputHash)) {
            return [
                'audits' => (array) data_get($existing->data, 'result', []),
                'generation_run_id' => (int) $existing->generation_run_id,
                'artifact_id' => (int) $existing->getKey(),
            ];
        }

        $run = $this->startRun($parentRun, $chapter, $draft, $inputHash, $trackingInput, $context);

        try {
            $repairContract = $this->outputCapacity->frozenRepairBudget(
                $run,
                AiStage::Reviewer,
                'review_schema',
                'initial',
            );
            $budget = $repairContract['budget'];
            $request = new AiRequest(
                model: (string) $run->model_policy,
                provider: (string) $run->provider,
                reasoningEffort: data_get($run->context_snapshot, 'generation_preferences.frozen_route.reasoning_effort'),
                systemPrompt: '你是 XNovel 审校合同修复器。只返回指定的审计数组，不得修改正文、评分、Findings、其他审计或任何正式故事数据。每个数组必须严格按输入 contracts 的原顺序和 expected_counts 的数量逐项返回。fulfilled、introduced 或 contradicted 必须引用当前正文中的连续逐字证据；missing、not_met 与 not_applicable 的 evidence 必须为 null。不得返回数据库 ID、Key、条件文本、Scene ID 或汇总状态，Laravel 会按位置恢复身份。',
                prompt: '请修复以下遗漏的审校合同数组：'.$encodedProviderInput,
                temperature: .1,
                maxTokens: $budget['max_completion_tokens'],
                responseSchema: CompactReviewPayload::repairSchema($counts, $fields),
                promptVersion: self::PROMPT_VERSION,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(),
                    'stage' => AiStage::Reviewer->value,
                    'substage' => 'review_contract_repair',
                ],
            );
            $this->outputCapacity->assertRequestFromMetadata(
                $request,
                AiStage::Reviewer,
                'review_contract_repair',
                $budget,
                $repairContract['tiers'],
            );
            $response = $this->provider->generate($request);
            $result = StructuredOutput::require($response, 'review_contract_repair', 'Review Contract Repair');
            $resultCounts = collect($counts)->only($fields)->all();
            $actualFields = array_keys($result);
            $expectedFields = array_values($fields);
            sort($actualFields);
            sort($expectedFields);
            if ($actualFields !== $expectedFields
                || CompactReviewPayload::contractMismatches($result, $resultCounts) !== []) {
                throw new AiProviderException(
                    'review_contract_repair_invalid',
                    'Review Contract Repair 仍未按冻结合同完整返回指定数组。',
                    false,
                );
            }

            $artifact = $this->persist($run, $parentRun, $draft, $inputHash, $fields, $result);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return [
                'audits' => (array) data_get($artifact->data, 'result', []),
                'generation_run_id' => (int) $run->getKey(),
                'artifact_id' => (int) $artifact->getKey(),
            ];
        } catch (Throwable $exception) {
            $this->failurePolicy->record($run, $exception, 'review_contract_repair_failed');

            throw new AiProviderException(
                'review_contract_repair_failed',
                '审校合同数组自动修复失败：'.$exception->getMessage(),
                false,
                previous: $exception,
            );
        }
    }

    /** @return array<string, mixed> */
    private function contracts(array $context): array
    {
        $plan = (array) ($context['chapter_plan'] ?? []);
        $outline = (array) ($context['outline_completion_contract'] ?? []);

        return [
            'milestone_completion' => array_values((array) ($outline['milestone_criteria'] ?? [])),
            'beat_exit' => array_values((array) ($outline['beat_exit_criteria'] ?? [])),
            'handoff_readiness' => array_values((array) ($outline['handoff_checks'] ?? [])),
            'foreshadowing_audits' => collect((array) data_get($context, 'foreshadowing_contract.actions', []))
                ->map(fn (array $item): array => collect($item)->except([
                    'foreshadowing_id', 'legacy_reference_id', 'reference_id',
                ])->all())
                ->values()
                ->all(),
            'arc_beat_audits' => collect($plan['arc_contributions'] ?? [])->map(fn (array $item): array => [
                'acceptance_criteria' => $item['acceptance_criteria'] ?? null,
                'target_scene_sequence' => $item['target_scene_sequence'] ?? null,
            ])->values()->all(),
            'arc_completion_audits' => collect((array) ($context['arc_completion_contract'] ?? []))
                ->map(fn (array $item): array => collect($item)->only([
                    'title', 'completion_conditions',
                ])->all())
                ->values()
                ->all(),
            'character_candidate_audits' => collect((array) ($plan['character_candidates'] ?? []))
                ->map(fn (array $item): array => collect($item)->except([
                    'candidate_key', 'id', 'character_id',
                ])->all())
                ->values()
                ->all(),
            'world_entity_candidate_audits' => collect((array) ($plan['world_entity_candidates'] ?? []))
                ->map(fn (array $item): array => collect($item)->except([
                    'candidate_key', 'id', 'world_entity_id',
                ])->all())
                ->values()
                ->all(),
        ];
    }

    private function startRun(
        GenerationRun $parentRun,
        Chapter $chapter,
        GenerationArtifact $draft,
        string $inputHash,
        array $input,
        array $context,
    ): GenerationRun {
        return DB::transaction(function () use ($parentRun, $chapter, $draft, $inputHash, $input, $context): GenerationRun {
            $existing = GenerationRun::query()
                ->where('stage', GenerationStage::ReviewRepair)
                ->where('input_hash', $inputHash)
                ->latest('id')
                ->first();
            if ($existing !== null) {
                if ($existing->status === RunStatus::Succeeded) {
                    return $existing;
                }

                throw new AiProviderException(
                    'review_contract_repair_unavailable',
                    '相同输入的审校合同修复已经失败或仍在运行，不能重复调用 Provider。',
                    false,
                );
            }

            $generationPreferences = (array) ($context['generation_preferences'] ?? []);

            return GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scope_type' => 'review_contract_repair',
                'scope_id' => $parentRun->getKey(),
                'stage' => GenerationStage::ReviewRepair,
                'status' => RunStatus::Running,
                'attempt' => 1,
                'idempotency_key' => "review-contract-repair:{$chapter->getKey()}:{$inputHash}",
                'input_hash' => $inputHash,
                'state_version' => $parentRun->state_version,
                'bible_version' => $parentRun->bible_version,
                'prompt_version' => self::PROMPT_VERSION,
                'provider' => $parentRun->provider,
                'model_policy' => $parentRun->model_policy,
                'context_snapshot' => [
                    'parent_review_run_id' => $parentRun->getKey(),
                    'source_artifact_id' => $draft->getKey(),
                    'repair_input' => $input,
                    'generation_preferences' => $generationPreferences,
                ],
                'started_at' => now(),
            ]);
        });
    }

    private function existing(Chapter $chapter, string $inputHash): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->where('type', ArtifactType::Context)
            ->where('input_hash', $inputHash)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('stage', GenerationStage::ReviewRepair)
                ->where('status', RunStatus::Succeeded))
            ->latest('id')
            ->get()
            ->first(fn (GenerationArtifact $artifact): bool => data_get($artifact->data, 'role') === 'review_contract_repair');
    }

    /** @param array<int, string> $fields @param array<string, mixed> $result */
    private function persist(
        GenerationRun $run,
        GenerationRun $parentRun,
        GenerationArtifact $draft,
        string $inputHash,
        array $fields,
        array $result,
    ): GenerationArtifact {
        $data = [
            'role' => 'review_contract_repair',
            'parent_review_run_id' => $parentRun->getKey(),
            'source_artifact_id' => $draft->getKey(),
            'input_hash' => $inputHash,
            'fields' => array_values($fields),
            'result' => $result,
        ];
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $run->artifacts()->create([
            'type' => ArtifactType::Context,
            'version' => 1,
            'content' => $encoded,
            'data' => $data,
            'checksum' => hash('sha256', $encoded),
        ]);
    }
}
