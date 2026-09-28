<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Data\ResolvedAiSettings;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\GenerateSceneJob;
use App\Jobs\RepairSceneLengthJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Models\StoryStateVersion;
use App\Models\UsageRecord;
use App\Services\ChapterAssembler;
use App\Services\ContextBuilder;
use App\Services\DraftLengthPolicy;
use App\Services\GenerationFailurePolicy;
use App\Services\PlanAdmissionService;
use App\Services\SceneLengthRepairer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function deterministicAssemblyFixture(array $contents = [' 第一场正文 ', "\n第二场正文\n"], array $coverageOverrides = []): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 20, 'status' => ChapterStatus::Generating]);
    $joined = implode(ChapterAssembler::SEPARATOR, array_map(fn (string $content): string => trim($content), $contents));
    ChapterPlan::factory()->for($chapter)->create([
        'scene_plans' => [],
        'target_words' => mb_strlen(preg_replace('/\s+/u', '', $joined)),
    ]);
    $scenes = collect($contents)->map(function (string $content, int $index) use ($chapter, $novel, $coverageOverrides): Scene {
        $sequence = $index + 1;
        $scene = Scene::factory()->for($chapter)->create(['sequence' => $sequence, 'status' => SceneStatus::Draft]);
        $run = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
            'stage' => GenerationStage::SceneGeneration,
            'status' => RunStatus::Succeeded,
        ]);
        $trimmed = trim($content);
        $fulfilled = ['status' => 'fulfilled', 'evidence' => $trimmed];
        $selfCheck = [
            'goal' => $fulfilled, 'conflict' => $fulfilled, 'turn' => $fulfilled, 'outcome' => $fulfilled,
            ...($coverageOverrides[$sequence] ?? []),
        ];
        $artifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::SceneDraft,
            'content' => $content,
            'data' => ['self_check' => $selfCheck, 'foreshadowing_coverage' => []],
            'checksum' => hash('sha256', $content),
        ]);
        $scene->update(['current_artifact_id' => $artifact->getKey()]);

        return $scene->fresh();
    });

    return compact('novel', 'chapter', 'scenes', 'joined');
}

test('deterministic assembler joins trimmed current scene artifacts with a fixed separator', function () {
    $fixture = deterministicAssemblyFixture(['  第一幕。  ', "\n第二幕。\n", " 第三幕。\t"]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);

    $artifact = app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());
    $run = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->sole();

    expect($artifact?->content)->toBe("第一幕。\n\n第二幕。\n\n第三幕。")
        ->and($artifact?->checksum)->toBe(hash('sha256', $artifact->content))
        ->and($artifact?->data['ordered_scene_ids'])->toBe($fixture['scenes']->pluck('id')->all())
        ->and($artifact?->data['ordered_artifact_ids'])->toBe($fixture['scenes']->pluck('current_artifact_id')->all())
        ->and($artifact?->data['ordered_scene_checksums'])->toBe($fixture['scenes']->pluck('currentArtifact.checksum')->all())
        ->and($artifact?->data['assembly_algorithm_version'])->toBe(ChapterAssembler::ALGORITHM_VERSION)
        ->and($artifact?->data['assembly_hash'])->toBe($run->input_hash)
        ->and($artifact?->data['word_count'])->toBe(12)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->provider)->toBeNull()
        ->and($run->model_policy)->toBeNull()
        ->and($run->prompt_version)->toBeNull()
        ->and($fake->requests())->toBeEmpty()
        ->and(UsageRecord::query()->count())->toBe(0);
});

test('assembly restores coverage identity from scenes and aggregates findings without rewriting prose', function () {
    $missing = ['status' => 'missing', 'evidence' => null];
    $fixture = deterministicAssemblyFixture(['场景一', '场景二'], [2 => ['outcome' => $missing]]);

    $artifact = app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());

    expect(data_get($artifact?->data, 'scene_coverage.0.scene_id'))->toBe($fixture['scenes'][0]->getKey())
        ->and(data_get($artifact?->data, 'scene_coverage.1.scene_id'))->toBe($fixture['scenes'][1]->getKey())
        ->and(data_get($artifact?->data, 'scene_coverage.1.outcome'))->toBe($missing)
        ->and(data_get($artifact?->data, 'plan_findings.0.code'))->toBe('SCENE_PLAN_COVERAGE_MISSING')
        ->and(data_get($artifact?->data, 'plan_findings.0.scene_id'))->toBe($fixture['scenes'][1]->getKey())
        ->and($artifact?->content)->toBe($fixture['joined']);
});

