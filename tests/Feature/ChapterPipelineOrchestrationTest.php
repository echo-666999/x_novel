<?php

use App\Actions\Generation\CheckNextAction;
use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\MemoryStatus;
use App\Enums\NovelOutlineStatus;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Jobs\AssembleChapterJob;
use App\Jobs\ContinueAutoGenerationJob;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateCanonicalChapterSummaryJob;
use App\Jobs\GenerateEmbeddingJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\RefreshNovelProjectionJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Review;
use App\Models\Scene;
use App\Models\StoryArc;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\User;
use App\Models\Volume;
use App\Services\CanonicalChapterSummaryService;
use App\Services\MemoryUpdater;
use App\Services\NarrativeStyleProfile;
use App\Services\OutlineCompletionService;
use App\Services\OutlineProgressResolver;
use App\Services\ProjectionRebuilder;
use Database\Factories\Support\NormalizedOutlineDefinition;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->actingAs(User::factory()->create());
});

function chapterPipelineBaseline(): array
{
    return require __DIR__.'/../Fixtures/chapter_pipeline_quality_baseline.php';
}

/** @return array{novel: Novel, bible: NovelBible, character: Character, foreshadowing: Foreshadowing|null} */
function chapterPipelineNovel(bool $withForeshadowing = false): array
{
    $baseline = chapterPipelineBaseline();
    $novel = Novel::factory()->create([
        'title' => '端到端基线小说',
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => null,
        'settings' => [
            'auto_generate' => false,
            'generation' => ['chapter_target_words' => $baseline['chapter_target_words']],
        ],
    ]);
    $bible = NovelBible::factory()->for($novel)->create([
        'tone' => '热血',
        'pov' => '第一人称',
        'tense' => '过去时',
        'style_profile' => [
            ...NovelBible::factory()->make()->style_profile,
            'primary_style' => 'passionate',
            'secondary_styles' => ['light_humorous'],
        ],
    ]);
    $character = Character::factory()->for($novel)->create([
        'name' => '林舟',
        'current_state' => ['location' => '城内'],
    ]);
    $foreshadowing = $withForeshadowing
        ? Foreshadowing::factory()->for($novel)->create([
            'title' => '铜盘上的潮痕',
            'promised_payoff' => '潮痕最终指出灯塔暗门并让林舟打开入口。',
            'importance' => ForeshadowingImportance::Critical,
            'status' => ForeshadowingStatus::Idea,
            'due_from_chapter' => 1,
            'due_to_chapter' => 3,
        ])
        : null;
    app(InitializeNovelStateAction::class)->handle($novel);

    // 端到端夹具提供三个可依次完成的 Main Beat，覆盖连续三章的正式推进。
    $outlineDefinition = NormalizedOutlineDefinition::make();
    $templateBeat = $outlineDefinition['volumes'][0]['arcs'][0]['beats'][0];
    $outlineDefinition['volumes'][0]['arcs'][0]['beats'] = collect(range(1, 3))
        ->map(function (int $sequence) use ($templateBeat): array {
            return [
                ...$templateBeat,
                'key' => "pipeline-beat-{$sequence}",
                'sequence' => $sequence,
                'mainline_sequence' => $sequence,
                'title' => "流水线 Beat {$sequence}",
                'acceptance_criteria' => ["完成第 {$sequence} 章的主线推进。"],
                'milestones' => [[
                    ...$templateBeat['milestones'][0],
                    'key' => "pipeline-milestone-{$sequence}",
                    'title' => "流水线 Milestone {$sequence}",
                    'acceptance_criteria' => ["第 {$sequence} 章正文完成既定推进。"],
                ]],
                'handoff' => [
                    'next_beat_key' => $sequence < 3 ? 'pipeline-beat-'.($sequence + 1) : null,
                    'transition_mode' => $sequence < 3 ? 'next_chapter' : null,
                    'exit_result' => $sequence < 3 ? "第 {$sequence} 章结果已成立。" : null,
                    'next_trigger' => $sequence < 3 ? '承接上一章结果。' : null,
                    'carried_states' => [],
                    'open_threads' => [],
                    'required_transition' => [],
                    'forbidden_jump' => [],
                ],
            ];
        })->all();
    $outline = app(CreateNormalizedNovelOutlineVersionAction::class)->handle($novel, $outlineDefinition);
    $outline->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
    $novel->update(['current_outline_id' => $outline->getKey()]);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    StoryArc::factory()->for($novel)->forVolume($volume)->create(['status' => 'active']);

    return compact('novel', 'bible', 'character', 'foreshadowing');
}

