<?php

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
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Models\User;
use App\Services\GenerationJobDispatcher;
use App\Services\OutlineCompletionService;
use App\Services\PlanAdmissionService;
use App\Services\StoryEventExtractor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
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
    $criteria = static fn (array $items): array => [
        'status' => $items === [] ? 'fulfilled' : 'not_met',
        'criteria' => collect($items)->map(fn (string $criterion): array => [
            'criterion' => $criterion,
            'status' => 'not_met',
            'evidence' => null,
            'scene_id' => null,
        ])->all(),
    ];
    $handoff = $contract['handoff_next_beat_id'] === null
        ? ['status' => 'not_applicable', 'checks' => []]
        : [
            'status' => 'not_ready',
            'checks' => collect($contract['handoff_checks'])->map(fn (array $check): array => [
                ...$check,
                'status' => 'not_met',
                'evidence' => null,
                'scene_id' => null,
            ])->all(),
        ];
    $payload = [
        'events' => [],
        'outline_completion' => [
            'milestone_completion' => $criteria($contract['milestone_criteria']),
            'beat_exit' => $criteria($contract['beat_exit_criteria']),
            'handoff_readiness' => $handoff,
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

test('legacy admission recovery freezes current extractor contract without changing existing sources', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $provider = new FakeAiProvider;
    app()->instance(AiProvider::class, $provider);
    $planSnapshot = $fixture['plan']->fresh()->admission_snapshot;
    $sceneArtifactId = $fixture['scene']->fresh()->current_artifact_id;
    $historicalRun = $fixture['historicalRun']->fresh()->toArray();
    $currentExtractor = app(PlanAdmissionService::class)
        ->currentStageContract($fixture['novel']->fresh(), AiStage::Extractor);

    $run = app(RecoverLegacyEventExtractionAction::class)->handle($fixture['chapter']);

    expect($run->status)->toBe(RunStatus::Queued)
        ->and($run->stage)->toBe(GenerationStage::EventExtraction)
        ->and($run->provider)->not->toBeEmpty()
        ->and($run->model_policy)->not->toBeEmpty()
        ->and($run->prompt_version)->not->toBeEmpty()
        ->and(data_get($run->context_snapshot, 'generation_preferences.frozen_route.provider'))->toBe(data_get($currentExtractor, 'route.provider'))
        ->and(data_get($run->context_snapshot, 'generation_preferences.frozen_route.model'))->toBe(data_get($currentExtractor, 'route.model'))
        ->and(data_get($run->context_snapshot, 'generation_preferences.frozen_route.reasoning_effort'))->toBe(data_get($currentExtractor, 'route.reasoning_effort'))
        ->and(data_get($run->context_snapshot, 'generation_preferences.model_capacity.max_output_tokens'))->toBe(128_000)
        ->and(data_get($run->context_snapshot, 'generation_preferences.request_budgets.initial.reasoning_reserve_tokens'))->toBe(8_000)
        ->and(data_get($run->context_snapshot, 'generation_preferences.request_budgets.retry.reasoning_reserve_tokens'))->toBe(16_000)
        ->and(data_get($run->context_snapshot, 'generation_preferences.request_budgets.final.reasoning_reserve_tokens'))->toBe(24_000)
        ->and($fixture['plan']->fresh()->admission_snapshot)->toBe($planSnapshot)
        ->and($fixture['scene']->fresh()->current_artifact_id)->toBe($sceneArtifactId)
        ->and($fixture['draft']->fresh()->exists)->toBeTrue()
        ->and($fixture['historicalRun']->fresh()->toArray())->toBe($historicalRun)
        ->and($provider->requests())->toBe([]);

    Queue::assertPushed(ExtractStoryEventsJob::class, fn (ExtractStoryEventsJob $job): bool => $job->chapterId === $fixture['chapter']->getKey()
        && $job->recoveryRunId === $run->getKey()
        && $job->regenerate
        && $job->continueRewrite);
    Queue::assertNotPushed(PlanChapterJob::class);
    Queue::assertNotPushed(GenerateSceneJob::class);
});

test('legacy recovery worker uses the run frozen extractor route and initial budget', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $planSnapshot = $fixture['plan']->fresh()->admission_snapshot;
    $fake = (new FakeAiProvider)->enqueue(successfulLegacyEventRecoveryResponse($fixture['chapter']));
    app()->instance(AiProvider::class, $fake);
    $run = app(RecoverLegacyEventExtractionAction::class)->handle($fixture['chapter']);

    (new ExtractStoryEventsJob(
        chapterId: $fixture['chapter']->getKey(),
        regenerate: true,
        continueRewrite: true,
        recoveryRunId: $run->getKey(),
    ))->handle(app(StoryEventExtractor::class));

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->provider)->toBe($run->provider)
        ->and($fake->requests()[0]->model)->toBe($run->model_policy)
        ->and($fake->requests()[0]->reasoningEffort)->toBe(data_get($run->context_snapshot, 'generation_preferences.frozen_route.reasoning_effort'))
        ->and($fake->requests()[0]->maxTokens)->toBe(12_000)
        ->and(data_get($run->context_snapshot, 'generation_preferences.selected_request_budget.reasoning_reserve_tokens'))->toBe(8_000)
        ->and($fixture['plan']->fresh()->admission_snapshot)->toBe($planSnapshot)
        ->and(GenerationArtifact::query()->where('generation_run_id', $run->getKey())->where('type', ArtifactType::EventCandidate)->count())->toBe(1);

    Queue::assertNotPushed(PlanChapterJob::class);
    Queue::assertNotPushed(GenerateSceneJob::class);
});

