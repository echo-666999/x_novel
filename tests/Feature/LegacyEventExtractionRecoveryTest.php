<?php

use App\Actions\Chapters\RecoverLegacyChapterPipelineAction;
use App\Actions\Chapters\RecoverLegacyEventExtractionAction;
use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Jobs\AdjudicatePlanCoverageJob;
use App\Jobs\AssembleChapterJob;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Models\AIModelPrice;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Review;
use App\Models\Scene;
use App\Models\User;
use App\Services\CanonicalChapterSummaryService;
use App\Services\DraftLengthPolicy;
use App\Services\GenerationJobDispatcher;
use App\Services\LegacyAdmissionRecoveryContract;
use App\Services\OutlineCompletionService;
use App\Services\StoryEventExtractor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/** @return array<string, mixed> */
function legacyEventRecoveryFixture(): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Blocked]);
    $character = Character::factory()->for($novel)->create();
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'pov_character_id' => $character->getKey(),
        'status' => PlanStatus::Ready,
    ]);
    $plan = freezeChapterRouteContractsForTest($plan);
    $legacySnapshot = $plan->admission_snapshot;
    $legacySnapshot['schema_version'] = 1;
    foreach (array_keys((array) ($legacySnapshot['routes'] ?? [])) as $stage) {
        unset($legacySnapshot['routes'][$stage]['model_capacity'], $legacySnapshot['routes'][$stage]['request_budgets']);
    }
    // 旧快照故意保留不同的 Extractor 值，证明恢复合同解析当前路由，而不是偷偷补写或继承 Admission v1。
    $legacySnapshot['routes']['extractor']['provider'] = 'legacy-provider';
    $legacySnapshot['routes']['extractor']['model'] = 'legacy-model';
    $legacySnapshot['routes']['extractor']['reasoning_effort'] = 'high';
    $plan->update(['admission_snapshot' => $legacySnapshot]);

    $scene = Scene::factory()->for($chapter)->create([
        'sequence' => 1,
        'status' => SceneStatus::Draft,
    ]);
    $sceneRun = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
        'scope_type' => 'scene',
        'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $content = '林舟终于抵达洛阳城下。城门在身后关闭。';
    $sceneArtifact = GenerationArtifact::factory()->for($sceneRun)->create([
        'type' => ArtifactType::SceneDraft,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $scene->update(['current_artifact_id' => $sceneArtifact->getKey()]);

    $assemblyRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $draft = GenerationArtifact::factory()->for($assemblyRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $historicalRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Failed,
        'attempt' => 1,
        'error_code' => 'event_reasoning_budget_exhausted',
        'error_message' => '旧合同推理预算耗尽。',
    ]);

    return compact('novel', 'chapter', 'plan', 'legacySnapshot', 'scene', 'sceneArtifact', 'draft', 'historicalRun');
}

function successfulLegacyEventRecoveryResponse(Chapter $chapter): AiResponse
{
    $contract = app(OutlineCompletionService::class)->contract($chapter);
    // 恢复链同样使用 v9 精简输出，冻结身份和汇总状态由 Laravel 恢复。
    $criteria = static fn (array $items): array => collect($items)->map(fn (): array => [
        'status' => 'not_met',
        'evidence' => null,
    ])->all();
    $payload = [
        'events' => [],
        'outline_completion' => [
            'milestone_completion' => $criteria($contract['milestone_criteria']),
            'beat_exit' => $criteria($contract['beat_exit_criteria']),
            'handoff_readiness' => collect($contract['handoff_checks'])->map(fn (): array => [
                'status' => 'not_met',
                'evidence' => null,
            ])->all(),
        ],
    ];

    return new AiResponse(
        content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: 1_000,
        outputTokens: 500,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'legacy-event-recovery-request',
        model: 'legacy-event-recovery-model',
    );
}

function successfulLegacySummaryResponse(): AiResponse
{
    $payload = [
        'summary' => '林舟抵达洛阳，城门随后关闭。',
        'key_events' => ['林舟抵达洛阳'],
        'character_changes' => [],
        'unresolved_threads' => ['城门关闭的原因尚未查明'],
    ];

    return new AiResponse(
        content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: 300,
        outputTokens: 100,
        cachedTokens: 0,
        latencyMs: 50,
        providerRequestId: 'legacy-summary-recovery-request',
        model: 'legacy-summary-recovery-model',
    );
}