function assertChapterPipelineQualityBaseline(Chapter $chapter): void
{
    $baseline = chapterPipelineBaseline();
    $sceneArtifacts = $chapter->scenes()
        ->with('currentArtifact')
        ->orderBy('sequence')
        ->get()
        ->pluck('currentArtifact');

    foreach ($sceneArtifacts as $artifact) {
        expect(collect(data_get($artifact?->data, 'self_check'))
            ->map(fn (array $item): mixed => $item['status'] ?? null)
            ->all())->toBe($baseline['expected']['scene_plan_adherence']);
    }

    $draft = GenerationArtifact::query()
        ->where('type', ArtifactType::ChapterDraft)
        ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
        ->latest('id')
        ->firstOrFail();
    $review = Review::query()
        ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
        ->latest('id')
        ->firstOrFail();

    expect(data_get($draft->data, 'plan_findings'))->toHaveCount($baseline['expected']['assembly_plan_findings'])
        ->and(collect($review->findings)->where('dimension', 'style'))->toHaveCount($baseline['expected']['final_style_findings']);
}

function assertFrozenPipelineStyle(Chapter $chapter, NovelBible $bible): void
{
    $stages = [
        GenerationStage::ChapterPlanning,
        GenerationStage::SceneGeneration,
        GenerationStage::Review,
        GenerationStage::Rewrite,
    ];
    $runs = $chapter->generationRuns()
        ->whereIn('stage', $stages)
        ->where('status', RunStatus::Succeeded)
        ->get();
    $checksum = app(NarrativeStyleProfile::class)->contractForBible($bible)['checksum'];

    expect($runs)->not->toBeEmpty()
        ->and($runs->pluck('bible_version')->unique()->values()->all())->toBe([$bible->version])
        ->and($runs->pluck('context_snapshot')
            ->map(fn (array $snapshot): mixed => data_get($snapshot, 'style_contract_checksum'))
            ->unique()->values()->all())->toBe([$checksum]);
}

function runQueuedChapterPipeline(?array &$handled = null): void
{
    $jobClasses = [
        PlanChapterJob::class,
        GenerateSceneJob::class,
        AssembleChapterJob::class,
        ExtractStoryEventsJob::class,
        ReviewChapterJob::class,
        RewriteChapterJob::class,
    ];
    $handled ??= array_fill_keys($jobClasses, 0);

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
            return;
        }

        app()->call([$nextJob, 'handle']);
        app(UniqueLock::class)->release($nextJob);
    }

    throw new RuntimeException('章节测试流水线超过 30 个 Job，可能存在重复派发循环。');
}

function runPostCommitChain(Chapter $chapter): void
{
    $chapter->refresh();
    $novel = $chapter->novel->fresh();
    (new UpdateMemoryJob($chapter->getKey()))->handle(app(MemoryUpdater::class));
    (new GenerateCanonicalChapterSummaryJob($chapter->getKey(), (int) $chapter->canonical_artifact_id))
        ->handle(app(CanonicalChapterSummaryService::class));
    (new RefreshNovelProjectionJob($novel->getKey(), (int) $novel->canonical_state_version_id))
        ->handle(app(ProjectionRebuilder::class));
    (new ContinueAutoGenerationJob(
        $chapter->getKey(),
        (int) $chapter->canonical_artifact_id,
        (int) $novel->canonical_state_version_id,
    ))->handle(app(CheckNextAction::class));
}

