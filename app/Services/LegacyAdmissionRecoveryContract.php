<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use Illuminate\Validation\ValidationException;

/**
 * 读取并验证 Admission v1 的不可变章节恢复合同。
 *
 * 旧 Plan 快照不能补写新字段，因此所有后续 Provider 阶段只能读取显式创建的恢复 Artifact。
 */
final class LegacyAdmissionRecoveryContract
{
    public const CONTRACT_VERSION = 'legacy-admission-chapter-recovery-v1';

    public const SCOPE = 'legacy_admission_recovery';

    /** @var array<int, AiStage> */
    public const PROVIDER_STAGES = [
        AiStage::Extractor,
        AiStage::Reviewer,
        AiStage::Rewrite,
        AiStage::Summary,
    ];

    public function __construct(
        private readonly ContextBuilder $contextBuilder,
        private readonly GenerationStageFingerprint $fingerprint,
        private readonly GenerationRequestBudget $requestBudget,
    ) {}

    /** 当前 Chapter Plan 是否属于必须显式恢复的 Admission v1。 */
    public function appliesTo(Chapter $chapter): bool
    {
        return (int) data_get($chapter->latestPlan?->admission_snapshot, 'schema_version') === 1;
    }

    /** 返回最新成功恢复 Run；没有显式恢复时不得猜测当前路由。 */
    public function latestRun(Chapter $chapter): ?GenerationRun
    {
        return $chapter->generationRuns()
            ->where('stage', GenerationStage::ChapterRecovery)
            ->where('scope_type', self::SCOPE)
            ->where('status', RunStatus::Succeeded)
            ->latest('id')
            ->first();
    }

    /**
     * 相同来源再次启动时复用已经冻结的完整合同，不受后来后台配置变化影响。
     *
     * @param  array<string, mixed>  $source
     */
    public function reusableRunForSource(Chapter $chapter, array $source): ?GenerationRun
    {
        $run = $this->latestRun($chapter);
        if ($run === null) {
            return null;
        }
        $contract = $this->contractData($run);
        if ($this->fingerprint->normalize((array) data_get($contract, 'source'))
            !== $this->fingerprint->normalize($source)) {
            return null;
        }
        foreach (self::PROVIDER_STAGES as $stage) {
            $route = data_get($contract, "routes.{$stage->value}");
            if (! is_array($route)) {
                throw new AiProviderException('legacy_recovery_route_missing', "旧恢复合同缺少 {$stage->getLabel()} 路由。", false);
            }
            $this->assertRouteValid($stage, $route);
        }

        return $run;
    }

    /**
     * 返回指定阶段的完整冻结路由，并在任何 Provider 请求前验证来源链。
     *
     * @return array{run: GenerationRun, route: array<string, mixed>}
     */
    public function routeFor(Chapter $chapter, AiStage $stage, GenerationArtifact $source): array
    {
        if (! in_array($stage, self::PROVIDER_STAGES, true)) {
            throw new AiProviderException(
                'legacy_recovery_stage_unsupported',
                "Admission v1 恢复合同不支持 {$stage->getLabel()} 阶段。",
                false,
            );
        }

        $run = $this->latestRun($chapter);
        if ($run === null) {
            throw new AiProviderException(
                'legacy_recovery_contract_missing',
                '当前 Chapter Plan 使用 Admission v1，但尚未创建完整章节恢复合同。请使用“恢复章节流程（Admission v1）”。',
                false,
            );
        }

        $contract = $this->contractData($run);
        $this->assertBaseSources($chapter, $run, $contract, $stage);
        $this->assertArtifactAuthorized($chapter, $run, $contract, $source);
        $route = data_get($contract, "routes.{$stage->value}");
        if (! is_array($route)) {
            throw new AiProviderException(
                'legacy_recovery_route_missing',
                "Admission v1 恢复合同缺少 {$stage->getLabel()} 的冻结路由。",
                false,
            );
        }
        $this->assertRouteValid($stage, $route);

        return ['run' => $run, 'route' => $route];
    }

