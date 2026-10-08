<?php

namespace App\Actions\Chapters;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Services\ContextBuilder;
use App\Services\GenerationProgressionFailureRecorder;
use App\Services\GenerationStageFingerprint;
use App\Services\LegacyAdmissionRecoveryContract;
use App\Services\PlanAdmissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** 为已有完整正文的 Admission v1 创建全后续阶段恢复合同。 */
final class RecoverLegacyChapterPipelineAction
{
    public function __construct(
        private readonly PlanAdmissionService $planAdmission,
        private readonly ContextBuilder $contextBuilder,
        private readonly GenerationStageFingerprint $fingerprint,
        private readonly LegacyAdmissionRecoveryContract $recoveryContract,
        private readonly AdvanceChapterPipelineAction $advance,
        private readonly GenerationProgressionFailureRecorder $progressionFailures,
    ) {}

    /**
     * 保留 Plan、Scene、Chapter Draft 与历史 Run，只冻结 Extractor 之后的完整合同并恢复统一推进。
     */
    public function handle(Chapter $chapter): GenerationRun
    {
        $run = DB::transaction(function () use ($chapter): GenerationRun {
            $locked = Chapter::query()->lockForUpdate()->with([
                'novel.canonicalStateVersion',
                'latestPlan',
                'scenes.currentArtifact.generationRun',
            ])->findOrFail($chapter->getKey());
            $novel = $locked->novel;
            $plan = $locked->latestPlan;

            if ($novel->status === NovelStatus::Paused) {
                throw ValidationException::withMessages(['chapter' => '小说已暂停，不能创建 Admission v1 章节恢复合同。']);
            }
            if ($locked->status === ChapterStatus::Canonical) {
                throw ValidationException::withMessages(['chapter' => '正式章节不需要恢复 Admission v1 生成流程。']);
            }
            if ($plan === null || (int) data_get($plan->admission_snapshot, 'schema_version') !== 1) {
                throw ValidationException::withMessages([
                    'plan' => '[LEGACY_CHAPTER_RECOVERY_NOT_APPLICABLE] 只有 Admission v1 Chapter Plan 可以使用此恢复动作。',
                ]);
            }
            if ($locked->generationRuns()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
                throw ValidationException::withMessages(['generation_runs' => '当前章节仍有排队中或运行中的 Generation Run，请等待结束后再恢复。']);
            }

            $draft = $this->latestChapterDraft($locked);
            $sceneArtifacts = $locked->scenes->sortBy('sequence')->map(function ($scene): array {
                $artifact = $scene->currentArtifact;
                if (! in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true)
                    || ! $artifact instanceof GenerationArtifact
                    || $artifact->generationRun?->chapter_id !== $scene->chapter_id
                    || $artifact->generationRun?->scene_id !== $scene->getKey()
                    || ! hash_equals($artifact->checksum, hash('sha256', (string) $artifact->content))) {
                    throw ValidationException::withMessages([
                        'scenes' => "Scene {$scene->sequence} 缺少可验证的当前正文，不能跳过 Writer 创建恢复合同。",
                    ]);
                }

                return [
                    'scene_id' => $scene->getKey(),
                    'sequence' => (int) $scene->sequence,
                    'artifact_id' => $artifact->getKey(),
                    'artifact_type' => $artifact->type->value,
                    'checksum' => $artifact->checksum,
                ];
            })->values()->all();
            if ($sceneArtifacts === []) {
                throw ValidationException::withMessages(['scenes' => 'Admission v1 章节没有完整 Scene 正文，不能跳过 Writer 恢复。']);
            }

            $stateVersion = $novel->canonicalStateVersion?->version;
            if ($stateVersion === null) {
                throw ValidationException::withMessages(['state' => '小说缺少 Canonical State Version，不能冻结恢复来源。']);
            }

            $source = [
                'chapter_id' => $locked->getKey(),
                'plan_id' => $plan->getKey(),
                'plan_version' => $plan->version,
                'plan_checksum' => $plan->checksum ?: $plan->semanticChecksum(),
                'admission_schema_version' => 1,
                'admission_input_hash' => data_get($plan->admission_snapshot, 'input_hash'),
                'admission_snapshot_checksum' => hash('sha256', json_encode(
                    $this->fingerprint->normalize($plan->admission_snapshot),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                )),
                'draft_artifact_id' => $draft->getKey(),
                'draft_checksum' => $draft->checksum,
                'state_version' => $stateVersion,
                'bible_version' => $this->contextBuilder->bibleVersionForChapter($locked),
                'scene_artifacts' => $sceneArtifacts,
            ];
            $existing = $this->recoveryContract->reusableRunForSource($locked, $source);
            if ($existing !== null) {
                if ($locked->status === ChapterStatus::Blocked) {
                    $locked->update(['status' => ChapterStatus::Generating]);
                }

                return $existing;
            }

            $routes = [];
            foreach (LegacyAdmissionRecoveryContract::PROVIDER_STAGES as $stage) {
                // 只有创建新合同才读取当前配置；相同来源 Resume 必须复用旧合同。
                $routes[$stage->value] = $this->planAdmission->currentStageContract($novel, $stage)['route'];
            }
            $contract = [
                'contract_version' => LegacyAdmissionRecoveryContract::CONTRACT_VERSION,
                'source' => $source,
                'routes' => $routes,
            ];
            $inputHash = $this->recoveryContract->checksum($contract);
            $attempt = ((int) $locked->generationRuns()
                ->where('stage', GenerationStage::ChapterRecovery)
                ->max('attempt')) + 1;
            $run = GenerationRun::query()->create([
                'novel_id' => $locked->novel_id,
                'chapter_id' => $locked->getKey(),
                'scene_id' => null,
                'scope_type' => LegacyAdmissionRecoveryContract::SCOPE,
                'scope_id' => $locked->getKey(),
                'stage' => GenerationStage::ChapterRecovery,
                'status' => RunStatus::Succeeded,
                'attempt' => $attempt,
                'idempotency_key' => "legacy-chapter-recovery:{$locked->getKey()}:{$inputHash}",
                'input_hash' => $inputHash,
                'state_version' => $stateVersion,
                'bible_version' => $source['bible_version'],
                'prompt_version' => LegacyAdmissionRecoveryContract::CONTRACT_VERSION,
                'provider' => 'deterministic',
                'model_policy' => 'frozen-multi-stage-contract',
                'context_snapshot' => [
                    'contract_version' => LegacyAdmissionRecoveryContract::CONTRACT_VERSION,
                    'source' => $source,
                    'frozen_stage_routes' => array_keys($routes),
                    // 此阶段只持久化合同，绝不发送 Provider 请求。
                    'provider_request_count' => 0,
                ],
                'started_at' => now(),
                'finished_at' => now(),
            ]);
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::Context,
                'version' => 1,
                'content' => null,
                'data' => $contract,
                'checksum' => $inputHash,
            ]);
            $snapshot = $run->context_snapshot;
            $snapshot['contract_artifact_id'] = $artifact->getKey();
            $run->update(['context_snapshot' => $snapshot]);
            $locked->update(['status' => ChapterStatus::Generating]);