test('assembly aggregates foreshadowing coverage in frozen contract order', function () {
    $fixture = deterministicAssemblyFixture(['地图最终指向潮汐门。']);
    $foreshadowing = Foreshadowing::factory()->for($fixture['novel'])->create([
        'status' => ForeshadowingStatus::Planted, 'due_from_chapter' => 18, 'due_to_chapter' => 20,
    ]);
    $fixture['chapter']->latestPlan->update(['foreshadowing_actions' => [[
        'foreshadowing_id' => $foreshadowing->getKey(), 'action' => 'pay_off',
        'target_scene_sequence' => 1, 'acceptance_criteria' => '地图指向潮汐门。', 'reason' => null,
    ]]]);
    $source = $fixture['scenes'][0]->currentArtifact;
    DB::table('generation_artifacts')->where('id', $source->getKey())->update(['data' => json_encode([
        ...$source->data,
        'foreshadowing_coverage' => [[
            'foreshadowing_id' => $foreshadowing->getKey(), 'action' => 'pay_off',
            'status' => 'fulfilled', 'evidence' => '地图最终指向潮汐门',
        ]],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

    $artifact = app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());

    expect(data_get($artifact?->data, 'scene_coverage.0.foreshadowing_coverage.0.foreshadowing_id'))
        ->toBe($foreshadowing->getKey())
        ->and(data_get($artifact?->data, 'scene_coverage.0.foreshadowing_coverage.0.action'))->toBe('pay_off');
});

test('identical assembly input reuses the same successful run and artifact', function () {
    $fixture = deterministicAssemblyFixture();
    $assembler = app(ChapterAssembler::class);

    $first = $assembler->assemble($fixture['chapter']->getKey());
    $second = $assembler->assemble($fixture['chapter']->getKey());

    expect($second?->is($first))->toBeTrue()
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(1)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(1);
});

test('forced regeneration remains byte identical and records a stable assembly hash', function () {
    $fixture = deterministicAssemblyFixture();
    $assembler = app(ChapterAssembler::class);

    $first = $assembler->assemble($fixture['chapter']->getKey());
    $second = $assembler->assemble($fixture['chapter']->getKey(), true);

    expect($second?->is($first))->toBeFalse()
        ->and($second?->content)->toBe($first?->content)
        ->and($second?->data['assembly_hash'])->toBe($first?->data['assembly_hash'])
        ->and($second?->version)->toBe(2);
});

test('assembly refuses missing and invalid scene inputs without saving a chapter draft', function (Closure $corrupt, string $message) {
    $fixture = deterministicAssemblyFixture();
    $corrupt($fixture);

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, $message)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0);
})->with([
    'missing current artifact' => [
        fn (array $fixture) => $fixture['scenes'][0]->update(['current_artifact_id' => null, 'status' => SceneStatus::Failed]),
        '尚未成功',
    ],
    'checksum mismatch' => [
        fn (array $fixture) => DB::table('generation_artifacts')->where('id', $fixture['scenes'][0]->current_artifact_id)->update(['checksum' => str_repeat('0', 64)]),
        'checksum 与正文不一致',
    ],
]);

test('assembly refuses a cross chapter current artifact', function () {
    $fixture = deterministicAssemblyFixture();
    $otherChapter = Chapter::factory()->for($fixture['novel'])->create();
    $otherScene = Scene::factory()->for($otherChapter)->create();
    $run = GenerationRun::factory()->for($fixture['novel'])->for($otherChapter)->for($otherScene)->create([
        'stage' => GenerationStage::SceneGeneration, 'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::SceneDraft, 'content' => '异章内容',
        'data' => ['self_check' => [], 'foreshadowing_coverage' => []],
        'checksum' => hash('sha256', '异章内容'),
    ]);
    $fixture['scenes'][0]->update(['current_artifact_id' => $artifact->getKey()]);

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '不属于该 Scene/Chapter');
    expect(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0);
});

