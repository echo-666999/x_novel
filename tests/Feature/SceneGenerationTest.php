<?php

use App\Actions\Chapters\RegenerateSceneSequenceAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\GenerateSceneJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Services\DraftLengthPolicy;
use App\Services\SceneDraftPayload;
use App\Services\SceneGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function sceneGenerationFixture(int $sceneCount = 2): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 20,
        'status' => ChapterStatus::Generating,
    ]);
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'must_not_reveal' => ['终局真相'],
        'scene_plans' => [],
        'target_words' => $sceneCount * 8,
    ]);
    $scenes = collect(range(1, $sceneCount))->map(fn (int $sequence): Scene => Scene::factory()
        ->for($chapter)
        ->create([
            'sequence' => $sequence,
            'goal' => "Scene {$sequence} 目标",
            'status' => SceneStatus::Planned,
        ]));

    return compact('novel', 'chapter', 'plan', 'scenes');
}

function sceneResponse(string $content, array $delta = []): AiResponse
{
    $payload = [
        'content' => $content,
        'temporary_state_delta' => $delta,
        'declared_events' => [],
        'uncertainties' => [],
        'self_check' => ['passed' => true],
    ];

    return new AiResponse(
        content: json_encode($payload, JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: 100,
        outputTokens: 200,
        cachedTokens: 0,
        latencyMs: 350,
        providerRequestId: 'scene-request',
        model: 'writer-test',
    );
}

test('scene allocation shares the chapter word budget across remaining scenes', function () {
    $policy = app(DraftLengthPolicy::class);

    expect($policy->sceneAllocation(3000, 0, 1)['scene_target_words'])->toBe(3000)
        ->and($policy->sceneAllocation(3000, 0, 4)['scene_target_words'])->toBe(750)
        ->and($policy->sceneAllocation(3000, 300, 1)['scene_target_words'])->toBe(2700)
        ->and($policy->sceneAllocation(3000, 300, 1)['required_scene_words'])->toBe(2250);
});

test('scene draft schema keeps dynamic objects compatible with strict structured output', function () {
    $schema = SceneDraftPayload::schema();

    expect($schema['additionalProperties'])->toBeFalse()
        ->and(data_get($schema, 'properties.temporary_state_delta.type'))->toBe('string')
        ->and(data_get($schema, 'properties.declared_events.items.type'))->toBe('string')
        ->and(data_get($schema, 'properties.self_check.type'))->toBe('string');

    $payload = SceneDraftPayload::validate([
        'content' => '林舟进入灯塔。',
        'temporary_state_delta' => '{"characters":{"lin_zhou":{"location":"灯塔"}}}',
        'declared_events' => ['{"type":"character_moved","subject":"lin_zhou"}'],
        'uncertainties' => [],
        'self_check' => '{"passed":true}',
    ]);

    expect(data_get($payload, 'temporary_state_delta.characters.lin_zhou.location'))->toBe('灯塔')
        ->and(data_get($payload, 'declared_events.0.type'))->toBe('character_moved')
        ->and(data_get($payload, 'self_check.passed'))->toBeTrue();
});

test('scene generator persists an immutable draft artifact and temporary state delta', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['novel']->update(['settings' => ['editorial' => ['primary_style' => 'light_humorous', 'secondary_styles' => [], 'style_parameters' => []]]]);
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('雨幕中，林舟推开了门。', [
        'characters' => ['lin_zhou' => ['location' => '灯塔']],
    ]));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());
    $scene = $fixture['scenes']->first()->fresh();
    $run = $scene->generationRuns()->sole();

    expect($artifact->type)->toBe(ArtifactType::SceneDraft)
        ->and($artifact->content)->toBe('雨幕中，林舟推开了门。')
        ->and(data_get($artifact->data, 'temporary_state_delta.characters.lin_zhou.location'))->toBe('灯塔')
        ->and(data_get($artifact->data, 'word_count'))->toBe(11)
        ->and(data_get($artifact->data, 'target_words'))->toBe(8)
        ->and($scene->status)->toBe(SceneStatus::Draft)
        ->and($scene->current_artifact_id)->toBe($artifact->getKey())
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->stage)->toBe(GenerationStage::SceneGeneration)
        ->and($run->context_snapshot)->toHaveKeys(['l0', 'l1', 'l2', 'scene_task', 'temporary_state']);
    expect(data_get($run->context_snapshot, 'writing_constraints.scene_target_words'))->toBe($fixture['plan']->target_words)
        ->and(data_get($run->context_snapshot, 'writing_constraints.style_profile.primary_style'))->toBe('轻松幽默')
        ->and(data_get($run->context_snapshot, 'writing_constraints.style_profile.instructions.0'))->toContain('情境幽默');
});