/** 返回确认原 Coverage 缺失的语义复核响应，驱动流程生成新的 Rewrite Review。 */
function legacyRewriteCoverageJudgmentResponse(string $content): AiResponse
{
    $payload = [
        'goal' => ['status' => 'missing', 'evidence' => null],
        'conflict' => ['status' => 'fulfilled', 'evidence' => '城门'],
        'turn' => ['status' => 'fulfilled', 'evidence' => '林舟终于抵达洛阳城下'],
        'outcome' => ['status' => 'fulfilled', 'evidence' => '城门在身后关闭'],
    ];

    return legacyRewriteResponse($payload, 'legacy-coverage-judgment', $content);
}

/** 返回长度与 Coverage 均满足冻结计划的局部重写响应。 */
function legacyRewriteSceneResponse(string $content): AiResponse
{
    $payload = [
        'content' => $content,
        'self_check' => [
            'goal' => ['status' => 'fulfilled', 'evidence' => '抵达洛阳'],
            'conflict' => ['status' => 'fulfilled', 'evidence' => '冲过阻拦'],
            'turn' => ['status' => 'fulfilled', 'evidence' => '回身确认'],
            'outcome' => ['status' => 'fulfilled', 'evidence' => '城门已关闭'],
        ],
    ];

    return legacyRewriteResponse($payload, 'legacy-scene-rewrite', $content);
}

/** 返回与当前 Outline Completion 合同逐项对应的事件提取响应。 */
function legacyRewriteEventResponse(Chapter $chapter, string $evidence): AiResponse
{
    $contract = app(OutlineCompletionService::class)->contract($chapter);
    // 精简合同只返回逐项语义结论，Scene ID 由逐字证据唯一定位。
    $criteria = static fn (array $items): array => collect($items)->map(fn (): array => [
        'status' => 'fulfilled',
        'evidence' => $evidence,
    ])->all();
    $payload = [
        'events' => [],
        'outline_completion' => [
            'milestone_completion' => $criteria($contract['milestone_criteria']),
            'beat_exit' => $criteria($contract['beat_exit_criteria']),
            'handoff_readiness' => collect($contract['handoff_checks'])->map(fn (): array => [
                'status' => 'fulfilled',
                'evidence' => $evidence,
            ])->all(),
        ],
    ];

    return legacyRewriteResponse($payload, 'legacy-event-extraction', $evidence);
}

/** 返回最终 PASS 所需的紧凑 Narrative Review 响应。 */
function legacyRewritePassReviewResponse(Chapter $chapter, string $evidence): AiResponse
{
    $contract = app(OutlineCompletionService::class)->contract($chapter);
    $audit = static fn (string $status = 'fulfilled'): array => [
        'status' => $status,
        'evidence' => in_array($status, ['fulfilled', 'introduced'], true) ? $evidence : null,
    ];
    $arcContributions = collect($chapter->latestPlan?->arc_contributions ?? []);
    $payload = [
        'scores' => array_fill_keys(['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'], 95),
        'chapter_plan_completion' => $audit(),
        'milestone_completion' => collect($contract['milestone_criteria'])->map(fn (): array => $audit())->all(),
        'beat_exit' => collect($contract['beat_exit_criteria'])->map(fn (): array => $audit())->all(),
        'handoff_readiness' => collect($contract['handoff_checks'])->map(fn (): array => $audit())->all(),
        'foreshadowing_audits' => [],
        'arc_beat_audits' => $arcContributions->map(fn (): array => $audit())->all(),
        'arc_completion_audits' => $arcContributions->pluck('arc_id')->unique()->map(fn (): array => $audit('not_met'))->values()->all(),
        'character_candidate_audits' => collect($chapter->latestPlan?->character_candidates ?? [])->map(fn (): array => $audit('introduced'))->all(),
        'world_entity_candidate_audits' => collect($chapter->latestPlan?->world_entity_candidates ?? [])->map(fn (): array => $audit('introduced'))->all(),
        'unapproved_characters' => [],
        'unapproved_world_entities' => [],
        'findings' => [],
    ];

    return legacyRewriteResponse($payload, 'legacy-final-review', $evidence);
}

