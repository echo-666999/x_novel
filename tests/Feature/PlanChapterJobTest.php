<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\StoryStateVersion;
use App\Models\Volume;
use App\Services\ChapterPlanner;
use App\Services\ChapterPlanPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function plannerChapter(): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create(['version' => 1]);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    $chapter = Chapter::factory()->for($novel)->for($volume)->create(['sequence' => 1]);
    $character = Character::factory()->for($novel)->create();

    return [$chapter, $character];
}

function plannerPayload(int $characterId, array $overrides = []): array
{
    return [
        'chapter_function' => '迫使主角离开安全区',
        'arc_contribution' => '推进失踪船队主线',
        'reader_promise' => '揭示灯塔的第一层秘密',
        'target_words' => 3000,
        'pov_character_id' => $characterId,
        'tone' => '紧张',
        'time_anchor' => '当日傍晚',
        'hook_type' => '悬念',
        'must_reveal' => ['灯塔仍在运转'],
        'may_hint' => [],
        'must_not_reveal' => ['幕后主使身份'],
        'required_facts' => [],
        'forbidden_conflicts' => [],
        'due_foreshadowings' => [],
        'scene_plans' => [[
            'goal' => '取得出港许可',
            'conflict' => '港务官拒绝放行',
            'turn' => '潮汐钟提前响起',
            'outcome' => '主角决定偷船',
            'pov_character_id' => $characterId,
            'location' => '旧港',
            'time_anchor' => '黄昏',
        ]],
        ...$overrides,
    ];
}

function plannerResponse(array $payload): AiResponse
{
    return new AiResponse(
        content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: 100,
        outputTokens: 200,
        cachedTokens: 0,
        latencyMs: 50,
        providerRequestId: fake()->uuid(),
        model: 'planner-test',
    );
}

test('the chapter plan response schema requires every declared scene field', function () {
    $sceneSchema = ChapterPlanPayload::schema()['properties']['scene_plans']['items'];

    expect($sceneSchema['required'])
        ->toEqualCanonicalizing(array_keys($sceneSchema['properties']))
        ->and($sceneSchema['properties']['pov_character_id']['type'])->toContain('null')
        ->and($sceneSchema['properties']['location']['type'])->toContain('null')
        ->and($sceneSchema['properties']['time_anchor']['type'])->toContain('null');
});

test('the planner creates a validated plan artifact and succeeds its run', function () {
    [$chapter, $character] = plannerChapter();
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(plannerPayload($character->getKey())));
    app()->instance(AiProvider::class, $fake);

    app(ChapterPlanner::class)->generate($chapter->getKey());

    $plan = $chapter->plans()->sole();
    $run = $chapter->generationRuns()->sole();

    expect($plan->status)->toBe(PlanStatus::Ready)
        ->and($plan->scene_plans)->toHaveCount(1)
        ->and($chapter->scenes()->count())->toBe(1)
        ->and($chapter->scenes()->sole()->goal)->toBe('取得出港许可')
        ->and($chapter->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->prompt_version)->toBe('chapter-planner-v1')
        ->and($run->artifacts()->sole()->type)->toBe(ArtifactType::ChapterPlan)
        ->and($fake->requests())->toHaveCount(1);
});

test('the planner receives closing restrictions and closure debt in completing mode', function () {
    [$chapter, $character] = plannerChapter();
    $chapter->novel->update(['status' => NovelStatus::Completing]);
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(plannerPayload($character->getKey())));
    app()->instance(AiProvider::class, $fake);

    app(ChapterPlanner::class)->generate($chapter->getKey());

    $request = $fake->requests()[0];
    $snapshot = $chapter->generationRuns()->sole()->context_snapshot;

    expect($request->systemPrompt)->toContain('Closing restrictions are active')
        ->and(data_get($snapshot, 'closing_restrictions.active'))->toBeTrue()
        ->and(data_get($snapshot, 'closing_restrictions.forbidden_new_elements'))->toBe([
            'core_character',
            'main_story_arc',
            'hard_world_rule',
            'high_importance_foreshadowing',
        ])
        ->and(data_get($snapshot, 'closure_debt.total'))->toBe(0)
        ->and(data_get($snapshot, 'closure_debt.critical'))->toBe(0);
});

test('duplicate delivery reuses the successful run and does not call the provider twice', function () {
    [$chapter, $character] = plannerChapter();
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(plannerPayload($character->getKey())));
    app()->instance(AiProvider::class, $fake);
    $planner = app(ChapterPlanner::class);

    $first = $planner->generate($chapter->getKey());
    $second = $planner->generate($chapter->getKey());

    expect($second?->is($first))->toBeTrue()
        ->and($chapter->plans()->count())->toBe(1)
        ->and($chapter->generationRuns()->count())->toBe(1)
        ->and($fake->requests())->toHaveCount(1);
});