test('a final scene budget shortfall is expanded once before the chapter is blocked', function () {
    Queue::fake();
    $fixture = sceneGenerationFixture(1);
    $fixture['plan']->update(['target_words' => 100]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse('过短场景'))
        ->enqueue(sceneResponse('仍然过短'));
    app()->instance(AiProvider::class, $fake);

    (new GenerateSceneJob($fixture['scenes']->first()->getKey()))->handle(app(SceneGenerator::class));

    Queue::assertNothingPushed();
    expect($fixture['scenes']->first()->fresh()->status)->toBe(SceneStatus::Failed)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Blocked)
        ->and($fixture['scenes']->first()->generationRuns()->where('error_code', 'scene_budget_shortfall')->count())->toBe(1)
        ->and($fixture['scenes']->first()->generationRuns()->whereHas('artifacts')->count())->toBe(0);
    expect($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->systemPrompt)->toContain('场景扩写器');
});

test('a successful length expansion becomes the scene draft', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['plan']->update(['target_words' => 100]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse(str_repeat('短', 20)))
        ->enqueue(sceneResponse(str_repeat('扩', 90)));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());

    expect($artifact?->content)->toBe(str_repeat('扩', 90))
        ->and($artifact?->data['word_count'])->toBe(90)
        ->and($fixture['scenes']->first()->fresh()->status)->toBe(SceneStatus::Draft)
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->prompt)->toContain('终局真相');
});

test('cascade generation shifts an early scene shortfall to the next scene', function () {
    Queue::fake();
    $fixture = sceneGenerationFixture(2);
    $fixture['plan']->update(['target_words' => 3000]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse(str_repeat('短', 300)))
        ->enqueue(sceneResponse(str_repeat('长', 2700)));
    app()->instance(AiProvider::class, $fake);
    $batchId = 'regenerate-batch-test';

    (new GenerateSceneJob(
        sceneId: $fixture['scenes']->first()->getKey(),
        cascade: true,
        regenerationBatchId: $batchId,
    ))->handle(app(SceneGenerator::class));

    $nextJob = Queue::pushed(GenerateSceneJob::class)->sole();
    expect($nextJob->sceneId)->toBe($fixture['scenes']->last()->getKey())
        ->and($nextJob->cascade)->toBeTrue()
        ->and($nextJob->regenerationBatchId)->toBe($batchId);

    $nextJob->handle(app(SceneGenerator::class));

    $firstConstraints = $fixture['scenes']->first()->generationRuns()->sole()->context_snapshot['writing_constraints'];
    $lastConstraints = $fixture['scenes']->last()->generationRuns()->sole()->context_snapshot['writing_constraints'];

    expect($firstConstraints['scene_target_words'])->toBe(1500)
        ->and($firstConstraints['required_scene_words'])->toBe(0)
        ->and($lastConstraints['allocated_scene_words'])->toBe(300)
        ->and($lastConstraints['remaining_scene_count'])->toBe(1)
        ->and($lastConstraints['scene_target_words'])->toBe(2700)
        ->and($lastConstraints['required_scene_words'])->toBe(2250)
        ->and($fixture['scenes']->sum(fn (Scene $scene): int => mb_strlen($scene->fresh()->currentArtifact->content)))->toBe(3000)
        ->and($fake->requests())->toHaveCount(2);
});