/** 创建统一的 Fake Provider 响应，避免端到端断言依赖真实网络。 */
function legacyRewriteResponse(array $payload, string $requestId, string $content): AiResponse
{
    return new AiResponse(
        content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: max(1, mb_strlen($content)),
        outputTokens: 200,
        cachedTokens: 0,
        latencyMs: 25,
        providerRequestId: $requestId,
        model: 'legacy-rewrite-e2e-model',
    );
}

/**
 * 按真实队列派发顺序执行恢复链，并释放 ShouldBeUnique 锁。
 *
 * @return array<class-string, int>
 */
function runQueuedLegacyRewriteRecoveryPipeline(): array
{
    $jobClasses = [
        AdjudicatePlanCoverageJob::class,
        ExtractStoryEventsJob::class,
        ReviewChapterJob::class,
        RewriteChapterJob::class,
        AssembleChapterJob::class,
    ];
    $handled = array_fill_keys($jobClasses, 0);

    for ($iteration = 0; $iteration < 30; $iteration++) {
        $nextJob = null;
        foreach ($jobClasses as $jobClass) {
            $jobs = Queue::pushed($jobClass)->values();
            if ($jobs->count() <= $handled[$jobClass]) {
                continue;
            }

            $nextJob = $jobs->get($handled[$jobClass]);
            $handled[$jobClass]++;
            break;
        }

        if ($nextJob === null) {
            return $handled;
        }

        app()->call([$nextJob, 'handle']);
        app(UniqueLock::class)->release($nextJob);
    }

    throw new RuntimeException('Admission v1 局部重写回归超过 30 个 Job，可能存在重复派发循环。');
}

test('chapter page exposes the explicit legacy recovery action instead of ordinary extraction', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->assertActionVisible(TestAction::make('recoverLegacyChapterPipeline')->schemaComponent('story-event-candidates', 'content'))
        ->assertActionVisible(TestAction::make('extractStoryEvents')->schemaComponent('story-event-candidates', 'content'))
        ->callAction(TestAction::make('recoverLegacyChapterPipeline')->schemaComponent('story-event-candidates', 'content'));

    $run = GenerationRun::query()
        ->where('chapter_id', $fixture['chapter']->getKey())
        ->where('stage', GenerationStage::ChapterRecovery)
        ->latest('id')
        ->firstOrFail();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(data_get($run->context_snapshot, 'source.plan_id'))->toBe($fixture['plan']->getKey());
    Queue::assertPushed(ExtractStoryEventsJob::class, fn (ExtractStoryEventsJob $job): bool => $job->recoveryRunId === null);
});

test('rewrite button resumes a blocked legacy coverage review through the complete recovery contract', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $coverageDraft = GenerationArtifact::factory()->for($fixture['draft']->generationRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'version' => 2,
        'content' => $fixture['draft']->content,
        'checksum' => $fixture['draft']->checksum,
        'data' => [
            // 旧 Review 的 Coverage Finding 必须先进入独立语义复核，不能直接调用 Rewrite Provider。
            'plan_findings' => [[
                'code' => 'SCENE_PLAN_COVERAGE_MISSING',
                'scene_id' => $fixture['scene']->getKey(),
                'scope' => 'scene',
                'auto_fixable' => true,
                'requires_human_decision' => false,
            ]],
        ],
    ]);
    $reviewRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
        'state_version' => $fixture['novel']->fresh()->canonicalStateVersion?->version,
    ]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create([
        'type' => ArtifactType::ReviewResult,
        'data' => [
            'source_artifact_id' => $coverageDraft->getKey(),
            'rewrite_scope' => [
                'scope' => 'scene',
                'scene_id' => $fixture['scene']->getKey(),
                'reason' => 'single_scene_affected',
                'finding_indexes' => [0],
            ],
        ],
    ]);
    Review::factory()->for($reviewRun)->create([
        'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::Rewrite,
        'findings' => [[
            'code' => 'SCENE_PLAN_COVERAGE_MISSING',
            'scene_id' => $fixture['scene']->getKey(),
            'scope' => 'scene',
            'auto_fixable' => true,
            'requires_human_decision' => false,
        ]],
    ]);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->assertActionVisible(TestAction::make('rewriteScene')->schemaComponent('narrative-review', 'content'))
        ->assertActionDoesNotExist(TestAction::make('rewriteChapter')->schemaComponent('narrative-review', 'content'))
        ->callAction(TestAction::make('rewriteScene')->schemaComponent('narrative-review', 'content'))
        ->assertHasNoActionErrors()
        ->assertActionDisabled(TestAction::make('rewriteScene')->schemaComponent('narrative-review', 'content'))
        ->assertNotified('Admission v1 修复流程已恢复');

    $recoveryRun = GenerationRun::query()
        ->where('chapter_id', $fixture['chapter']->getKey())
        ->where('stage', GenerationStage::ChapterRecovery)
        ->sole();

    expect($recoveryRun->status)->toBe(RunStatus::Succeeded)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating);
    Queue::assertPushed(AdjudicatePlanCoverageJob::class, fn (AdjudicatePlanCoverageJob $job): bool => $job->sceneId === $fixture['scene']->getKey());
    Queue::assertNotPushed(RewriteChapterJob::class);
    Queue::assertNotPushed(PlanChapterJob::class);
    Queue::assertNotPushed(GenerateSceneJob::class);
});