test('one trigger reaches pass then manual commit creates canonical state memory work and only the next chapter', function () {
    $fixture = chapterPipelineNovel();
    $provider = new ChapterPipelineFixtureProvider(
        $fixture['character']->getKey(),
        chapterPipelineBaseline(),
        [ReviewDecision::Pass],
    );
    app()->instance(AiProvider::class, $provider);
    Queue::fake();

    Livewire::test(ViewNovel::class, ['record' => $fixture['novel']->getRouteKey()])
        ->callAction('startAutoGenerate')
        ->assertNotified('自动生成已开启，章节流水线已启动');

    runQueuedChapterPipeline();

    $chapter = $fixture['novel']->chapters()->where('sequence', 1)->sole();
    $review = Review::query()
        ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
        ->sole();

    expect($chapter->fresh()->status)->toBe(ChapterStatus::Review)
        ->and($review->decision)->toBe(ReviewDecision::Pass)
        ->and($chapter->scenes()->count())->toBe(2)
        ->and(StoryEvent::query()->count())->toBe(0)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(1)
        ->and($provider->stages())->toBe([
            AiStage::Planner->value,
            AiStage::Writer->value,
            AiStage::Writer->value,
            AiStage::Extractor->value,
            AiStage::Reviewer->value,
        ]);

    expect($chapter->generationRuns()->where('stage', GenerationStage::ChapterPlanning)->count())->toBe(1)
        ->and($chapter->generationRuns()->where('stage', GenerationStage::SceneGeneration)->count())->toBe(2)
        ->and($chapter->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(1)
        ->and($chapter->generationRuns()->where('stage', GenerationStage::EventExtraction)->count())->toBe(1)
        ->and($chapter->generationRuns()->where('stage', GenerationStage::Review)->count())->toBe(1)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::StatePatch)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->count())->toBe(1);

    assertChapterPipelineQualityBaseline($chapter);
    assertFrozenPipelineStyle($chapter, $fixture['bible']);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->callAction('commitCanonical')
        ->assertNotified('章节已提交为正式版本');

    expect($fixture['novel']->chapters()->where('sequence', 2)->doesntExist())->toBeTrue();
    runPostCommitChain($chapter);
    $nextChapter = $fixture['novel']->chapters()->where('sequence', 2)->sole();

    expect($chapter->fresh()->status)->toBe(ChapterStatus::Canonical)
        ->and($fixture['novel']->fresh()->current_chapter_sequence)->toBe(1)
        ->and(StoryEvent::query()->where('chapter_id', $chapter->getKey())->count())->toBe(3)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(2)
        ->and(data_get($fixture['novel']->fresh()->canonicalStateVersion->state, 'characters.'.$fixture['character']->getKey().'.location'))->toBe('灯塔')
        ->and($nextChapter->status)->toBe(ChapterStatus::Planned)
        ->and($fixture['novel']->chapters()->where('sequence', '>', 2)->doesntExist())->toBeTrue();

    Queue::assertPushed(UpdateMemoryJob::class, fn (UpdateMemoryJob $job): bool => $job->chapterId === $chapter->getKey());
    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $nextChapter->getKey());

    expect(Memory::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(3);
    Queue::assertPushed(GenerateEmbeddingJob::class, 3);
});