    /** Rewrite 后重新组装前验证当前 Scene 仍来自冻结来源或本恢复合同。 */
    public function assertCanAssembleCurrentScenes(Chapter $chapter): GenerationRun
    {
        $run = $this->latestRun($chapter);
        if ($run === null) {
            throw new AiProviderException(
                'legacy_recovery_contract_missing',
                'Admission v1 重新组装前缺少完整章节恢复合同。',
                false,
            );
        }

        $contract = $this->contractData($run);
        $this->assertBaseSources($chapter, $run, $contract, AiStage::Rewrite);
        foreach ($chapter->scenes as $scene) {
            $artifact = $scene->currentArtifact;
            if (! $artifact instanceof GenerationArtifact) {
                throw new AiProviderException('legacy_recovery_scene_incomplete', "Scene {$scene->sequence} 缺少当前 Artifact。", false);
            }
            $this->assertSceneArtifactAuthorized($run, $contract, $artifact);
        }

        return $run;
    }

    /** @return array<string, mixed> */
    private function contractData(GenerationRun $run): array
    {
        $artifact = $run->artifacts()
            ->where('type', ArtifactType::Context)
            ->latest('version')
            ->first();
        $data = $artifact?->data;
        if (! is_array($data)
            || data_get($data, 'contract_version') !== self::CONTRACT_VERSION
            || ! hash_equals((string) $artifact->checksum, $this->checksum($data))) {
            throw new AiProviderException(
                'legacy_recovery_contract_invalid',
                'Admission v1 章节恢复 Artifact 缺失、版本不匹配或校验和无效。',
                false,
            );
        }

        return $data;
    }

