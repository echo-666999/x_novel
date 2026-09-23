<?php

use App\Actions\Novels\ApplyNovelBlueprintAction;
use App\Actions\Novels\CreateBibleVersionAction;
use App\Actions\Novels\StartNovelGenerationAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Providers\FakeAiProvider;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\Pages\ManageNovelOutline;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\StoryArc;
use App\Models\StoryEvent;
use App\Models\User;
use App\Services\NovelOutlineChecksum;
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
        'outline' => [
            'title' => '六环余光全书大纲',
            'summary' => '林昼寻找太阳档案并恢复城市光明。',
            'must_include' => ['恢复太阳档案'],
            'must_not_include' => ['机械降神'],
            'baseline_completions' => [],
            'volumes' => [[
                'key' => 'v1', 'sequence' => 1, 'title' => '余光', 'goal' => '越过第一道环墙', 'climax' => '发现太阳档案', 'target_words' => 200000,
                'arcs' => [[
                    'key' => 'a1', 'sequence' => 1, 'type' => 'main', 'title' => '失落太阳',
                    'goal' => '寻找太阳档案', 'stakes' => '城市将永远失去光',
                    'completion_conditions' => ['确认档案位置'],
                    'beats' => [[
                        'key' => 'beat-map', 'sequence' => 1, 'title' => '获得地图', 'summary' => '林昼获得穿越环墙所需的地图。',
                        'chapter_budget' => ['min' => 1, 'max' => 2], 'acceptance_criteria' => ['林昼取得真实地图'],
                        'must_include' => ['地图来源可验证'], 'must_not_include' => ['直接抵达终点'],
                        'character_candidates' => [], 'world_entity_candidates' => [],
                    ], [
                        'key' => 'beat-wall', 'sequence' => 2, 'title' => '穿过环墙', 'summary' => '林昼付出代价穿过第一道环墙。',
                        'chapter_budget' => ['min' => 1, 'max' => 3], 'acceptance_criteria' => ['林昼穿过第一道环墙'],
                        'must_include' => ['跨环代价'], 'must_not_include' => ['无代价通行'],
                        'character_candidates' => [], 'world_entity_candidates' => [],
                    ]],
                ]],
            ]],
        ],
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
        ->and($novel->outlines()->count())->toBe(1)
        ->and($novel->volumes()->count())->toBe(0)
        ->and($novel->generationRuns()->sole()->status)->toBe(RunStatus::Succeeded)
        ->and($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->promptVersion)->toBe('novel-planner-v5')
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.bible.required'))->toContain('style_profile')
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.bible.properties.style_profile.properties.primary_style.enum'))->toContain('passionate')
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.bible.properties.style_profile.properties.parameters.required'))->toBe(config('narrative.parameter_keys'))
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.outline.properties.volumes.items.properties.arcs.items.properties.beats.items.required'))->toContain('chapter_budget');
});