test('legacy rewrite button completes coverage event rewrite assembly and final review without planner or writer', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $originalPlanSnapshot = $fixture['plan']->fresh()->admission_snapshot;
    $originalHistoricalRun = $fixture['historicalRun']->fresh()->toArray();
    $rewrittenContent = '林舟抵达洛阳，冲过阻拦，回身确认城门已关闭。';

    // 让测试正文处于真实长度门禁内，并冻结一个可由当前 Scene 安全修复的计划范围。
    $targetWords = app(DraftLengthPolicy::class)->count($rewrittenContent);
    $fixture['plan']->update([
        'target_words' => $targetWords,
        'scene_plans' => [[
            'goal' => '抵达洛阳',
            'conflict' => '冲过守卫阻拦',
            'turn' => '回身确认城门状态',
            'outcome' => '城门关闭',
            'outcome_allowed' => ['城门关闭'],
            'outcome_forbidden' => [],
            'continuity_requirements' => [],
            'transition_from_previous' => null,
        ]],
    ]);
    $fixture['scene']->update([
        'goal' => '抵达洛阳',
        'conflict' => '冲过守卫阻拦',
        'turn' => '回身确认城门状态',
        'outcome' => '城门关闭',
    ]);
    $coverageFinding = [
        'code' => 'SCENE_PLAN_COVERAGE_MISSING',
        'dimension' => 'plan',
        'severity' => 'error',
        'scene_id' => $fixture['scene']->getKey(),
        'scope' => 'scene',
        'auto_fixable' => true,
        'requires_human_decision' => false,
        'plan_element' => 'goal',
        'coverage_status' => 'missing',
        'expected' => '抵达洛阳',
        'evidence' => null,
        'message' => '旧 Coverage 判断目标未落实。',
        'source' => 'assembly_coverage',
    ];
    $coverageDraft = GenerationArtifact::factory()->for($fixture['draft']->generationRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'version' => 2,
        'content' => $fixture['draft']->content,
        'checksum' => $fixture['draft']->checksum,
        'data' => [
            'scene_coverage' => [[
                'scene_id' => $fixture['scene']->getKey(),
                'goal' => ['status' => 'missing', 'evidence' => null],
                'conflict' => ['status' => 'fulfilled', 'evidence' => '城门'],
                'turn' => ['status' => 'fulfilled', 'evidence' => '林舟终于抵达洛阳城下'],
                'outcome' => ['status' => 'fulfilled', 'evidence' => '城门在身后关闭'],
                'coverage_evidence_unverified' => [],
                'foreshadowing_coverage' => [],
            ]],
            'plan_findings' => [$coverageFinding],
        ],
    ]);
    $legacyReviewRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
        'state_version' => $fixture['novel']->fresh()->canonicalStateVersion?->version,
    ]);
    $legacyReviewArtifact = GenerationArtifact::factory()->for($legacyReviewRun)->create([
        'type' => ArtifactType::ReviewResult,
        'data' => [
            'source_artifact_id' => $coverageDraft->getKey(),
            'rewrite_scope' => [
                'scope' => 'scene',
                'scene_id' => $fixture['scene']->getKey(),
                'reason' => 'single_scene_affected',
                'finding_indexes' => [0],
            ],
        ],
    ]);
    Review::factory()->for($legacyReviewRun)->create([
        'artifact_id' => $legacyReviewArtifact->getKey(),
        'decision' => ReviewDecision::Rewrite,
        'findings' => [$coverageFinding],
    ]);

    // 响应顺序对应 Coverage Judgment、旧稿事件、Rewrite、新稿事件和最终语义 Review。
    $fake = (new FakeAiProvider)
        ->enqueue(legacyRewriteCoverageJudgmentResponse($fixture['draft']->content))
        ->enqueue(legacyRewriteEventResponse($fixture['chapter'], '城门在身后关闭'))
        ->enqueue(legacyRewriteSceneResponse($rewrittenContent))
        ->enqueue(legacyRewriteEventResponse($fixture['chapter'], '城门已关闭'))
        ->enqueue(legacyRewritePassReviewResponse($fixture['chapter'], '城门已关闭'));
    app()->instance(AiProvider::class, $fake);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->callAction(TestAction::make('rewriteScene')->schemaComponent('narrative-review', 'content'))
        ->assertHasNoActionErrors()
        ->assertNotified('Admission v1 修复流程已恢复');

    $recoveryRun = GenerationRun::query()
        ->where('chapter_id', $fixture['chapter']->getKey())
        ->where('stage', GenerationStage::ChapterRecovery)
        ->sole();
    $handled = runQueuedLegacyRewriteRecoveryPipeline();
    $chapter = $fixture['chapter']->fresh();
    $reviews = Review::query()
        ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
        ->oldest('id')
        ->get();
    $latestDraft = GenerationArtifact::query()
        ->where('type', ArtifactType::ChapterDraft)
        ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
        ->latest('id')
        ->firstOrFail();
    $latestCandidate = GenerationArtifact::query()
        ->where('type', ArtifactType::EventCandidate)
        ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
        ->latest('id')
        ->firstOrFail();
    $latestPatch = GenerationArtifact::query()
        ->where('type', ArtifactType::StatePatch)
        ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
        ->latest('id')
        ->firstOrFail();
    $providerRuns = GenerationRun::query()
        ->where('chapter_id', $chapter->getKey())
        ->where('id', '>', $recoveryRun->getKey())
        ->whereIn('stage', [
            GenerationStage::CoverageJudgment,
            GenerationStage::EventExtraction,
            GenerationStage::Rewrite,
            GenerationStage::Review,
        ])
        ->where('provider', '!=', 'deterministic')
        ->get();

    expect($chapter->status)->toBe(ChapterStatus::Review)
        ->and($reviews->pluck('decision')->all())->toBe([
            ReviewDecision::Rewrite,
            ReviewDecision::Rewrite,
            ReviewDecision::Pass,
        ])
        ->and($fixture['scene']->fresh()->currentArtifact?->type)->toBe(ArtifactType::RewriteDraft)
        ->and($latestDraft->content)->toBe($rewrittenContent)
        ->and((int) data_get($latestCandidate->data, 'source_artifact_id'))->toBe($latestDraft->getKey())
        ->and((int) data_get($latestPatch->data, 'source_artifact_id'))->toBe($latestCandidate->getKey())
        ->and($providerRuns)->toHaveCount(5)
        ->and($providerRuns->pluck('context_snapshot')
            ->map(fn (array $snapshot): int => (int) data_get($snapshot, 'generation_preferences.recovery_contract_run_id'))
            ->unique()->values()->all())->toBe([$recoveryRun->getKey()])
        ->and(collect($fake->requests())->map(fn ($request): string => (string) data_get($request->metadata, 'stage'))->all())->toBe([
            AiStage::Reviewer->value,
            AiStage::Extractor->value,
            AiStage::Rewrite->value,
            AiStage::Extractor->value,
            AiStage::Reviewer->value,
        ])
        ->and($handled[AdjudicatePlanCoverageJob::class])->toBe(1)
        ->and($handled[RewriteChapterJob::class])->toBe(1)
        ->and($handled[AssembleChapterJob::class])->toBe(1)
        ->and($handled[ExtractStoryEventsJob::class])->toBe(2)
        ->and($handled[ReviewChapterJob::class])->toBe(2)
        ->and(GenerationRun::query()->where('id', '>', $recoveryRun->getKey())->where('status', RunStatus::Failed)->count())->toBe(0)
        ->and($fixture['plan']->fresh()->admission_snapshot)->toBe($originalPlanSnapshot)
        ->and($fixture['historicalRun']->fresh()->toArray())->toBe($originalHistoricalRun)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Queue::assertNotPushed(PlanChapterJob::class);
    Queue::assertNotPushed(GenerateSceneJob::class);
});

