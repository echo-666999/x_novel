<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Jobs\AssembleChapterJob;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateEmbeddingJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\GenerationArtifact;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Review;
use App\Models\Scene;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\User;
use App\Models\Volume;
use App\Services\MemoryUpdater;
use App\Services\NarrativeStyleProfile;
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

/** @return array{novel: Novel, bible: NovelBible, character: Character} */
function chapterPipelineNovel(): array
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
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    return compact('novel', 'bible', 'character');
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
        GenerationStage::ChapterAssembly,
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

function runQueuedChapterPipeline(): void
{
    $jobClasses = [
        PlanChapterJob::class,
        GenerateSceneJob::class,
        AssembleChapterJob::class,
        ExtractStoryEventsJob::class,
        ReviewChapterJob::class,
        RewriteChapterJob::class,
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
            return;
        }

        app()->call([$nextJob, 'handle']);
        app(UniqueLock::class)->release($nextJob);
    }

    throw new RuntimeException('章节测试流水线超过 30 个 Job，可能存在重复派发循环。');
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
            AiStage::Assembler->value,
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

    $nextChapter = $fixture['novel']->chapters()->where('sequence', 2)->sole();

    expect($chapter->fresh()->status)->toBe(ChapterStatus::Canonical)
        ->and($fixture['novel']->fresh()->current_chapter_sequence)->toBe(1)
        ->and(StoryEvent::query()->where('chapter_id', $chapter->getKey())->count())->toBe(1)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(2)
        ->and(data_get($fixture['novel']->fresh()->canonicalStateVersion->state, 'characters.'.$fixture['character']->getKey().'.location'))->toBe('灯塔')
        ->and($nextChapter->status)->toBe(ChapterStatus::Planned)
        ->and($fixture['novel']->chapters()->where('sequence', '>', 2)->doesntExist())->toBeTrue();

    Queue::assertPushed(UpdateMemoryJob::class, fn (UpdateMemoryJob $job): bool => $job->chapterId === $chapter->getKey());
    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $nextChapter->getKey());

    (new UpdateMemoryJob($chapter->getKey()))->handle(app(MemoryUpdater::class));

    expect(Memory::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(1);
    Queue::assertPushed(GenerateEmbeddingJob::class, 1);
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
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $stage = (string) data_get($request->metadata, 'stage');
        $payload = match ($stage) {
            AiStage::Planner->value => $this->plan(),
            AiStage::Writer->value => $this->scene((int) data_get($request->metadata, 'scene_id')),
            AiStage::Assembler->value => $this->assembly((int) data_get($request->metadata, 'chapter_id')),
            AiStage::Extractor->value => $this->events((int) data_get($request->metadata, 'chapter_id')),
            AiStage::Reviewer->value => $this->review((int) data_get($request->metadata, 'chapter_id')),
            AiStage::Rewrite->value => $this->rewrite((int) data_get($request->metadata, 'scene_id')),
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
    private function plan(): array
    {
        return [
            'chapter_function' => '迫使主角离开安全区',
            'arc_contribution' => '推进灯塔主线',
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
            'due_foreshadowings' => [],
            'scene_plans' => collect($this->baseline['scenes'])->map(fn (array $scene, int $index): array => [
                'goal' => $scene['goal'],
                'conflict' => $scene['conflict'],
                'turn' => $scene['turn'],
                'outcome' => $scene['outcome'],
                'outcome_allowed' => $scene['outcome_allowed'],
                'outcome_forbidden' => $scene['outcome_forbidden'],
                'pov_character_id' => $this->characterId,
                'location' => $scene['location'],
                'time_anchor' => $scene['time_anchor'],
                'transition_from_previous' => $index === 0 ? null : '承接上一场景的出港行动',
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function scene(int $sceneId): array
    {
        $scene = Scene::query()->findOrFail($sceneId);
        $content = $this->baseline['scenes'][$scene->sequence - 1]['content'];

        return [
            'content' => $content,
            'temporary_state_delta' => '{}',
            'declared_events' => [],
            'uncertainties' => [],
            'self_check' => $this->coverage($content),
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
            ])->all(),
            'introduced_major_facts' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function events(int $chapterId): array
    {
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

        return ['events' => [[
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
        ]]];
    }

    /** @return array<string, mixed> */
    private function review(int $chapterId): array
    {
        $decision = $this->reviewDecisions[$this->reviewIndex] ?? ReviewDecision::Pass;
        $this->reviewIndex++;
        $findings = [];

        if ($decision === ReviewDecision::Rewrite) {
            $scene = Scene::query()->where('chapter_id', $chapterId)->orderBy('sequence')->firstOrFail();
            $findings[] = [
                'code' => 'PLAN_DEVIATION',
                'dimension' => 'plan',
                'severity' => 'warning',
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
            'findings' => $findings,
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
}
