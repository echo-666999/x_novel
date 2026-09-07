<?php

use App\Actions\Generation\PauseGenerationAction;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Scene;
use App\Services\GenerationStageGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('pause records the active scene and preserves the previous novel status', function () {
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'settings' => ['auto_generate' => true],
    ]);
    $chapter = Chapter::factory()->for($novel)->create();
    $scene = Scene::factory()->for($chapter)->create(['sequence' => 2]);
    GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
        'scope_type' => 'scene',
        'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Running,
    ]);

    app(PauseGenerationAction::class)->handle($novel);

    $novel->refresh();
    expect($novel->status)->toBe(NovelStatus::Paused)
        ->and(data_get($novel->settings, 'auto_generate'))->toBeTrue()
        ->and(data_get($novel->settings, 'pause.previous_status'))->toBe('generating')
        ->and(data_get($novel->settings, 'pause.stage'))->toBe('scene_generation')
        ->and(data_get($novel->settings, 'pause.label'))->toBe('场景 2')
        ->and(data_get($novel->settings, 'pause.chapter_id'))->toBe($chapter->getKey())
        ->and(data_get($novel->settings, 'pause.scene_id'))->toBe($scene->getKey())
        ->and(data_get($novel->settings, 'pause.paused_at'))->not->toBeNull();
});

test('paused novel cannot pass the next stage dispatch gate', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Paused]);
    $chapter = Chapter::factory()->for($novel)->create();
    $dispatched = false;

    $allowed = app(GenerationStageGate::class)->dispatchForChapter(
        $chapter->getKey(),
        function () use (&$dispatched): void {
            $dispatched = true;
        },
    );

    expect($allowed)->toBeFalse()->and($dispatched)->toBeFalse();
});

test('only an actively generating or completing novel can be paused', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft]);

    expect(fn () => app(PauseGenerationAction::class)->handle($novel))
        ->toThrow(ValidationException::class, '只有生成中或收束中的小说可以暂停');

    expect($novel->fresh()->status)->toBe(NovelStatus::Draft);
});