test('adopting a blueprint creates coherent planning data and refreshes an early initial state', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $fake = (new FakeAiProvider)->enqueue(novelBlueprintResponse());
    app()->instance(AiProvider::class, $fake);
    $artifact = app(NovelPlanner::class)->generate($novel, 1);

    app(ApplyNovelBlueprintAction::class)->handle($novel, $novel->outlines()->latest('version')->firstOrFail(), $artifact);
    $novel->refresh();

    expect($novel->bibles()->count())->toBe(1)
        ->and($novel->currentBible->style_profile)->toBe(data_get(novelBlueprint(), 'bible.style_profile'))
        ->and($novel->characters()->count())->toBe(1)
        ->and($novel->worldEntities()->count())->toBe(1)
        ->and($novel->volumes()->count())->toBe(1)
        ->and($novel->storyArcs()->count())->toBe(1)
        ->and($novel->foreshadowings()->count())->toBe(1)
        ->and($novel->currentOutline->status->value)->toBe('current')
        ->and($novel->volumes()->sole()->outline_key)->toBe('v1')
        ->and($novel->storyArcs()->sole()->outline_key)->toBe('a1')
        ->and($novel->storyArcs()->sole()->beats[0]['key'])->toBe('beat-map')
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

test('a ready plan can enter generation and an incomplete plan cannot', function () {
    $incomplete = Novel::factory()->create(['status' => NovelStatus::Draft]);

    expect(fn () => app(StartNovelGenerationAction::class)->handle($incomplete))
        ->toThrow(ValidationException::class, '规划尚未就绪');

    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = (new FakeAiProvider)->enqueue(novelBlueprintResponse());
    app()->instance(AiProvider::class, $fake);
    $artifact = app(NovelPlanner::class)->generate($novel, 1);
    app(ApplyNovelBlueprintAction::class)->handle($novel, $novel->outlines()->latest('version')->firstOrFail(), $artifact);

    app(StartNovelGenerationAction::class)->handle($novel);

    expect($novel->fresh()->status)->toBe(NovelStatus::Generating);
});

test('the outline workspace guides a draft through ai planning and generation readiness', function () {
    $this->actingAs(User::factory()->create());
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = (new FakeAiProvider)->enqueue(novelBlueprintResponse());
    app()->instance(AiProvider::class, $fake);

    Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
        ->assertActionVisible('generateOutlineCandidate')
        ->callAction('generateOutlineCandidate', ['volume_count' => 1])
        ->assertNotified('AI 大纲候选已保存为 Draft Version')
        ->assertActionVisible('applyOutline')
        ->callAction('applyOutline')
        ->assertNotified('Current Novel Outline 已采用');

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertActionDoesNotExist('generateNovelBlueprint')
        ->assertActionVisible('startNovelGeneration')
        ->callAction('startNovelGeneration')
        ->assertNotified('小说已进入生成阶段')
        ->assertActionVisible('generateNextChapter');
});

test('manual outline creation calls no provider and editing creates a new immutable version', function () {
    $this->actingAs(User::factory()->create());
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);
    $first = novelBlueprint()['outline'];

    Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
        ->callAction('saveManualOutline', $first)
        ->assertNotified('大纲 Draft Version 已创建');

    $firstVersion = $novel->outlines()->sole();
    $second = $first;
    $second['summary'] = '人工修订后的全书摘要。';

    Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
        ->callAction('saveManualOutline', $second)
        ->assertNotified('大纲 Draft Version 已创建');

    expect($fake->requests())->toHaveCount(0)
        ->and($novel->outlines()->count())->toBe(2)
        ->and($firstVersion->fresh()->status)->toBe(NovelOutlineStatus::Superseded)
        ->and($novel->outlines()->latest('version')->first()->content['summary'])->toBe('人工修订后的全书摘要。')
        ->and($novel->generationRuns()->count())->toBe(0);
});

test('a manual outline can be adopted without a provider after its bible is prepared', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);
    app(CreateBibleVersionAction::class)->execute($novel, novelBlueprint()['bible']);
    $content = novelBlueprint()['outline'];
    $outline = NovelOutline::factory()->for($novel)->create([
        'source' => NovelOutlineSource::Manual,
        'content' => $content,
        'checksum' => app(NovelOutlineChecksum::class)->for($content),
    ]);

    app(ApplyNovelBlueprintAction::class)->handle($novel, $outline);

    expect($fake->requests())->toHaveCount(0)
        ->and($novel->fresh()->current_outline_id)->toBe($outline->getKey())
        ->and($outline->fresh()->status)->toBe(NovelOutlineStatus::Current)
        ->and($novel->volumes()->count())->toBe(1)
        ->and($novel->storyArcs()->count())->toBe(1)
        ->and($novel->storyStateVersions()->count())->toBe(1);
});