test('rewrite button uses the review frozen scene instead of accepting an arbitrary scene', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    // 把测试 Plan 恢复为完整 Admission v2，验证普通章节不会创建旧合同恢复 Run。
    freezeChapterRouteContractsForTest($fixture['plan']);
    $fixture['chapter']->update(['status' => ChapterStatus::Rewrite]);
    $reviewRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
        'state_version' => $fixture['novel']->fresh()->canonicalStateVersion?->version,
    ]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create([
        'type' => ArtifactType::ReviewResult,
        'data' => [
            'source_artifact_id' => $fixture['draft']->getKey(),
            'rewrite_scope' => [
                'scope' => 'scene',
                'scene_id' => $fixture['scene']->getKey(),
                'reason' => 'single_scene_affected',
                'finding_indexes' => [0],
            ],
        ],
    ]);
    Review::factory()->for($reviewRun)->create([
        'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::Rewrite,
        'findings' => [[
            'code' => 'STYLE_MISMATCH',
            'scene_id' => $fixture['scene']->getKey(),
            'scope' => 'scene',
            'auto_fixable' => true,
            'requires_human_decision' => false,
        ]],
    ]);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->callAction(TestAction::make('rewriteScene')->schemaComponent('narrative-review', 'content'))
        ->assertHasNoActionErrors()
        ->assertNotified('局部重写已加入队列');

    Queue::assertPushed(RewriteChapterJob::class, fn (RewriteChapterJob $job): bool => $job->chapterId === $fixture['chapter']->getKey()
        && $job->sceneId === $fixture['scene']->getKey());
    expect(GenerationRun::query()->where('stage', GenerationStage::ChapterRecovery)->doesntExist())->toBeTrue();
});