            return $run->refresh();
        });

        try {
            // 合同落库后交给唯一推进器选择 Coverage、Extractor、Review 或 Rewrite，避免恢复动作复制流程判断。
            $this->advance->handle($chapter->getKey());
            $this->progressionFailures->resolve(
                GenerationStage::ChapterRecovery,
                $chapter->getKey(),
                generationRunId: $run->getKey(),
            );
        } catch (Throwable $exception) {
            // 恢复合同已经成功持久化，后续派发失败必须按统一格式挂在该成功 Run 上。
            $this->progressionFailures->record(
                GenerationStage::ChapterRecovery,
                $chapter->getKey(),
                $exception,
                generationRunId: $run->getKey(),
            );
            Chapter::query()->whereKey($run->chapter_id)->update(['status' => ChapterStatus::Blocked]);
            throw $exception;
        }

        return $run->fresh();
    }

    /** 只接受章节级组装/重写草稿，不能把 Scene Artifact 当成完整章节来源。 */
    private function latestChapterDraft(Chapter $chapter): GenerationArtifact
    {
        $draft = GenerationArtifact::query()
            ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->whereNull('scene_id')
                ->where('status', RunStatus::Succeeded))
            ->with('generationRun')
            ->latest('id')
            ->first();
        if (! $draft instanceof GenerationArtifact
            || ! hash_equals($draft->checksum, hash('sha256', (string) $draft->content))) {
            throw ValidationException::withMessages(['draft' => 'Admission v1 章节缺少可验证的完整 Chapter Draft。']);
        }

        return $draft;
    }
}
