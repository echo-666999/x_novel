<?php

use App\Actions\Novels\ApplyNovelBlueprintAction;
use App\Actions\Novels\StartNovelGenerationAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Providers\FakeAiProvider;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\Novel;
use App\Models\User;
use App\Services\NovelPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function novelBlueprint(): array
{
    return [
        'bible' => [
            'logline' => '失忆测绘师在六环城寻找被删除的太阳。',
            'themes' => ['记忆', '选择'],
            'tone' => '克制而紧张',
            'pov' => '第三人称限知',
            'tense' => '过去时',
            'taboos' => ['机械降神'],
            'hard_constraints' => ['死亡不可逆'],
            'style_profile' => [
                'subgenre' => '东方玄幻',
                'target_platform' => 'qidian',
                'primary_style' => 'passionate',
                'secondary_styles' => ['accessible_brisk'],
                'language_era' => 'modern_spoken',
                'pacing' => 'fast',
                'parameters' => [
                    'ornateness' => 2,
                    'dialogue_ratio' => 4,
                    'description_density' => 3,
                    'psychology_density' => 2,
                    'humor_level' => 1,
                    'literary_level' => 2,
                ],
            ],
            'ending_contract' => [
                'final_protagonist_state' => '接受真实记忆',
                'main_conflict_resolution' => '恢复太阳档案',
                'theme_payoff' => '选择比记忆更能定义一个人',
                'required_foreshadowing_payoff' => ['六环余光来源'],
                'character_arc_requirements' => ['主角停止逃避'],
                'allowed_open_endings' => ['城外世界'],
            ],
        ],
        'characters' => [[
            'name' => '林昼', 'role' => '主角', 'motivation' => '找回真相',
            'profile' => ['失忆测绘师'], 'personality' => ['谨慎'], 'abilities' => ['空间测绘'],
            'knowledge' => ['不知道太阳档案'], 'current_state' => ['location' => '第六环', 'summary' => '准备启程'],
        ]],
        'world_entities' => [[
            'type' => 'location', 'name' => '六环城', 'description' => '被六层环墙包围的城市',
            'attributes' => ['终年无日'], 'rules' => ['跨环需要许可'], 'current_state' => ['封锁中'],
        ]],
        'volumes' => [[
            'key' => 'v1', 'title' => '余光', 'goal' => '越过第一道环墙', 'climax' => '发现太阳档案', 'target_words' => 200000,
        ]],
        'story_arcs' => [[
            'key' => 'a1', 'volume_key' => 'v1', 'type' => 'main', 'title' => '失落太阳',
            'goal' => '寻找太阳档案', 'stakes' => '城市将永远失去光',
            'beats' => ['获得地图', '穿过环墙'], 'completion_conditions' => ['确认档案位置'],
        ]],
        'foreshadowings' => [[
            'title' => '墙上的余光', 'description' => '墙面会在午夜发亮', 'promised_payoff' => '揭示太阳仍然存在',
            'due_from_chapter' => 10, 'due_to_chapter' => 20, 'importance' => 'high', 'owner_arc_key' => 'a1',
        ]],
    ];
}

function novelBlueprintResponse(): AiResponse
{
    $data = novelBlueprint();

    return new AiResponse(
        content: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $data,
        inputTokens: 100,
        outputTokens: 500,
        cachedTokens: 0,
        latencyMs: 50,
        providerRequestId: 'novel-plan-test',
        model: 'planner-test',
    );
}

test('ai planning creates a reusable blueprint without changing planning tables', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = (new FakeAiProvider)->enqueue(novelBlueprintResponse());
    app()->instance(AiProvider::class, $fake);

    $first = app(NovelPlanner::class)->generate($novel, 1);
    $second = app(NovelPlanner::class)->generate($novel, 1);

    expect($first->is($second))->toBeTrue()
        ->and($novel->fresh()->status)->toBe(NovelStatus::Planning)
        ->and($novel->bibles()->count())->toBe(0)
        ->and($novel->generationRuns()->sole()->status)->toBe(RunStatus::Succeeded)
        ->and($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->promptVersion)->toBe('novel-planner-v4')
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.bible.required'))->toContain('style_profile')
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.bible.properties.style_profile.properties.primary_style.enum'))->toContain('passionate')
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.bible.properties.style_profile.properties.parameters.required'))->toBe(config('narrative.parameter_keys'));
});