test('scene checksum change before persistence abandons the draft and recovers from the earliest changed scene', function () {
    Queue::fake();
    $fixture = deterministicAssemblyFixture(['场景一', '场景二', '场景三']);
    $firstArtifactId = $fixture['scenes'][0]->current_artifact_id;
    $armed = true;
    GenerationRun::created(function (GenerationRun $run) use (&$armed, $fixture): void {
        if (! $armed || $run->stage !== GenerationStage::ChapterAssembly || $run->chapter_id !== $fixture['chapter']->getKey()) {
            return;
        }
        $armed = false;
        $scene = $fixture['scenes'][1];
        $source = $scene->currentArtifact;
        $content = '并发产生的新场景二';
        $artifact = GenerationArtifact::factory()->for($source->generationRun)->create([
            'type' => ArtifactType::SceneDraft,
            'version' => $source->version + 1,
            'content' => $content,
            'data' => [
                'self_check' => collect(['goal', 'conflict', 'turn', 'outcome'])
                    ->mapWithKeys(fn (string $key): array => [$key => ['status' => 'fulfilled', 'evidence' => $content]])
                    ->all(),
                'foreshadowing_coverage' => [],
            ],
            'checksum' => hash('sha256', $content),
        ]);
        $scene->update(['current_artifact_id' => $artifact->getKey()]);
    });

    expect(app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))->toBeNull();

    expect($fixture['scenes'][0]->fresh()->current_artifact_id)->toBe($firstArtifactId)
        ->and($fixture['scenes'][1]->fresh()->current_artifact_id)->toBeNull()
        ->and($fixture['scenes'][2]->fresh()->current_artifact_id)->toBeNull()
        ->and(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->sole()->error_code)
        ->toBe('scene_artifact_conflict');
    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $fixture['scenes'][1]->getKey() && $job->cascade);
});

test('state version change before persistence abandons the draft and restarts scene recovery from the first scene', function () {
    Queue::fake();
    $fixture = deterministicAssemblyFixture(['场景一', '场景二']);
    $armed = true;
    GenerationRun::created(function (GenerationRun $run) use (&$armed, $fixture): void {
        if (! $armed || $run->stage !== GenerationStage::ChapterAssembly || $run->chapter_id !== $fixture['chapter']->getKey()) {
            return;
        }
        $armed = false;
        $next = StoryStateVersion::factory()->for($fixture['novel'])->create(['version' => 1]);
        $fixture['novel']->update(['canonical_state_version_id' => $next->getKey()]);
    });

    expect(app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))->toBeNull();

    expect($fixture['scenes'][0]->fresh()->current_artifact_id)->toBeNull()
        ->and($fixture['scenes'][1]->fresh()->current_artifact_id)->toBeNull()
        ->and(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->sole()->error_code)
        ->toBe('state_version_conflict');
    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $fixture['scenes'][0]->getKey() && $job->cascade);
});

