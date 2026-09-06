<?php

use App\Actions\Chapters\SyncScenesFromChapterPlanAction;
use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('it initializes ordered scenes from the latest chapter plan', function () {
    $chapter = Chapter::factory()->create();
    $defaultPov = Character::factory()->for($chapter->novel)->create();
    $secondPov = Character::factory()->for($chapter->novel)->create();
    ChapterPlan::factory()->for($chapter)->create([
        'version' => 1,
        'pov_character_id' => $defaultPov->getKey(),
        'time_anchor' => '清晨',
        'scene_plans' => [
            [
                'goal' => '取得钥匙',
                'conflict' => '守卫阻拦',
                'turn' => '钥匙是赝品',
                'outcome' => '转而寻找铸造者',
                'location' => '北门',
            ],
            [
                'goal' => '找到铸造者',
                'conflict' => '铸造者失踪',
                'turn' => '发现密道',
                'outcome' => '进入地下城',
                'pov_character_id' => $secondPov->getKey(),
                'time_anchor' => '正午',
            ],
        ],
    ]);

    $count = app(SyncScenesFromChapterPlanAction::class)->execute($chapter);
    [$first, $second] = $chapter->scenes()->get();

    expect($count)->toBe(2)
        ->and($first->sequence)->toBe(1)
        ->and($first->pov_character_id)->toBe($defaultPov->getKey())
        ->and($first->location)->toBe('北门')
        ->and($first->time_anchor)->toBe('清晨')
        ->and($first->status)->toBe(SceneStatus::Planned)
        ->and($second->sequence)->toBe(2)
        ->and($second->pov_character_id)->toBe($secondPov->getKey())
        ->and($second->time_anchor)->toBe('正午');
});

test('repeated sync updates planned scenes without creating duplicates and removes surplus scenes', function () {
    $chapter = Chapter::factory()->create();
    $plan = ChapterPlan::factory()->for($chapter)->create();
    $action = app(SyncScenesFromChapterPlanAction::class);

    $action->execute($chapter);
    $firstSceneId = $chapter->scenes()->sole()->getKey();
    $plan->update(['scene_plans' => [
        ['goal' => '新目标', 'conflict' => '新冲突', 'turn' => '新转折', 'outcome' => '新结果'],
        ['goal' => '第二目标', 'conflict' => '第二冲突', 'turn' => '第二转折', 'outcome' => '第二结果'],
    ]]);
    $action->execute($chapter);

    expect($chapter->scenes()->count())->toBe(2)
        ->and($chapter->scenes()->first()->getKey())->toBe($firstSceneId)
        ->and($chapter->scenes()->first()->goal)->toBe('新目标');

    $plan->update(['scene_plans' => [[
        'goal' => '最终目标', 'conflict' => '最终冲突', 'turn' => '最终转折', 'outcome' => '最终结果',
    ]]]);
    $action->execute($chapter);

    expect($chapter->scenes()->count())->toBe(1)
        ->and($chapter->scenes()->sole()->sequence)->toBe(1);
});

test('sync refuses to overwrite scenes that entered generation', function () {
    $chapter = Chapter::factory()->create();
    ChapterPlan::factory()->for($chapter)->create();
    $scene = Scene::factory()->for($chapter)->create([
        'sequence' => 1,
        'status' => SceneStatus::Draft,
        'goal' => '已生成场景',
    ]);

    expect(fn () => app(SyncScenesFromChapterPlanAction::class)->execute($chapter))
        ->toThrow(ValidationException::class);

    expect($scene->fresh()->goal)->toBe('已生成场景');
});

test('sync requires a chapter plan', function () {
    $chapter = Chapter::factory()->create();

    expect(fn () => app(SyncScenesFromChapterPlanAction::class)->execute($chapter))
        ->toThrow(ValidationException::class);
});