test('cascade regeneration resets the selected and later scenes while preserving history', function () {
    Queue::fake();
    $fixture = sceneGenerationFixture(3);
    $artifactIds = $fixture['scenes']->map(function (Scene $scene) use ($fixture): int {
        $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->for($scene)->create([
            'stage' => GenerationStage::SceneGeneration,
            'status' => RunStatus::Succeeded,
        ]);
        $artifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::SceneDraft,
            'content' => str_repeat((string) $scene->sequence, 10),
        ]);
        $scene->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $artifact->getKey()]);

        return $artifact->getKey();
    });
    $fixture['chapter']->update(['status' => ChapterStatus::Review]);

    $count = app(RegenerateSceneSequenceAction::class)->handle($fixture['scenes'][1]);

    expect($count)->toBe(2)
        ->and($fixture['scenes'][0]->fresh()->current_artifact_id)->toBe($artifactIds[0])
        ->and($fixture['scenes'][0]->fresh()->status)->toBe(SceneStatus::Draft)
        ->and($fixture['scenes'][1]->fresh()->current_artifact_id)->toBeNull()
        ->and($fixture['scenes'][1]->fresh()->status)->toBe(SceneStatus::Planned)
        ->and($fixture['scenes'][2]->fresh()->current_artifact_id)->toBeNull()
        ->and($fixture['scenes'][2]->fresh()->status)->toBe(SceneStatus::Planned)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and(GenerationArtifact::query()->whereKey($artifactIds)->count())->toBe(3);

    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $fixture['scenes'][1]->getKey()
        && $job->cascade
        && $job->regenerationBatchId !== null);
});

test('scene two cannot execute before scene one succeeds', function () {
    $fixture = sceneGenerationFixture();
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('不应生成'));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(SceneGenerator::class)->generate($fixture['scenes']->last()->getKey()))
        ->toThrow(AiProviderException::class, '必须等待 Scene 1 成功');

    expect($fake->requests())->toHaveCount(0)
        ->and(GenerationRun::query()->count())->toBe(0);
});

test('the next scene receives previous temporary state and scene tail', function () {
    $fixture = sceneGenerationFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse('第一幕结尾：门后传来钟声。', ['items' => ['key' => ['owner' => '林舟']]]))
        ->enqueue(sceneResponse('第二幕开始。'));
    app()->instance(AiProvider::class, $fake);
    $generator = app(SceneGenerator::class);

    $generator->generate($fixture['scenes']->first()->getKey());
    $generator->generate($fixture['scenes']->last()->getKey());
    $secondPrompt = $fake->requests()[1]->prompt;

    expect($secondPrompt)->toContain('门后传来钟声')
        ->and($secondPrompt)->toContain('"owner":"林舟"');
});

test('duplicate delivery reuses the successful scene artifact without another provider call', function () {
    $fixture = sceneGenerationFixture(1);
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('唯一的 Scene Draft。'));
    app()->instance(AiProvider::class, $fake);
    $generator = app(SceneGenerator::class);
    $sceneId = $fixture['scenes']->first()->getKey();

    $first = $generator->generate($sceneId);
    $second = $generator->generate($sceneId);

    expect($second?->is($first))->toBeTrue()
        ->and($fake->requests())->toHaveCount(1)
        ->and(GenerationRun::query()->count())->toBe(1);
});

test('regenerating a scene preserves the previous artifact and returns the chapter to generation', function () {
    $fixture = sceneGenerationFixture(1);
    $scene = $fixture['scenes']->first();
    $previousRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->for($scene)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $previousArtifact = GenerationArtifact::factory()->for($previousRun)->create([
        'type' => ArtifactType::SceneDraft,
        'content' => '旧场景草稿内容。',
    ]);
    $scene->update([
        'status' => SceneStatus::Draft,
        'current_artifact_id' => $previousArtifact->getKey(),
    ]);
    $fixture['chapter']->update(['status' => ChapterStatus::Review]);
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('重新生成后的场景正文。'));
    app()->instance(AiProvider::class, $fake);

    $newArtifact = app(SceneGenerator::class)->generate($scene->getKey(), true);

    expect($newArtifact?->getKey())->not->toBe($previousArtifact->getKey())
        ->and($previousArtifact->version)->toBe(1)
        ->and($newArtifact?->version)->toBe(2)
        ->and($scene->fresh()->current_artifact_id)->toBe($newArtifact?->getKey())
        ->and($previousArtifact->fresh())->not->toBeNull()
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and($scene->generationRuns()->count())->toBe(2)
        ->and($fake->requests())->toHaveCount(1);
});