test('rewrite button reports an unresolved scope before dispatching any job', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    freezeChapterRouteContractsForTest($fixture['plan']);
    $fixture['chapter']->update(['status' => ChapterStatus::Rewrite]);
    $reviewRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
        'state_version' => $fixture['novel']->fresh()->canonicalStateVersion?->version,
    ]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create([
        'type' => ArtifactType::ReviewResult,
        'data' => ['source_artifact_id' => $fixture['draft']->getKey()],
    ]);
    Review::factory()->for($reviewRun)->create([
        'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::Rewrite,
        'findings' => [[
            // 章节结构问题必须转入 Plan/Scene 重建，不能让按钮伪装成整章 Rewrite。
            'code' => 'CHAPTER_PLAN_MISMATCH',
            'scene_id' => null,
            'scope' => 'chapter',
            'auto_fixable' => true,
            'requires_human_decision' => false,
        ]],
    ]);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->callAction(TestAction::make('rewriteScene')->schemaComponent('narrative-review', 'content'))
        ->assertHasNoActionErrors()
        ->assertNotified('无法启动局部重写');

    Queue::assertNothingPushed();
    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Rewrite);
});

test('the old event recovery action name delegates to complete chapter recovery', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();

    $run = app(RecoverLegacyEventExtractionAction::class)->handle($fixture['chapter']);

    expect($run->stage)->toBe(GenerationStage::ChapterRecovery)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and(GenerationRun::query()
            ->where('chapter_id', $fixture['chapter']->getKey())
            ->where('stage', GenerationStage::EventExtraction)
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->count())->toBe(0);
});

test('complete recovery resume reuses its frozen contract after current model configuration changes', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $first = app(RecoverLegacyChapterPipelineAction::class)->handle($fixture['chapter']);
    app(GenerationJobDispatcher::class)->release(new ExtractStoryEventsJob($fixture['chapter']->getKey()));
    $fixture['chapter']->update(['status' => ChapterStatus::Blocked]);
    AIModelPrice::query()->update(['is_enabled' => false]);

    $resumed = app(RecoverLegacyChapterPipelineAction::class)->handle($fixture['chapter']);

    expect($resumed->getKey())->toBe($first->getKey())
        ->and(GenerationRun::query()->where('stage', GenerationStage::ChapterRecovery)->count())->toBe(1)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating);
});