test('legacy recovery blocks changed source data before any provider request', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);
    $run = app(RecoverLegacyEventExtractionAction::class)->handle($fixture['chapter']);
    $newAssemblyRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    GenerationArtifact::factory()->for($newAssemblyRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => '来源正文已经变化。',
        'checksum' => hash('sha256', '来源正文已经变化。'),
    ]);

    expect(fn () => app(StoryEventExtractor::class)->extract(
        $fixture['chapter']->getKey(),
        regenerate: true,
        recoveryRunId: $run->getKey(),
    ))->toThrow(AiProviderException::class, '事件提取恢复合同冻结后');

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->error_code)->toBe('extractor_recovery_source_changed')
        ->and($fake->requests())->toBe([]);
});

test('legacy recovery persists a damaged frozen contract failure before any provider request', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);
    $run = app(RecoverLegacyEventExtractionAction::class)->handle($fixture['chapter']);
    $snapshot = $run->context_snapshot;
    data_forget($snapshot, 'generation_preferences.request_budgets.final');
    data_forget($snapshot, 'generation_preferences.frozen_route.model');
    $run->update(['context_snapshot' => $snapshot]);

    expect(fn () => app(StoryEventExtractor::class)->extract(
        $fixture['chapter']->getKey(),
        regenerate: true,
        recoveryRunId: $run->getKey(),
    ))->toThrow(AiProviderException::class, '缺少完整冻结路由');

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->error_code)->toBe('extractor_recovery_contract_missing')
        ->and($fake->requests())->toBe([]);
});

test('legacy recovery action rejects admission v2 without creating a run', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $snapshot = $fixture['plan']->admission_snapshot;
    $snapshot['schema_version'] = 2;
    $fixture['plan']->update(['admission_snapshot' => $snapshot]);
    $before = GenerationRun::query()->count();

    expect(fn () => app(RecoverLegacyEventExtractionAction::class)->handle($fixture['chapter']))
        ->toThrow(ValidationException::class, '只有 Admission v1');

    expect(GenerationRun::query()->count())->toBe($before);
    Queue::assertNothingPushed();
});

test('pipeline recovery keeps the legacy event recovery run id after worker loss', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();
    $run = app(RecoverLegacyEventExtractionAction::class)->handle($fixture['chapter']);
    $run->update([
        'status' => RunStatus::Failed,
        'error_code' => 'worker_lost',
        'error_message' => 'Worker 心跳超时。',
        'finished_at' => now(),
    ]);
    $fixture['chapter']->update(['status' => ChapterStatus::Generating]);
    app(GenerationJobDispatcher::class)->release(new ExtractStoryEventsJob(
        chapterId: $fixture['chapter']->getKey(),
        recoveryRunId: $run->getKey(),
    ));
    Queue::fake();

    app(AdvanceChapterPipelineAction::class)->handle($fixture['chapter']->getKey());

    Queue::assertPushed(ExtractStoryEventsJob::class, fn (ExtractStoryEventsJob $job): bool => $job->chapterId === $fixture['chapter']->getKey()
        && $job->recoveryRunId === $run->getKey());
    Queue::assertNotPushed(PlanChapterJob::class);
    Queue::assertNotPushed(GenerateSceneJob::class);
});

test('chapter page exposes the explicit legacy recovery action instead of ordinary extraction', function () {
    Queue::fake();
    $fixture = legacyEventRecoveryFixture();

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->assertActionVisible(TestAction::make('recoverLegacyEventExtraction')->schemaComponent('story-event-candidates', 'content'))
        ->assertActionVisible(TestAction::make('extractStoryEvents')->schemaComponent('story-event-candidates', 'content'))
        ->callAction(TestAction::make('recoverLegacyEventExtraction')->schemaComponent('story-event-candidates', 'content'));

    $run = GenerationRun::query()
        ->where('chapter_id', $fixture['chapter']->getKey())
        ->where('stage', GenerationStage::EventExtraction)
        ->latest('id')
        ->firstOrFail();

    expect($run->status)->toBe(RunStatus::Queued)
        ->and(data_get($run->context_snapshot, 'recovery.source.plan_id'))->toBe($fixture['plan']->getKey());
    Queue::assertPushed(ExtractStoryEventsJob::class, fn (ExtractStoryEventsJob $job): bool => $job->recoveryRunId === $run->getKey());
});