test('canonical chapter scenes cannot be regenerated directly', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['chapter']->update(['status' => ChapterStatus::Canonical]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey(), true))
        ->toThrow(AiProviderException::class, '正式章节不能直接重新生成场景');

    expect($fake->requests())->toHaveCount(0)
        ->and(GenerationRun::query()->count())->toBe(0);
});

test('a stale running scene is marked interrupted and resumed with a new run', function () {
    $fixture = sceneGenerationFixture(1);
    $scene = $fixture['scenes']->first();
    $stale = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->for($scene)->create([
        'scope_type' => 'scene',
        'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Running,
        'attempt' => 1,
        'updated_at' => now()->subMinutes(3),
    ]);
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('恢复后生成成功。'));
    app()->instance(AiProvider::class, $fake);

    app(SceneGenerator::class)->generate($scene->getKey());

    expect($stale->fresh()->status)->toBe(RunStatus::Failed)
        ->and($stale->fresh()->error_code)->toBe('worker_interrupted')
        ->and($scene->generationRuns()->count())->toBe(2)
        ->and($scene->fresh()->status)->toBe(SceneStatus::Draft);
});

test('retryable provider failures are recorded and rethrown for queue retry', function () {
    $fixture = sceneGenerationFixture(1);
    $fake = (new FakeAiProvider)->enqueue(new AiProviderException('provider_timeout', 'timeout', true));
    app()->instance(AiProvider::class, $fake);
    $job = new GenerateSceneJob($fixture['scenes']->first()->getKey());

    expect(fn () => $job->handle(app(SceneGenerator::class)))
        ->toThrow(AiProviderException::class, 'timeout');

    expect($fixture['scenes']->first()->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
        ->and($fixture['scenes']->first()->generationRuns()->sole()->error_code)->toBe('provider_timeout')
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating);

    $fake->enqueue(sceneResponse('重试后成功的 Scene。'));
    $job->handle(app(SceneGenerator::class));

    expect($fixture['scenes']->first()->fresh()->status)->toBe(SceneStatus::Draft)
        ->and($fixture['scenes']->first()->generationRuns()->count())->toBe(2)
        ->and($fixture['scenes']->first()->generationRuns()->latest('id')->first()->attempt)->toBe(2)
        ->and($fake->requests())->toHaveCount(2);
});

test('terminal failure blocks the scene and chapter while preserving earlier artifacts', function () {
    $fixture = sceneGenerationFixture();
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('已经完成的第一幕。'));
    app()->instance(AiProvider::class, $fake);
    $generator = app(SceneGenerator::class);
    $first = $fixture['scenes']->first();
    $second = $fixture['scenes']->last();
    $artifact = $generator->generate($first->getKey());

    (new GenerateSceneJob($second->getKey()))->failed(new AiProviderException('provider_timeout', 'timeout', true));

    expect($first->fresh()->current_artifact_id)->toBe($artifact?->getKey())
        ->and($second->fresh()->status)->toBe(SceneStatus::Failed)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Blocked);
});

test('paused novels cannot start a new scene generation stage', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['novel']->update(['status' => NovelStatus::Paused]);
    app()->instance(AiProvider::class, new FakeAiProvider);

    expect(fn () => app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey()))
        ->toThrow(AiProviderException::class, '小说已暂停');

    expect(GenerationRun::query()->count())->toBe(0);
});

test('obvious must not reveal violations fail before persisting an artifact', function () {
    $fixture = sceneGenerationFixture(1);
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('这一幕直接说出了终局真相。'));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey()))
        ->toThrow(AiProviderException::class, '禁止揭示内容');

    expect($fixture['scenes']->first()->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
        ->and($fixture['scenes']->first()->fresh()->current_artifact_id)->toBeNull();
});