test('editing an ai outline preserves its artifact and local regeneration creates another artifact and outline version', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $firstResponse = novelBlueprintResponse();
    $revisedData = novelBlueprint()['outline'];
    $revisedData['volumes'][0]['arcs'][0]['beats'][0]['summary'] = '林昼通过旧档案保管人取得真实地图。';
    $regenerationResponse = new AiResponse(
        content: json_encode($revisedData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $revisedData,
        inputTokens: 100,
        outputTokens: 300,
        cachedTokens: 0,
        latencyMs: 30,
        providerRequestId: 'outline-regen-test',
        model: 'planner-test',
    );
    $fake = (new FakeAiProvider)->enqueue($firstResponse)->enqueue($regenerationResponse);
    app()->instance(AiProvider::class, $fake);
    $artifact = app(NovelPlanner::class)->generate($novel, 1);
    $firstOutline = $novel->outlines()->sole();

    $secondOutline = app(NovelPlanner::class)->regenerateNode(
        $novel,
        $firstOutline,
        $artifact,
        'beat-map',
        '让地图来源更具体。',
    );

    expect($firstOutline->fresh()->status)->toBe(NovelOutlineStatus::Superseded)
        ->and($secondOutline->version)->toBe(2)
        ->and($secondOutline->based_on_outline_id)->toBe($firstOutline->getKey())
        ->and(data_get($secondOutline->content, 'volumes.0.arcs.0.beats.0.summary'))->toContain('旧档案保管人')
        ->and($novel->generationRuns()->count())->toBe(2)
        ->and($novel->generationRuns()->withCount('artifacts')->get()->sum('artifacts_count'))->toBe(2);
});

test('applying the same current outline twice has exactly once planning effects', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = (new FakeAiProvider)->enqueue(novelBlueprintResponse());
    app()->instance(AiProvider::class, $fake);
    $artifact = app(NovelPlanner::class)->generate($novel, 1);
    $outline = $novel->outlines()->sole();

    $first = app(ApplyNovelBlueprintAction::class)->handle($novel, $outline, $artifact);
    $second = app(ApplyNovelBlueprintAction::class)->handle($novel, $outline->fresh(), $artifact);

    expect($second->getKey())->toBe($first->getKey())
        ->and($novel->bibles()->count())->toBe(1)
        ->and($novel->volumes()->count())->toBe(1)
        ->and($novel->storyArcs()->count())->toBe(1)
        ->and($novel->characters()->count())->toBe(1)
        ->and($novel->worldEntities()->count())->toBe(1)
        ->and($novel->storyStateVersions()->count())->toBe(1);
});

test('apply failure rolls back the complete initial planning transaction', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = (new FakeAiProvider)->enqueue(novelBlueprintResponse());
    app()->instance(AiProvider::class, $fake);
    $artifact = app(NovelPlanner::class)->generate($novel, 1);
    $outline = $novel->outlines()->sole();
    StoryArc::creating(fn () => throw new RuntimeException('forced story arc failure'));

    try {
        expect(fn () => app(ApplyNovelBlueprintAction::class)->handle($novel, $outline, $artifact))
            ->toThrow(RuntimeException::class, 'forced story arc failure');
    } finally {
        StoryArc::flushEventListeners();
    }

    expect($novel->fresh()->current_outline_id)->toBeNull()
        ->and($outline->fresh()->status)->toBe(NovelOutlineStatus::Draft)
        ->and($novel->bibles()->count())->toBe(0)
        ->and($novel->characters()->count())->toBe(0)
        ->and($novel->worldEntities()->count())->toBe(0)
        ->and($novel->volumes()->count())->toBe(0)
        ->and($novel->storyArcs()->count())->toBe(0)
        ->and($novel->storyStateVersions()->count())->toBe(0);
});

test('first outline apply rejects novels that already have a chapter or story event', function (string $existing) {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft]);
    $outline = NovelOutline::factory()->for($novel)->create([
        'content' => novelBlueprint()['outline'],
        'checksum' => app(NovelOutlineChecksum::class)->for(novelBlueprint()['outline']),
    ]);

    if ($existing === 'chapter') {
        Chapter::factory()->for($novel)->create();
    } else {
        StoryEvent::factory()->create(['novel_id' => $novel->getKey()]);
    }

    expect(fn () => app(ApplyNovelBlueprintAction::class)->handle($novel, $outline))
        ->toThrow(ValidationException::class, '章节或正式事件');
    expect($novel->volumes()->count())->toBe(0);
})->with(['chapter', 'event']);