test('complete legacy admission recovery freezes every downstream provider stage and preserves old data', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $planSnapshot = $fixture['plan']->fresh()->admission_snapshot;
    $historicalRun = $fixture['historicalRun']->fresh()->toArray();

    $run = app(RecoverLegacyChapterPipelineAction::class)->handle($fixture['chapter']);
    $contract = $run->artifacts()->where('type', ArtifactType::Context)->firstOrFail()->data;

    expect($run->stage)->toBe(GenerationStage::ChapterRecovery)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->provider)->toBe('deterministic')
        ->and(data_get($run->context_snapshot, 'provider_request_count'))->toBe(0)
        ->and(data_get($contract, 'contract_version'))->toBe(LegacyAdmissionRecoveryContract::CONTRACT_VERSION)
        ->and(array_keys((array) data_get($contract, 'routes')))->toBe([
            AiStage::Extractor->value,
            AiStage::Reviewer->value,
            AiStage::Rewrite->value,
            AiStage::Summary->value,
        ]);

    foreach (LegacyAdmissionRecoveryContract::PROVIDER_STAGES as $stage) {
        expect(data_get($contract, "routes.{$stage->value}.provider"))->not->toBeEmpty()
            ->and(data_get($contract, "routes.{$stage->value}.model"))->not->toBeEmpty()
            ->and(data_get($contract, "routes.{$stage->value}.prompt_version"))->not->toBeEmpty()
            ->and(data_get($contract, "routes.{$stage->value}.model_capacity.max_output_tokens"))->toBeGreaterThan(0)
            ->and(data_get($contract, "routes.{$stage->value}.request_budgets.initial.max_completion_tokens"))->toBeGreaterThan(0);
    }

    expect($fixture['plan']->fresh()->admission_snapshot)->toBe($planSnapshot)
        ->and($fixture['historicalRun']->fresh()->toArray())->toBe($historicalRun)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating);
    Queue::assertPushed(ExtractStoryEventsJob::class);
    Queue::assertNotPushed(PlanChapterJob::class);
    Queue::assertNotPushed(GenerateSceneJob::class);
});

test('complete recovery event worker uses the frozen contract without a special job run id', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $fake = (new FakeAiProvider)->enqueue(successfulLegacyEventRecoveryResponse($fixture['chapter']));
    app()->instance(AiProvider::class, $fake);
    $contractRun = app(RecoverLegacyChapterPipelineAction::class)->handle($fixture['chapter']);

    (new ExtractStoryEventsJob($fixture['chapter']->getKey()))
        ->handle(app(StoryEventExtractor::class));

    $eventRun = GenerationRun::query()
        ->where('chapter_id', $fixture['chapter']->getKey())
        ->where('stage', GenerationStage::EventExtraction)
        ->where('id', '>', $contractRun->getKey())
        ->latest('id')
        ->firstOrFail();
    $route = data_get($contractRun->artifacts()->where('type', ArtifactType::Context)->firstOrFail()->data, 'routes.extractor');

    expect($eventRun->status)->toBe(RunStatus::Succeeded)
        ->and(data_get($eventRun->context_snapshot, 'generation_preferences.recovery_contract_run_id'))->toBe($contractRun->getKey())
        ->and(data_get($eventRun->context_snapshot, 'generation_preferences.route_contract_source'))->toBe(LegacyAdmissionRecoveryContract::CONTRACT_VERSION)
        ->and($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->provider)->toBe(data_get($route, 'provider'))
        ->and($fake->requests()[0]->model)->toBe(data_get($route, 'model'));
    Queue::assertNotPushed(PlanChapterJob::class);
    Queue::assertNotPushed(GenerateSceneJob::class);
});

test('complete recovery blocks an unrelated replacement draft before provider request', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);
    app(RecoverLegacyChapterPipelineAction::class)->handle($fixture['chapter']);
    $newAssemblyRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    GenerationArtifact::factory()->for($newAssemblyRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => '不属于恢复来源链的新正文。',
        'checksum' => hash('sha256', '不属于恢复来源链的新正文。'),
    ]);

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey(), regenerate: true))
        ->toThrow(AiProviderException::class, '不属于 Admission v1 恢复合同');
    expect($fake->requests())->toBe([]);
});