test('one trigger performs a targeted scene rewrite and revalidates fresh downstream artifacts before pass', function () {
    $fixture = chapterPipelineNovel();
    $provider = new ChapterPipelineFixtureProvider(
        $fixture['character']->getKey(),
        chapterPipelineBaseline(),
        [ReviewDecision::Rewrite, ReviewDecision::Pass],
    );
    app()->instance(AiProvider::class, $provider);
    Queue::fake();

    Livewire::test(ViewNovel::class, ['record' => $fixture['novel']->getRouteKey()])
        ->callAction('generateNextChapter')
        ->assertNotified('章节流水线已启动');

    runQueuedChapterPipeline();

    $chapter = $fixture['novel']->chapters()->sole();
    $reviews = Review::query()
        ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
        ->oldest('id')
        ->get();
    $latestDraft = GenerationArtifact::query()
        ->where('type', ArtifactType::ChapterDraft)
        ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
        ->latest('id')
        ->firstOrFail();
    $candidate = GenerationArtifact::query()
        ->where('type', ArtifactType::EventCandidate)
        ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
        ->latest('id')
        ->firstOrFail();
    $patch = GenerationArtifact::query()
        ->where('type', ArtifactType::StatePatch)
        ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
        ->latest('id')
        ->firstOrFail();

    expect($provider->stages())->toContain(AiStage::Rewrite->value)
        ->and($chapter->fresh()->status)->toBe(ChapterStatus::Review)
        ->and($reviews->pluck('decision')->all())->toBe([ReviewDecision::Rewrite, ReviewDecision::Pass])
        ->and($chapter->generationRuns()->where('stage', GenerationStage::Rewrite)->whereNotNull('scene_id')->count())->toBe(1)
        ->and($chapter->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(2)
        ->and($chapter->generationRuns()->where('stage', GenerationStage::EventExtraction)->count())->toBe(2)
        ->and((int) data_get($candidate->data, 'source_artifact_id'))->toBe($latestDraft->getKey())
        ->and((int) data_get($patch->data, 'source_artifact_id'))->toBe($candidate->getKey())
        ->and(StoryEvent::query()->count())->toBe(0);

    assertChapterPipelineQualityBaseline($chapter);
    assertFrozenPipelineStyle($chapter, $fixture['bible']);
});

test('one foreshadowing crosses the full chapter pipeline from idea to paid off and feeds the next chapter', function () {
    $fixture = chapterPipelineNovel(withForeshadowing: true);
    $foreshadowing = $fixture['foreshadowing'];
    $provider = new ChapterPipelineFixtureProvider(
        $fixture['character']->getKey(),
        chapterPipelineBaseline(),
        [ReviewDecision::Pass, ReviewDecision::Pass, ReviewDecision::Pass],
        $foreshadowing->getKey(),
    );
    app()->instance(AiProvider::class, $provider);
    Queue::fake();
    $handled = null;

    Livewire::test(ViewNovel::class, ['record' => $fixture['novel']->getRouteKey()])
        ->callAction('startAutoGenerate')
        ->assertNotified('自动生成已开启，章节流水线已启动');

    $expected = [
        1 => [EventType::ForeshadowingPlanted, ForeshadowingStatus::Planted, 0],
        2 => [EventType::ForeshadowingReinforced, ForeshadowingStatus::Reinforced, 1],
        3 => [EventType::ForeshadowingPaidOff, ForeshadowingStatus::PaidOff, 1],
    ];

    foreach ($expected as $sequence => [$eventType, $status, $reinforceCount]) {
        runQueuedChapterPipeline($handled);
        $chapter = $fixture['novel']->chapters()->where('sequence', $sequence)->sole();
        $review = Review::query()
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->latest('id')
            ->firstOrFail();
        $contextRun = $chapter->generationRuns()
            ->where('stage', GenerationStage::SceneGeneration)
            ->orderBy('id')
            ->firstOrFail();

        expect($chapter->fresh()->status)->toBe(ChapterStatus::Review)
            ->and($review->decision)->toBe(ReviewDecision::Pass)
            ->and($fixture['novel']->storyEvents()->count())->toBe(($sequence - 1) * 3)
            ->and($fixture['novel']->fresh()->canonicalStateVersion->version)->toBe($sequence - 1)
            ->and($contextRun->state_version)->toBe($sequence - 1)
            ->and(data_get($contextRun->context_snapshot, 'l0.foreshadowing_contract.actions.0.content_status'))
            ->toBe(match ($sequence) {
                1 => ForeshadowingStatus::Idea->value,
                2 => ForeshadowingStatus::Planted->value,
                3 => ForeshadowingStatus::Reinforced->value,
            });

        Livewire::test(ViewNovelChapter::class, [
            'record' => $fixture['novel']->getRouteKey(),
            'chapter' => $chapter->getRouteKey(),
        ])->callAction('commitCanonical')->assertNotified('章节已提交为正式版本');

        runPostCommitChain($chapter);
        $novel = $fixture['novel']->fresh();

        expect($chapter->fresh()->status)->toBe(ChapterStatus::Canonical)
            ->and($novel->canonicalStateVersion->version)->toBe($sequence)
            ->and(data_get($novel->canonicalStateVersion->state, "foreshadowings.{$foreshadowing->getKey()}.status"))->toBe($status->value)
            ->and(data_get($novel->canonicalStateVersion->state, "foreshadowings.{$foreshadowing->getKey()}.reinforce_count"))->toBe($reinforceCount)
            ->and($foreshadowing->fresh()->status)->toBe($status)
            ->and($foreshadowing->fresh()->reinforce_count)->toBe($reinforceCount)
            ->and($chapter->storyEvents()->active()->where('event_type', $eventType->value)->sole()->event_type)->toBe($eventType)
            ->and(Memory::query()->where('novel_id', $novel->getKey())
                ->where('source_type', 'story_event')
                ->where('source_id', $chapter->storyEvents()->active()->where('event_type', $eventType->value)->sole()->getKey())
                ->where('status', MemoryStatus::Active)
                ->exists())->toBeTrue();
    }

    expect($foreshadowing->fresh()->setup_chapter_id)->toBe(
        $fixture['novel']->chapters()->where('sequence', 1)->value('id'),
    )->and($foreshadowing->fresh()->payoff_chapter_id)->toBe(
        $fixture['novel']->chapters()->where('sequence', 3)->value('id'),
    )->and(app(ProjectionRebuilder::class)->inspect($fixture['novel']->fresh())->isHealthy())->toBeTrue()
        ->and(Memory::query()->where('novel_id', $fixture['novel']->getKey())->where('status', MemoryStatus::Active)->count())->toBe(9);
});

final class ChapterPipelineFixtureProvider implements AiProvider
{
    /** @var array<int, AiRequest> */
    private array $requests = [];

    private int $reviewIndex = 0;

    /** @param array<int, ReviewDecision> $reviewDecisions */
    public function __construct(
        private readonly int $characterId,
        private readonly array $baseline,
        private readonly array $reviewDecisions,
        private readonly ?int $foreshadowingId = null,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $stage = (string) data_get($request->metadata, 'stage');
        $payload = match ($stage) {
            AiStage::Planner->value => $this->plan((int) data_get($request->metadata, 'chapter_id')),
            AiStage::Writer->value => $this->scene((int) data_get($request->metadata, 'scene_id')),
            AiStage::Extractor->value => $this->events((int) data_get($request->metadata, 'chapter_id')),
            AiStage::Reviewer->value => $this->review((int) data_get($request->metadata, 'chapter_id')),
            AiStage::Rewrite->value => $this->rewrite((int) data_get($request->metadata, 'scene_id')),
            AiStage::Summary->value => [
                'summary' => '本章正式摘要。',
                'key_events' => ['本章核心事件完成。'],
                'character_changes' => [],
                'unresolved_threads' => [],
            ],
            default => throw new RuntimeException("端到端 Fake Provider 不支持 Stage [{$stage}]。"),
        };

        return new AiResponse(
            content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            structuredData: $payload,
            inputTokens: 10,
            outputTokens: 20,
            cachedTokens: 0,
            latencyMs: 5,
            providerRequestId: 'pipeline-fixture-'.count($this->requests),
            model: 'pipeline-fixture',
        );
    }

    /** @return array<int, string> */
    public function stages(): array
    {
        return array_map(
            fn (AiRequest $request): string => (string) data_get($request->metadata, 'stage'),
            $this->requests,
        );
    }

    /** @return array<string, mixed> */
    private function plan(int $chapterId): array
    {
        $chapter = Chapter::query()->with('novel')->findOrFail($chapterId);
        $chapterSequence = $chapter->sequence;
        $target = app(OutlineProgressResolver::class)->resolve($chapter->novel);

        return [
            'novel_outline_id' => $target->outlineId,
            'chapter_function' => '迫使主角离开安全区',
            'arc_contribution' => '推进灯塔主线',
            'arc_contributions' => [[
                'role' => 'primary',
                'arc_id' => $target->arcId,
                'beat_key' => $target->beat['key'],
                'beat_index' => $target->beat['sequence'],
                'milestone_key' => $target->milestone['key'],
                'milestone_sequence' => $target->milestone['sequence'],
                'target_scene_sequence' => 1,
                'acceptance_criteria' => $target->milestone['acceptance_criteria'][0],
            ]],
            'character_candidates' => [],
            'reader_promise' => '主角抵达灯塔水域',
            'target_words' => $this->baseline['chapter_target_words'],
            'pov_character_id' => $this->characterId,
            'tone' => '热血',
            'time_anchor' => '当日黄昏',
            'hook_type' => '悬念',
            'must_reveal' => ['灯塔仍在运转'],
            'may_hint' => [],
            'must_not_reveal' => ['幕后主使身份'],
            'required_facts' => [],
            'forbidden_conflicts' => [],
            'foreshadowing_actions' => $this->foreshadowingAction($chapterSequence),
            'world_entity_candidates' => [],
            'scene_plans' => collect($this->baseline['scenes'])->map(fn (array $scene, int $index): array => [
                'goal' => $scene['goal'],
                'conflict' => $scene['conflict'],
                'turn' => $scene['turn'],
                'outcome' => $scene['outcome'],
                'outcome_allowed' => $scene['outcome_allowed'],
                'outcome_forbidden' => $scene['outcome_forbidden'],
                'continuity_requirements' => [],
                'pov_character_id' => $this->characterId,
                'location' => $scene['location'],
                'time_anchor' => $scene['time_anchor'],
                'transition_from_previous' => $index === 0
                    ? ($chapterSequence > 1 ? '承接上一章抵达灯塔后的行动。' : null)
                    : '承接上一场景的出港行动',
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function scene(int $sceneId): array
    {
        $scene = Scene::query()->findOrFail($sceneId);
        $content = $this->foreshadowingId !== null && $scene->sequence === 1
            ? '我冲进旧港，'.$this->foreshadowingEvidence($scene->chapter->sequence)
            : $this->baseline['scenes'][$scene->sequence - 1]['content'];

        return [
            'content' => $content,
            'temporary_state_delta' => '{}',
            'declared_events' => [],
            'uncertainties' => [],
            'self_check' => $this->coverage($content),
            'foreshadowing_coverage' => $this->foreshadowingCoverage($scene),
        ];
    }

    /** @return array<string, mixed> */
    private function assembly(int $chapterId): array
    {
        $scenes = Scene::query()
            ->where('chapter_id', $chapterId)
            ->with('currentArtifact')
            ->orderBy('sequence')
            ->get();
        $content = $scenes->pluck('currentArtifact.content')->implode('');

        return [
            'content' => $content,
            'scene_coverage' => $scenes->map(fn (Scene $scene): array => [
                'scene_id' => $scene->getKey(),
                ...$this->coverage((string) $scene->currentArtifact?->content),
                'foreshadowing_coverage' => $this->foreshadowingCoverage($scene),
            ])->all(),
            'introduced_major_facts' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function events(int $chapterId): array
    {
        $chapter = Chapter::query()->with('latestPlan')->findOrFail($chapterId);
        $draft = GenerationArtifact::query()
            ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapterId)->whereNull('scene_id'))
            ->latest('id')
            ->firstOrFail();
        $quote = (string) Scene::query()
            ->where('chapter_id', $chapterId)
            ->with('currentArtifact')
            ->orderBy('sequence')
            ->firstOrFail()
            ->currentArtifact?->content;
        if ($this->foreshadowingId !== null) {
            $scene = $chapter->scenes()->orderBy('sequence')->firstOrFail();

            return [
                'events' => [[
                    'event_type' => $this->foreshadowingEventType($chapter->sequence)->value,
                    'subject_type' => 'foreshadowing',
                    'subject_id' => (string) $this->foreshadowingId,
                    'payload' => '{}',
                    'evidence' => [[
                        'artifact_id' => $draft->getKey(),
                        'scene_id' => $scene->getKey(),
                        'quote' => $this->foreshadowingEvidence($chapter->sequence),
                        'start_offset' => null,
                        'end_offset' => null,
                    ]],
                    'story_time' => "第{$chapter->sequence}日夜晚",
                    'confidence' => 0.98,
                ]],
                'outline_completion' => $this->outlineCompletion($chapter),
            ];
        }

        return [
            'events' => [[
                'event_type' => EventType::CharacterMoved->value,
                'subject_type' => 'character',
                'subject_id' => (string) $this->characterId,
                'payload' => ['from' => '城内', 'to' => '灯塔'],
                'evidence' => [[
                    'artifact_id' => $draft->getKey(),
                    'scene_id' => null,
                    'quote' => $quote,
                    'start_offset' => null,
                    'end_offset' => null,
                ]],
                'story_time' => '第一日夜晚',
                'confidence' => 0.98,
            ]],
            'outline_completion' => $this->outlineCompletion($chapter),
        ];
    }

    /** @return array<string, mixed> */
    private function review(int $chapterId): array
    {
        $chapter = Chapter::query()->with(['latestPlan', 'scenes.currentArtifact'])->findOrFail($chapterId);
        $primary = collect($chapter->latestPlan?->arc_contributions ?? [])->firstWhere('role', 'primary');
        $targetScene = $chapter->scenes->firstWhere('sequence', (int) $primary['target_scene_sequence']);
        $decision = $this->reviewDecisions[$this->reviewIndex] ?? ReviewDecision::Pass;
        $this->reviewIndex++;
        $findings = [];

        if ($decision === ReviewDecision::Rewrite) {
            $scene = Scene::query()->where('chapter_id', $chapterId)->orderBy('sequence')->firstOrFail();
            $findings[] = [
                'code' => 'PLAN_DEVIATION',
                'dimension' => 'plan',
                'severity' => 'error',
                'scene_id' => $scene->getKey(),
                'scope' => 'scene',
                'auto_fixable' => true,
                'requires_human_decision' => false,
                'message' => '旧港突破动作需要更明确。',
                'evidence' => (string) $scene->currentArtifact?->content,
            ];
        }

        return [
            'recommended_decision' => $decision->value,
            'scores' => [
                'continuity' => 92,
                'plan' => 92,
                'character' => 92,
                'progress' => 92,
                'repetition' => 92,
                'pacing' => 92,
                'style' => 92,
            ],
            'dimension_audits' => collect(['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'])
                ->mapWithKeys(fn (string $dimension): array => [$dimension => [
                    'status' => collect($findings)->contains(fn (array $finding): bool => $finding['dimension'] === $dimension)
                        ? 'issues_found'
                        : 'pass',
                    'summary' => collect($findings)->contains(fn (array $finding): bool => $finding['dimension'] === $dimension)
                        ? '已一次列出该维度发现的全部问题。'
                        : '全量检查未发现需要报告的问题。',
                ]])
                ->all(),
            'foreshadowing_audits' => $this->foreshadowingId === null ? [] : [[
                'foreshadowing_id' => $this->foreshadowingId,
                'action' => $this->foreshadowingActionName(Chapter::query()->findOrFail($chapterId)->sequence),
                'target_scene_sequence' => 1,
                'status' => 'fulfilled',
                'summary' => '正文、Coverage 和 Event Candidate 已共同完成本章冻结动作。',
                'evidence' => $this->foreshadowingEvidence(Chapter::query()->findOrFail($chapterId)->sequence),
            ]],
            'chapter_plan_completion' => [
                'status' => 'fulfilled',
                'evidence' => $targetScene->currentArtifact->content,
                'scene_id' => $targetScene->getKey(),
            ],
            ...$this->outlineCompletion($chapter),
            'arc_beat_audits' => [[
                'arc_id' => $primary['arc_id'],
                'beat_key' => $primary['beat_key'],
                'status' => 'fulfilled',
                'evidence' => $targetScene->currentArtifact->content,
                'scene_id' => $targetScene->getKey(),
            ]],
            'arc_completion_audits' => [[
                'arc_id' => $primary['arc_id'],
                'status' => 'not_met',
                'evidence' => null,
            ]],
            'character_candidate_audits' => [],
            'world_entity_candidate_audits' => [],
            'unapproved_characters' => [],
            'unapproved_world_entities' => [],
            'findings' => $findings,
        ];
    }

    /** @return array<string, mixed> */
    private function outlineCompletion(Chapter $chapter): array
    {
        $contract = app(OutlineCompletionService::class)->contract($chapter);
        $scene = $chapter->scenes()->with('currentArtifact')->orderBy('sequence')->firstOrFail();
        $evidence = (string) $scene->currentArtifact?->content;
        $criteria = fn (array $items): array => collect($items)->map(fn (string $criterion): array => [
            'criterion' => $criterion,
            'status' => 'fulfilled',
            'evidence' => $evidence,
            'scene_id' => $scene->getKey(),
        ])->all();

        return [
            'milestone_completion' => ['status' => 'fulfilled', 'criteria' => $criteria($contract['milestone_criteria'])],
            'beat_exit' => ['status' => 'fulfilled', 'criteria' => $criteria($contract['beat_exit_criteria'])],
            'handoff_readiness' => $contract['handoff_next_beat_id'] === null
                ? ['status' => 'not_applicable', 'checks' => []]
                : ['status' => 'ready', 'checks' => collect($contract['handoff_checks'])->map(fn (array $check): array => [
                    ...$check,
                    'status' => 'fulfilled',
                    'evidence' => $evidence,
                    'scene_id' => $scene->getKey(),
                ])->all()],
        ];
    }

    /** @return array<string, mixed> */
    private function rewrite(int $sceneId): array
    {
        $scene = Scene::query()->findOrFail($sceneId);
        $content = $this->baseline['scenes'][$scene->sequence - 1]['rewritten_content'];

        return [
            'content' => $content,
            'self_check' => $this->coverage($content),
        ];
    }

    /** @return array<string, array{status: string, evidence: string}> */
    private function coverage(string $content): array
    {
        $fulfilled = ['status' => 'fulfilled', 'evidence' => $content];

        return [
            'goal' => $fulfilled,
            'conflict' => $fulfilled,
            'turn' => $fulfilled,
            'outcome' => $fulfilled,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function foreshadowingAction(int $chapterSequence): array
    {
        if ($this->foreshadowingId === null) {
            return [];
        }

        return [[
            'foreshadowing_id' => $this->foreshadowingId,
            'action' => $this->foreshadowingActionName($chapterSequence),
            'target_scene_sequence' => 1,
            'acceptance_criteria' => match ($chapterSequence) {
                1 => '正文首次写出铜盘潮痕亮起。',
                2 => '正文写出潮痕延伸并提供新的方向信息。',
                3 => '正文写出林舟依照潮痕打开灯塔暗门。',
            },
            'reason' => null,
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function foreshadowingCoverage(Scene $scene): array
    {
        if ($this->foreshadowingId === null || $scene->sequence !== 1) {
            return [];
        }

        return [[
            'foreshadowing_id' => $this->foreshadowingId,
            'action' => $this->foreshadowingActionName($scene->chapter->sequence),
            'status' => 'fulfilled',
            'evidence' => $this->foreshadowingEvidence($scene->chapter->sequence),
        ]];
    }

    private function foreshadowingActionName(int $chapterSequence): string
    {
        return match ($chapterSequence) {
            1 => 'plant',
            2 => 'reinforce',
            3 => 'pay_off',
        };
    }

    private function foreshadowingEventType(int $chapterSequence): EventType
    {
        return match ($chapterSequence) {
            1 => EventType::ForeshadowingPlanted,
            2 => EventType::ForeshadowingReinforced,
            3 => EventType::ForeshadowingPaidOff,
        };
    }

    private function foreshadowingEvidence(int $chapterSequence): string
    {
        if ($this->foreshadowingId === null) {
            return '';
        }

        return match ($chapterSequence) {
            1 => '潮痕在铜盘边缘第一次亮起。',
            2 => '潮痕延伸成通往暗门的箭头。',
            3 => '林舟按下潮痕指向的石钮，暗门轰然开启。',
        };
    }
}