test('explicit regeneration creates a new immutable artifact and plan version', function () {
    [$chapter, $character] = plannerChapter();
    $fake = (new FakeAiProvider)
        ->enqueue(plannerResponse(plannerPayload($character->getKey())))
        ->enqueue(plannerResponse(plannerPayload($character->getKey(), ['tone' => '压抑'])));
    app()->instance(AiProvider::class, $fake);
    $planner = app(ChapterPlanner::class);

    $planner->generate($chapter->getKey());
    $planner->generate($chapter->getKey(), true);

    expect($chapter->plans()->count())->toBe(2)
        ->and($chapter->plans()->where('version', 1)->sole()->status)->toBe(PlanStatus::Superseded)
        ->and($chapter->plans()->where('version', 2)->sole()->status)->toBe(PlanStatus::Ready)
        ->and($chapter->generationRuns()->count())->toBe(2)
        ->and($chapter->generationRuns()->latest('id')->first()->attempt)->toBe(2);
});

test('invalid structured output fails the run without writing a plan or artifact', function () {
    [$chapter, $character] = plannerChapter();
    $payload = plannerPayload($character->getKey());
    unset($payload['scene_plans'][0]['outcome']);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(plannerResponse($payload)));

    try {
        app(ChapterPlanner::class)->generate($chapter->getKey());
        $this->fail('Expected plan validation to fail.');
    } catch (ValidationException) {
        expect($chapter->plans()->count())->toBe(0)
            ->and($chapter->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
            ->and($chapter->generationRuns()->sole()->error_code)->toBe('plan_validation_failed')
            ->and($chapter->generationRuns()->sole()->artifacts()->count())->toBe(0);
    }
});

test('the queue job does not retry schema or business validation failures', function () {
    [$chapter, $character] = plannerChapter();
    $payload = plannerPayload($character->getKey());
    unset($payload['scene_plans'][0]['outcome']);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(plannerResponse($payload)));

    (new PlanChapterJob($chapter->getKey()))->handle(app(ChapterPlanner::class));

    expect($chapter->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
        ->and($chapter->generationRuns()->sole()->error_code)->toBe('plan_validation_failed')
        ->and($chapter->plans()->count())->toBe(0);
});

test('a canonical state change during provider execution blocks stale plan persistence', function () {
    [$chapter, $character] = plannerChapter();
    $payload = plannerPayload($character->getKey());
    $provider = new class($chapter->novel, plannerResponse($payload)) implements AiProvider
    {
        public function __construct(private Novel $novel, private AiResponse $response) {}

        public function generate(AiRequest $request): AiResponse
        {
            $nextState = StoryStateVersion::factory()->for($this->novel)->create([
                'version' => 1,
                'state' => ['timeline' => ['changed while planning']],
            ]);
            $this->novel->update(['canonical_state_version_id' => $nextState->getKey()]);

            return $this->response;
        }
    };
    app()->instance(AiProvider::class, $provider);

    try {
        app(ChapterPlanner::class)->generate($chapter->getKey());
        $this->fail('Expected state version conflict was not thrown.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('state_version_conflict')
            ->and($exception->retryable)->toBeFalse()
            ->and($chapter->plans()->count())->toBe(0)
            ->and($chapter->generationRuns()->sole()->artifacts()->count())->toBe(0)
            ->and($chapter->generationRuns()->sole()->status)->toBe(RunStatus::Failed);
    }
});

test('a retryable provider failure is recorded and rethrown by the queue job', function () {
    [$chapter] = plannerChapter();
    $exception = new AiProviderException('provider_timeout', 'timeout', true);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue($exception));

    expect(fn () => (new PlanChapterJob($chapter->getKey()))->handle(app(ChapterPlanner::class)))
        ->toThrow(AiProviderException::class, 'timeout');

    expect($chapter->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
        ->and($chapter->generationRuns()->sole()->error_code)->toBe('provider_timeout');
});

test('a stale running planner run is failed and recovered as a new attempt', function () {
    [$chapter, $character] = plannerChapter();
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(plannerPayload($character->getKey())));
    app()->instance(AiProvider::class, $fake);

    $stale = $chapter->generationRuns()->create([
        'novel_id' => $chapter->novel_id,
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => 'chapter_planning',
        'status' => RunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'stale-planner-run',
        'input_hash' => hash('sha256', 'stale'),
        'started_at' => now()->subMinutes(3),
    ]);
    $stale->timestamps = false;
    $stale->updated_at = now()->subMinutes(3);
    $stale->saveQuietly();

    app(ChapterPlanner::class)->generate($chapter->getKey());

    expect($stale->fresh()->status)->toBe(RunStatus::Failed)
        ->and($stale->fresh()->error_code)->toBe('worker_interrupted')
        ->and($chapter->generationRuns()->count())->toBe(2)
        ->and($chapter->generationRuns()->latest('id')->first()->status)->toBe(RunStatus::Succeeded);
});

test('a paused novel cannot start a planner run', function () {
    [$chapter] = plannerChapter();
    $chapter->novel->update(['status' => NovelStatus::Paused]);

    expect(fn () => app(ChapterPlanner::class)->generate($chapter->getKey()))
        ->toThrow(AiProviderException::class, '小说已暂停');

    expect($chapter->generationRuns()->count())->toBe(0);
});