test('adopting a blueprint creates coherent planning data and refreshes an early initial state', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $fake = (new FakeAiProvider)->enqueue(novelBlueprintResponse());
    app()->instance(AiProvider::class, $fake);
    $artifact = app(NovelPlanner::class)->generate($novel, 1);

    app(ApplyNovelBlueprintAction::class)->handle($novel, $artifact);
    $novel->refresh();

    expect($novel->bibles()->count())->toBe(1)
        ->and($novel->currentBible->style_profile)->toBe(data_get(novelBlueprint(), 'bible.style_profile'))
        ->and($novel->characters()->count())->toBe(1)
        ->and($novel->worldEntities()->count())->toBe(1)
        ->and($novel->volumes()->count())->toBe(1)
        ->and($novel->storyArcs()->count())->toBe(1)
        ->and($novel->foreshadowings()->count())->toBe(1)
        ->and($novel->canonicalStateVersion->state['characters'])->not->toBeEmpty();
});

test('ai planning rejects a blueprint without a complete style profile', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $data = novelBlueprint();
    unset($data['bible']['style_profile']);
    $fake = (new FakeAiProvider)->enqueue(new AiResponse(
        content: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $data,
        inputTokens: 100,
        outputTokens: 500,
        cachedTokens: 0,
        latencyMs: 50,
        providerRequestId: 'novel-plan-missing-style',
        model: 'planner-test',
    ));
    app()->instance(AiProvider::class, $fake);

    try {
        app(NovelPlanner::class)->generate($novel, 1);
        test()->fail('Expected blueprint style validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())
            ->toHaveKey('bible.style_profile')
            ->and(collect($exception->errors())->flatten()->implode(' '))->toContain('缺少完整文风设置');
    }

    expect($novel->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
        ->and($novel->generationRuns()->sole()->artifacts()->count())->toBe(0)
        ->and($novel->bibles()->count())->toBe(0);
});

test('the blueprint preview shows complete narrative and style settings before adoption', function () {
    $this->actingAs(User::factory()->create());
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = (new FakeAiProvider)->enqueue(novelBlueprintResponse());
    app()->instance(AiProvider::class, $fake);
    app(NovelPlanner::class)->generate($novel, 1);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertActionVisible('previewNovelBlueprint')
        ->mountAction('previewNovelBlueprint')
        ->assertActionMounted('previewNovelBlueprint')
        ->assertMountedActionModalSee([
            '叙事与文风基线',
            '克制而紧张',
            '第三人称限知',
            '过去时',
            '东方玄幻',
            '起点中文网',
            '热血激昂',
            '通俗爽快',
            '现代口语',
            '快节奏',
            '文风高级设置',
            '对白占比',
            '4 / 5',
        ]);
});

test('a ready plan can enter generation and an incomplete plan cannot', function () {
    $incomplete = Novel::factory()->create(['status' => NovelStatus::Draft]);

    expect(fn () => app(StartNovelGenerationAction::class)->handle($incomplete))
        ->toThrow(ValidationException::class, '规划尚未就绪');

    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = (new FakeAiProvider)->enqueue(novelBlueprintResponse());
    app()->instance(AiProvider::class, $fake);
    $artifact = app(NovelPlanner::class)->generate($novel, 1);
    app(ApplyNovelBlueprintAction::class)->handle($novel, $artifact);

    app(StartNovelGenerationAction::class)->handle($novel);

    expect($novel->fresh()->status)->toBe(NovelStatus::Generating);
});

test('the novel workspace guides a draft through ai planning and generation readiness', function () {
    $this->actingAs(User::factory()->create());
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = (new FakeAiProvider)->enqueue(novelBlueprintResponse());
    app()->instance(AiProvider::class, $fake);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertActionVisible('generateNovelBlueprint')
        ->assertActionHidden('generateNextChapter')
        ->callAction('generateNovelBlueprint', ['volume_count' => 1])
        ->assertNotified('小说规划候选方案已生成')
        ->assertActionVisible('previewNovelBlueprint')
        ->assertActionVisible('applyNovelBlueprint')
        ->callAction('applyNovelBlueprint')
        ->assertNotified('AI 小说规划已采用')
        ->assertActionVisible('startNovelGeneration')
        ->callAction('startNovelGeneration')
        ->assertNotified('小说已进入生成阶段')
        ->assertActionVisible('generateNextChapter')
        ->assertActionHidden('generateNovelBlueprint');
});