    /** @param array<string, mixed> $contract */
    private function assertBaseSources(Chapter $chapter, GenerationRun $run, array $contract, AiStage $stage): void
    {
        $chapter->loadMissing(['novel.canonicalStateVersion', 'latestPlan', 'scenes.currentArtifact.generationRun']);
        $plan = $chapter->latestPlan;
        $source = data_get($contract, 'source');
        $currentAdmissionChecksum = hash('sha256', json_encode(
            $this->fingerprint->normalize($plan?->admission_snapshot),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
        $stateVersion = $chapter->novel->canonicalStateVersion?->version;
        $expectedStateVersion = (int) data_get($source, 'state_version', -1);
        // Summary 在 Canonical Commit 后运行；后续章节可能已经提交，因此只要求状态至少包含本章提交。
        $stateMatches = $stage === AiStage::Summary
            ? $chapter->status === ChapterStatus::Canonical && $stateVersion >= $expectedStateVersion + 1
            : $stateVersion === $expectedStateVersion;

        if (! is_array($source)
            || $run->chapter_id !== $chapter->getKey()
            || (int) data_get($source, 'chapter_id') !== $chapter->getKey()
            || (int) data_get($source, 'plan_id') !== $plan?->getKey()
            || (int) data_get($source, 'plan_version') !== $plan?->version
            || (string) data_get($source, 'plan_checksum') !== (string) ($plan?->checksum ?: $plan?->semanticChecksum())
            || (int) data_get($plan?->admission_snapshot, 'schema_version') !== 1
            || (string) data_get($source, 'admission_snapshot_checksum') !== $currentAdmissionChecksum
            || (int) data_get($source, 'bible_version', -1) !== $this->contextBuilder->bibleVersionForChapter($chapter)
            || ! $stateMatches) {
            throw new AiProviderException(
                'legacy_recovery_source_changed',
                'Admission v1 恢复合同冻结后，Plan、Admission、Bible 或 Canonical State 已变化；已在 Provider 请求前阻断。',
                false,
            );
        }
    }

    /** @param array<string, mixed> $contract */
    private function assertArtifactAuthorized(Chapter $chapter, GenerationRun $run, array $contract, GenerationArtifact $artifact): void
    {
        if ($artifact->generationRun?->chapter_id !== $chapter->getKey()
            || ! hash_equals($artifact->checksum, hash('sha256', (string) $artifact->content))) {
            throw new AiProviderException('legacy_recovery_artifact_invalid', '恢复阶段输入 Artifact 不属于当前章节或正文校验和无效。', false);
        }

        $frozenDraftId = (int) data_get($contract, 'source.draft_artifact_id');
        $frozenDraftChecksum = (string) data_get($contract, 'source.draft_checksum');
        if ($artifact->getKey() === $frozenDraftId && hash_equals($frozenDraftChecksum, $artifact->checksum)) {
            return;
        }

        if ($artifact->type === ArtifactType::RewriteDraft) {
            if ((int) data_get($artifact->generationRun?->context_snapshot, 'generation_preferences.recovery_contract_run_id') === $run->getKey()) {
                return;
            }
        }

        if ($artifact->type === ArtifactType::ChapterDraft) {
            $sourceIds = data_get($artifact->data, 'ordered_artifact_ids');
            if (is_array($sourceIds) && $sourceIds !== []) {
                $sceneArtifacts = GenerationArtifact::query()
                    ->whereIn('id', array_map('intval', $sourceIds))
                    ->with('generationRun')
                    ->get()
                    ->keyBy('id');
                foreach ($sourceIds as $sourceId) {
                    $sceneArtifact = $sceneArtifacts->get((int) $sourceId);
                    if (! $sceneArtifact instanceof GenerationArtifact) {
                        throw new AiProviderException('legacy_recovery_artifact_lineage_invalid', '恢复后的章节草稿缺少 Scene 来源 Artifact。', false);
                    }
                    $this->assertSceneArtifactAuthorized($run, $contract, $sceneArtifact);
                }

                return;
            }
        }

        $this->assertSceneArtifactAuthorized($run, $contract, $artifact);
    }

    /** @param array<string, mixed> $contract */
    private function assertSceneArtifactAuthorized(GenerationRun $run, array $contract, GenerationArtifact $artifact): void
    {
        $frozen = collect((array) data_get($contract, 'source.scene_artifacts'))
            ->first(fn (mixed $item): bool => is_array($item) && (int) ($item['artifact_id'] ?? 0) === $artifact->getKey());
        if (is_array($frozen) && hash_equals((string) ($frozen['checksum'] ?? ''), $artifact->checksum)) {
            return;
        }
        if ($artifact->type === ArtifactType::RewriteDraft
            && (int) data_get($artifact->generationRun?->context_snapshot, 'generation_preferences.recovery_contract_run_id') === $run->getKey()) {
            return;
        }

        throw new AiProviderException(
            'legacy_recovery_artifact_lineage_invalid',
            '当前 Scene/Chapter Artifact 不属于 Admission v1 恢复合同冻结的来源链。',
            false,
        );
    }

    /** @param array<string, mixed> $route */
    private function assertRouteValid(AiStage $stage, array $route): void
    {
        $capacity = $route['model_capacity'] ?? null;
        $budgets = $route['request_budgets'] ?? null;
        $repairs = $route['repair_request_budgets'] ?? null;
        if (blank($route['provider'] ?? null)
            || blank($route['model'] ?? null)
            || blank($route['prompt_version'] ?? null)
            || ! is_array($capacity)
            || ! is_array($budgets)
            || ! is_array($repairs)
            || strtolower((string) ($capacity['provider'] ?? '')) !== strtolower((string) $route['provider'])
            || (string) ($capacity['model'] ?? '') !== (string) $route['model']) {
            throw new AiProviderException('legacy_recovery_route_invalid', "{$stage->getLabel()} 恢复路由、容量或预算合同不完整。", false);
        }

        try {
            $validated = $this->requestBudget->validateFrozen($budgets, $stage);
            foreach ($repairs as $substage => $tiers) {
                if (! is_string($substage) || ! is_array($tiers)) {
                    throw ValidationException::withMessages(['recovery' => '修复子阶段预算结构无效。']);
                }
                $this->requestBudget->validateFrozen($tiers, $stage);
            }
        } catch (ValidationException $exception) {
            throw new AiProviderException('legacy_recovery_route_invalid', "{$stage->getLabel()} 恢复预算无效：{$exception->getMessage()}", false, previous: $exception);
        }

        $maximum = max([
            $this->requestBudget->maximum($validated),
            ...collect($repairs)->map(fn (array $tiers): int => $this->requestBudget->maximum($tiers))->values()->all(),
        ]);
        $capacityMaximum = min(
            (int) ($capacity['context_window_tokens'] ?? 0),
            (int) ($capacity['max_output_tokens'] ?? 0),
        );
        if ($maximum < 1 || $maximum > $capacityMaximum) {
            throw new AiProviderException('legacy_recovery_route_invalid', "{$stage->getLabel()} 恢复预算超过冻结模型容量。", false);
        }
    }

    /** 对恢复合同做规范化哈希，避免 PostgreSQL jsonb 对象键顺序影响校验。 */
    public function checksum(array $contract): string
    {
        return hash('sha256', json_encode(
            $this->fingerprint->normalize($contract),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }
}