test('a scene owned continuity finding regenerates only that scene and its downstream scenes', function () {
    Queue::fake();
    $fixture = deterministicAssemblyFixture(['场景一', '场景二', '场景三']);
    $firstArtifactId = $fixture['scenes'][0]->current_artifact_id;
    $source = $fixture['scenes'][1]->currentArtifact;
    DB::table('generation_artifacts')->where('id', $source->getKey())->update([
        'data' => json_encode([
            ...$source->data,
            'continuity_findings' => [[
                'code' => 'CONTINUITY_TRANSITION_MISSING',
                'dimension' => 'continuity',
                'message' => '第二场景缺少承接动作。',
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);

    expect(app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))->toBeNull();

    expect($fixture['scenes'][0]->fresh()->current_artifact_id)->toBe($firstArtifactId)
        ->and($fixture['scenes'][1]->fresh()->current_artifact_id)->toBeNull()
        ->and($fixture['scenes'][2]->fresh()->current_artifact_id)->toBeNull()
        ->and(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->sole()->error_code)
        ->toBe('assembly_continuity_recovery_scheduled');
    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $fixture['scenes'][1]->getKey() && $job->cascade);
});

test('underlength assembly dispatches one targeted scene expansion and no chapter provider request', function () {
    Queue::fake();
    $fixture = deterministicAssemblyFixture(['短', '这一个场景相对更长']);
    $fixture['chapter']->latestPlan->update(['target_words' => 100]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);

    $artifact = app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());
    $run = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->sole();

    expect($artifact)->toBeNull()
        ->and($run->status)->toBe(RunStatus::Failed)
        ->and($run->error_code)->toBe('assembly_scene_length_repair_scheduled')
        ->and($fake->requests())->toBeEmpty();
    Queue::assertPushed(RepairSceneLengthJob::class, fn (RepairSceneLengthJob $job): bool => $job->sceneId === $fixture['scenes'][0]->getKey() && $job->mode === 'expand');
});

test('overlength assembly dispatches one targeted scene compression and never a whole chapter rewrite', function () {
    Queue::fake();
    $fixture = deterministicAssemblyFixture([str_repeat('甲', 80), str_repeat('乙', 40)]);
    $fixture['chapter']->latestPlan->update(['target_words' => 80]);

    expect(app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))->toBeNull();

    Queue::assertPushed(RepairSceneLengthJob::class, fn (RepairSceneLengthJob $job): bool => $job->sceneId === $fixture['scenes'][0]->getKey() && $job->mode === 'compress');
    Queue::assertNotPushed(GenerateSceneJob::class);
});

test('targeted scene length repair creates a new current scene artifact within frozen bounds', function () {
    $fixture = deterministicAssemblyFixture(['短场景']);
    $scene = $fixture['scenes'][0];
    $source = $scene->currentArtifact;
    $content = '扩写后的完整场景内容';
    $fulfilled = ['status' => 'fulfilled', 'evidence' => $content];
    $payload = [
        'content' => $content,
        'self_check' => ['goal' => $fulfilled, 'conflict' => $fulfilled, 'turn' => $fulfilled, 'outcome' => $fulfilled],
        'foreshadowing_coverage' => [],
    ];
    $fake = (new FakeAiProvider)->enqueue(new AiResponse(
        json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $payload,
        10,
        20,
        0,
        5,
        'scene-length-repair',
        'rewrite-test',
    ));
    $admission = Mockery::mock(PlanAdmissionService::class);
    $admission->shouldReceive('admit')->once()->andReturn($fixture['chapter']->latestPlan);
    $admission->shouldReceive('routeFor')->once()->andReturn(new ResolvedAiSettings(
        stage: AiStage::Rewrite,
        provider: 'fake',
        model: 'rewrite-test',
        reasoningEffort: null,
        source: 'test',
    ));
    $admission->shouldReceive('promptVersionFor')->once()->andReturn('rewrite-v14+natural-prose-v1');
    $repairer = new SceneLengthRepairer(
        $fake,
        $admission,
        app(ContextBuilder::class),
        app(DraftLengthPolicy::class),
        app(GenerationFailurePolicy::class),
    );

    $artifact = $repairer->repair(
        $scene->getKey(), $source->getKey(), 'expand', 8, 10, 12, str_repeat('a', 64),
    );

    expect($artifact?->type)->toBe(ArtifactType::RewriteDraft)
        ->and($artifact?->content)->toBe($content)
        ->and(data_get($artifact?->data, 'assembly_length_repair'))->toBeTrue()
        ->and(data_get($artifact?->data, 'source_artifact_id'))->toBe($source->getKey())
        ->and($scene->fresh()->current_artifact_id)->toBe($artifact?->getKey())
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and($fake->requests())->toHaveCount(1)
        ->and(data_get($fake->requests()[0]->metadata, 'stage'))->toBe('rewrite')
        ->and(data_get($fake->requests()[0]->metadata, 'substage'))->toBe('assembly_length_repair');
});

test('a second out of range assembly after local repair stops without another paid repair', function () {
    Queue::fake();
    $fixture = deterministicAssemblyFixture(['短']);
    $fixture['chapter']->latestPlan->update(['target_words' => 100]);
    $source = $fixture['scenes'][0]->currentArtifact;
    DB::table('generation_artifacts')->where('id', $source->getKey())->update([
        'data' => json_encode([...$source->data, 'assembly_length_repair' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '局部字数修复后章节仍为');

    Queue::assertNotPushed(RepairSceneLengthJob::class);
    expect(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0);
});

test('paused novels cannot start deterministic assembly', function () {
    $fixture = deterministicAssemblyFixture();
    $fixture['novel']->update(['status' => NovelStatus::Paused]);

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '小说已暂停');
    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(0);
});