test('complete recovery enters coverage judgment before event extraction when findings are pending', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $assemblyRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    GenerationArtifact::factory()->for($assemblyRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'version' => 2,
        'content' => $fixture['draft']->content,
        'checksum' => $fixture['draft']->checksum,
        'data' => [
            'plan_findings' => [[
                'code' => 'SCENE_PLAN_COVERAGE_MISSING',
                'scene_id' => $fixture['scene']->getKey(),
            ]],
        ],
    ]);

    app(RecoverLegacyChapterPipelineAction::class)->handle($fixture['chapter']);

    Queue::assertPushed(AdjudicatePlanCoverageJob::class, fn (AdjudicatePlanCoverageJob $job): bool => $job->sceneId === $fixture['scene']->getKey());
    Queue::assertNotPushed(ExtractStoryEventsJob::class);
    Queue::assertNotPushed(PlanChapterJob::class);
    Queue::assertNotPushed(GenerateSceneJob::class);
});

test('complete recovery allows deterministic reassembly only from recovery rewrite lineage', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $contractRun = app(RecoverLegacyChapterPipelineAction::class)->handle($fixture['chapter']);
    app(GenerationJobDispatcher::class)->release(new ExtractStoryEventsJob($fixture['chapter']->getKey()));
    Queue::fake();

    $rewriteRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->for($fixture['scene'])->create([
        'scope_type' => 'scene',
        'scope_id' => $fixture['scene']->getKey(),
        'stage' => GenerationStage::Rewrite,
        'status' => RunStatus::Succeeded,
        'context_snapshot' => [
            'generation_preferences' => [
                'route_contract_source' => LegacyAdmissionRecoveryContract::CONTRACT_VERSION,
                'recovery_contract_run_id' => $contractRun->getKey(),
            ],
        ],
    ]);
    $content = '林舟抵达洛阳后重新确认城门已经关闭。';
    $rewrite = GenerationArtifact::factory()->for($rewriteRun)->create([
        'type' => ArtifactType::RewriteDraft,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $fixture['scene']->update([
        'status' => SceneStatus::Draft,
        'current_artifact_id' => $rewrite->getKey(),
    ]);
    $fixture['chapter']->update(['status' => ChapterStatus::Generating]);

    expect(app(AdvanceChapterPipelineAction::class)->handle($fixture['chapter']->getKey()))
        ->toBe(GenerationStage::ChapterAssembly);
    Queue::assertPushed(AssembleChapterJob::class);
    Queue::assertNotPushed(PlanChapterJob::class);
    Queue::assertNotPushed(GenerateSceneJob::class);
});

test('complete recovery keeps the frozen summary route after canonical commit', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $contractRun = app(RecoverLegacyChapterPipelineAction::class)->handle($fixture['chapter']);
    $novel = $fixture['novel']->fresh('canonicalStateVersion');
    $nextState = $novel->storyStateVersions()->create([
        'version' => $novel->canonicalStateVersion->version + 1,
        'chapter_id' => $fixture['chapter']->getKey(),
        'state' => $novel->canonicalStateVersion->state,
        'checksum' => $novel->canonicalStateVersion->checksum,
    ]);
    $novel->update(['canonical_state_version_id' => $nextState->getKey()]);
    $fixture['chapter']->update([
        'status' => ChapterStatus::Canonical,
        'canonical_artifact_id' => $fixture['draft']->getKey(),
    ]);
    $fake = (new FakeAiProvider)->enqueue(successfulLegacySummaryResponse());
    app()->instance(AiProvider::class, $fake);

    $result = app(CanonicalChapterSummaryService::class)->generate($fixture['chapter']->getKey());
    $summaryRun = $result['artifact']->generationRun;
    $summaryRoute = data_get($contractRun->artifacts()->where('type', ArtifactType::Context)->firstOrFail()->data, 'routes.summary');

    expect($result['applied'])->toBeTrue()
        ->and($summaryRun->stage)->toBe(GenerationStage::MemorySummary)
        ->and(data_get($summaryRun->context_snapshot, 'generation_preferences.recovery_contract_run_id'))->toBe($contractRun->getKey())
        ->and($fake->requests()[0]->provider)->toBe(data_get($summaryRoute, 'provider'))
        ->and($fake->requests()[0]->model)->toBe(data_get($summaryRoute, 'model'));
});
