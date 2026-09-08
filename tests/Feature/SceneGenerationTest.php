<?php

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
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Services\SceneDraftPayload;
use App\Services\SceneGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        ->and($scene->status)->toBe(SceneStatus::Draft)
        ->and($scene->current_artifact_id)->toBe($artifact->getKey())
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->stage)->toBe(GenerationStage::SceneGeneration)
        ->and($run->context_snapshot)->toHaveKeys(['l0', 'l1', 'l2', 'scene_task', 'temporary_state']);
    expect(data_get($run->context_snapshot, 'writing_constraints.scene_target_words'))->toBe($fixture['plan']->target_words)
        ->and(data_get($run->context_snapshot, 'writing_constraints.style_profile.primary_style'))->toBe('轻松幽默')
        ->and(data_get($run->context_snapshot, 'writing_constraints.style_profile.instructions.0'))->toContain('情境幽默');
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
