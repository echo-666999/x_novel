<?php

use App\Actions\Novels\ApplyNovelBlueprintAction;
use App\Actions\Novels\CreateBibleVersionAction;
use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Actions\Novels\StartNovelGenerationAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Data\NormalizedNovelOutline;
use App\Enums\ArtifactType;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\Pages\ManageNovelOutline;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Jobs\FinalizeNovelOutlineJob;
use App\Jobs\GenerateNovelBeatDetailJob;
use App\Jobs\GenerateNovelFoundationJob;
use App\Jobs\GenerateNovelOutlineJob;
use App\Jobs\GenerateNovelOutlineSkeletonJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\StoryEvent;
use App\Models\User;
use App\Services\NovelOutlinePipeline;
use App\Services\NovelPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
            'volumes' => [[
                'key' => 'vol-01', 'sequence' => 1, 'title' => '余光', 'goal' => '越过第一道环墙', 'climax' => '发现太阳档案', 'target_words' => 200000,
                'arcs' => [[
                    'key' => 'arc-01', 'sequence' => 1, 'mainline_sequence' => 1, 'type' => 'main', 'title' => '失落太阳',
                    'goal' => '寻找太阳档案', 'stakes' => '城市将永远失去光',
                    'completion_conditions' => ['确认档案位置'],
                    'beats' => [[
                        'key' => 'beat-01', 'sequence' => 1, 'mainline_sequence' => 1, 'title' => '获得地图', 'summary' => '林昼获得穿越环墙所需的地图。',
                        'chapter_budget' => ['min' => 1, 'max' => 2], 'acceptance_criteria' => ['林昼取得真实地图'],
                        'must_include' => ['地图来源可验证'], 'must_not_include' => ['直接抵达终点'],
                        'character_candidates' => [], 'world_entity_candidates' => [],
                        'milestones' => [[
                            'key' => 'beat-01-m01', 'sequence' => 1, 'title' => '取得地图', 'objective' => '确认地图来源。',
                            'acceptance_criteria' => ['林昼取得真实地图'], 'must_include' => ['地图来源可验证'], 'must_not_include' => [],
                        ]],
                        'handoff' => [
                            'next_beat_key' => 'beat-02', 'transition_mode' => 'causal', 'exit_result' => '地图来源已经确认。',
                            'next_trigger' => '地图指向第一道环墙。', 'carried_states' => [], 'open_threads' => [],
                            'required_transition' => [], 'forbidden_jump' => [],
                        ],
                    ], [
                        'key' => 'beat-02', 'sequence' => 2, 'mainline_sequence' => 2, 'title' => '穿过环墙', 'summary' => '林昼付出代价穿过第一道环墙。',
                        'chapter_budget' => ['min' => 1, 'max' => 3], 'acceptance_criteria' => ['林昼穿过第一道环墙'],
                        'must_include' => ['跨环代价'], 'must_not_include' => ['无代价通行'],
                        'character_candidates' => [], 'world_entity_candidates' => [],
                        'milestones' => [[
                            'key' => 'beat-02-m01', 'sequence' => 1, 'title' => '跨越环墙', 'objective' => '付出代价后通过环墙。',
                            'acceptance_criteria' => ['林昼穿过第一道环墙'], 'must_include' => ['跨环代价'], 'must_not_include' => [],
                        ]],
                        'handoff' => [
                            'next_beat_key' => null, 'transition_mode' => null, 'exit_result' => null, 'next_trigger' => null,
                            'carried_states' => [], 'open_threads' => [], 'required_transition' => [], 'forbidden_jump' => [],
                        ],
                    ]],
                ]],
            ]],
        ],
        'foreshadowings' => [[
            'title' => '墙上的余光', 'description' => '墙面会在午夜发亮', 'promised_payoff' => '揭示太阳仍然存在',
            'due_from_chapter' => 10, 'due_to_chapter' => 20, 'importance' => 'high', 'owner_arc_key' => 'arc-01',
        ]],
    ];
}

function outlineStageResponse(array $data, string $requestId): AiResponse
{
    return new AiResponse(
        content: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $data,
        inputTokens: 100,
        outputTokens: 500,
        cachedTokens: 0,
        latencyMs: 50,
        providerRequestId: $requestId,
        model: 'planner-test',
    );
}

/** @return array<int, AiResponse> */
function stagedNovelBlueprintResponses(?array $blueprint = null): array
{
    $blueprint ??= novelBlueprint();
    $foundation = collect($blueprint)->only(['bible', 'characters', 'world_entities', 'foreshadowings'])->all();
    $skeleton = $blueprint['outline'];
    $details = [];
    foreach ($skeleton['volumes'] as &$volume) {
        foreach ($volume['arcs'] as &$arc) {
            foreach ($arc['beats'] as &$beat) {
                if ($arc['type'] === 'main') {
                    $details[] = [
                        'beat_key' => $beat['key'],
                        'milestones' => $beat['milestones'],
                        'handoff' => $beat['handoff'],
                    ];
                }
                unset($beat['milestones'], $beat['handoff']);
            }
            unset($beat);
        }
        unset($arc);
    }
    unset($volume);

    return [
        outlineStageResponse($foundation, 'foundation-test'),
        outlineStageResponse($skeleton, 'skeleton-test'),
        ...array_map(
            fn (array $detail, int $index): AiResponse => outlineStageResponse($detail, 'beat-detail-'.($index + 1)),
            $details,
            array_keys($details),
        ),
    ];
}

function stagedNovelBlueprintProvider(?array $blueprint = null): FakeAiProvider
{
    $fake = new FakeAiProvider;
    foreach (stagedNovelBlueprintResponses($blueprint) as $response) {
        $fake->enqueue($response);
    }

    return $fake;
}

test('ai planning creates a reusable blueprint without changing planning tables', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);

    $first = app(NovelPlanner::class)->generate($novel, 1);
    $second = app(NovelPlanner::class)->generate($novel, 1);

    expect($first->is($second))->toBeTrue()
        ->and($novel->fresh()->status)->toBe(NovelStatus::Planning)
        ->and($novel->bibles()->count())->toBe(0)
        ->and($novel->outlines()->count())->toBe(1)
        ->and($novel->volumes()->count())->toBe(0)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->sole()->status)->toBe(RunStatus::Succeeded)
        ->and($fake->requests())->toHaveCount(4)
        ->and($fake->requests()[0]->promptVersion)->toBe(NovelOutlinePipeline::FOUNDATION_PROMPT_VERSION)
        ->and($fake->requests()[1]->promptVersion)->toBe(NovelOutlinePipeline::SKELETON_PROMPT_VERSION)
        ->and($fake->requests()[2]->promptVersion)->toBe(NovelOutlinePipeline::BEAT_DETAIL_PROMPT_VERSION)
        ->and($fake->requests()[0]->systemPrompt)->toContain('规划语言必须落到具体人物、动作、选择、阻力、因果和可观察变化')
        ->and($fake->requests()[0]->maxTokens)->toBe(NovelOutlinePipeline::FOUNDATION_MAX_TOKENS)
        ->and($fake->requests()[0]->reasoningEffort)->toBeNull()
        ->and(data_get($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->sole()->context_snapshot, 'generation_preferences.reasoning_effort'))->toBeNull()
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.bible.required'))->toContain('style_profile')
        ->and(data_get($fake->requests()[1]->responseSchema, 'properties.volumes.minItems'))->toBe(1)
        ->and(data_get($fake->requests()[1]->responseSchema, 'properties.volumes.maxItems'))->toBe(1)
        ->and(data_get($fake->requests()[1]->responseSchema, 'properties.volumes.items.properties.arcs.items.properties.beats.items.required'))->toContain('chapter_budget');
});

test('ai planning response schema contains only strict objects accepted by the provider', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);

    app(NovelPlanner::class)->generate($novel, 1);

    $schemas = collect($fake->requests())->pluck('responseSchema');
    $invalidObjects = [];
    $inspect = function (mixed $node, string $path = '$') use (&$inspect, &$invalidObjects): void {
        if (! is_array($node)) {
            return;
        }

        if (($node['type'] ?? null) === 'object') {
            $properties = array_keys($node['properties'] ?? []);
            $required = $node['required'] ?? [];

            if (($node['additionalProperties'] ?? null) !== false || $properties !== $required) {
                $invalidObjects[] = $path;
            }
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $inspect($value, $path.'.'.$key);
            }
        }
    };
    $schemas->each(fn (array $schema) => $inspect($schema));

    expect($invalidObjects)->toBe([])
        ->and(data_get($schemas[1], 'properties.volumes.items.properties.key.pattern'))->toBe('^vol-[0-9]{2}$')
        ->and(data_get($schemas[1], 'properties.volumes.items.properties.arcs.items.properties.key.pattern'))->toBe('^arc-[0-9]{2,}$')
        ->and(data_get($schemas[1], 'properties.volumes.items.properties.arcs.items.properties.beats.items.properties.key.pattern'))->toBe('^beat-[0-9]{2,}$')
        ->and(data_get($schemas[2], 'properties.milestones.type'))->toBe('array');
});

test('ai planning derives sibling sequences from array order', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $data = novelBlueprint();
    $data['outline']['volumes'][0]['sequence'] = 10;
    $data['outline']['volumes'][0]['arcs'][0]['sequence'] = 20;
    $data['outline']['volumes'][0]['arcs'][0]['beats'][0]['sequence'] = 30;
    $data['outline']['volumes'][0]['arcs'][0]['beats'][1]['sequence'] = 40;
    $fake = stagedNovelBlueprintProvider($data);
    app()->instance(AiProvider::class, $fake);

    $artifact = app(NovelPlanner::class)->generate($novel, 1);

    expect(data_get($artifact->data, 'outline.volumes.0.sequence'))->toBe(1)
        ->and(data_get($artifact->data, 'outline.volumes.0.arcs.0.sequence'))->toBe(1)
        ->and(data_get($artifact->data, 'outline.volumes.0.arcs.0.beats.0.sequence'))->toBe(1)
        ->and(data_get($artifact->data, 'outline.volumes.0.arcs.0.beats.1.sequence'))->toBe(2);
});

test('adopting a blueprint creates coherent planning data and refreshes an early initial state', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $fake = stagedNovelBlueprintProvider();
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
        ->and($novel->volumes()->sole()->sourceOutlineVolume->volume_key)->toBe('vol-01')
        ->and($novel->storyArcs()->sole()->sourceOutlineArc->arc_key)->toBe('arc-01')
        ->and($novel->storyArcs()->sole()->sourceOutlineArc->beats()->firstOrFail()->beat_key)->toBe('beat-01')
        ->and($novel->canonicalStateVersion->state['characters'])->not->toBeEmpty();
});

test('ai planning rejects a blueprint without a complete style profile', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $data = novelBlueprint();
    unset($data['bible']['style_profile']);
    $fake = stagedNovelBlueprintProvider($data);
    app()->instance(AiProvider::class, $fake);

    try {
        app(NovelPlanner::class)->generate($novel, 1);
        test()->fail('Expected blueprint style validation to fail.');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->implode(' '))->toContain('严格 Schema 不一致');
    }

    expect($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->sole()->status)->toBe(RunStatus::Failed)
        ->and($novel->generationRuns()->withCount('artifacts')->get()->sum('artifacts_count'))->toBe(0)
        ->and($novel->bibles()->count())->toBe(0);
});

test('a ready plan can enter generation and an incomplete plan cannot', function () {
    $incomplete = Novel::factory()->create(['status' => NovelStatus::Draft]);

    expect(fn () => app(StartNovelGenerationAction::class)->handle($incomplete))
        ->toThrow(ValidationException::class, '规划尚未就绪');

    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);
    $artifact = app(NovelPlanner::class)->generate($novel, 1);
    app(ApplyNovelBlueprintAction::class)->handle($novel, $novel->outlines()->latest('version')->firstOrFail(), $artifact);

    app(StartNovelGenerationAction::class)->handle($novel);

    expect($novel->fresh()->status)->toBe(NovelStatus::Generating);
});

test('the outline workspace guides a draft through ai planning and generation readiness', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);

    Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
        ->assertActionVisible('generateOutlineCandidate')
        ->callAction('generateOutlineCandidate', ['volume_count' => 1])
        ->assertNotified('AI 大纲候选已加入生成队列');

    Queue::assertPushed(GenerateNovelOutlineJob::class, fn (GenerateNovelOutlineJob $job): bool => $job->novelId === $novel->getKey()
        && $job->volumeCount === 1
        && $job->queue === 'generation');
    expect($fake->requests())->toHaveCount(0);

    $pipeline = app(NovelOutlinePipeline::class);
    (new GenerateNovelOutlineJob($novel->getKey(), 1))->handle($pipeline);
    $batch = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->sole();
    (new GenerateNovelFoundationJob($batch->getKey()))->handle($pipeline);
    (new GenerateNovelOutlineSkeletonJob($batch->getKey()))->handle($pipeline);
    (new GenerateNovelBeatDetailJob($batch->getKey(), 'beat-01'))->handle($pipeline);
    (new GenerateNovelBeatDetailJob($batch->getKey(), 'beat-02'))->handle($pipeline);
    (new FinalizeNovelOutlineJob($batch->getKey()))->handle($pipeline);

    Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
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
        ->and($novel->outlines()->latest('version')->first()->summary)->toBe('人工修订后的全书摘要。')
        ->and($novel->generationRuns()->count())->toBe(0);
});

test('a manual outline can be adopted without a provider after its bible is prepared', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);
    app(CreateBibleVersionAction::class)->execute($novel, novelBlueprint()['bible']);
    $content = novelBlueprint()['outline'];
    $outline = app(CreateNormalizedNovelOutlineVersionAction::class)->handle(
        $novel,
        $content,
        NovelOutlineSource::Manual,
    );

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
    $revisedData = novelBlueprint()['outline'];
    $revisedData['volumes'][0]['arcs'][0]['beats'][0]['summary'] = '林昼通过旧档案保管人取得真实地图。';
    $revisedNode = $revisedData['volumes'][0]['arcs'][0]['beats'][0];
    foreach ($revisedNode['character_candidates'] as &$candidate) {
        unset($candidate['possible_duplicate_character_ids']);
    }
    unset($candidate);
    foreach ($revisedNode['world_entity_candidates'] as &$candidate) {
        unset($candidate['possible_duplicate_entity_ids']);
    }
    unset($candidate);
    $regenerationResponse = new AiResponse(
        content: json_encode(['node' => $revisedNode], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: ['node' => $revisedNode],
        inputTokens: 100,
        outputTokens: 300,
        cachedTokens: 0,
        latencyMs: 30,
        providerRequestId: 'outline-regen-test',
        model: 'planner-test',
    );
    $fake = stagedNovelBlueprintProvider();
    $fake->enqueue($regenerationResponse);
    app()->instance(AiProvider::class, $fake);
    $artifact = app(NovelPlanner::class)->generate($novel, 1);
    $firstOutline = $novel->outlines()->sole();

    $secondOutline = app(NovelPlanner::class)->regenerateNode(
        $novel,
        $firstOutline,
        $artifact,
        'beat-01',
        '让地图来源更具体。',
    );

    expect($firstOutline->fresh()->status)->toBe(NovelOutlineStatus::Superseded)
        ->and($secondOutline->version)->toBe(2)
        ->and($secondOutline->based_on_outline_id)->toBe($firstOutline->getKey())
        ->and(data_get(NormalizedNovelOutline::fromModel($secondOutline)->toArray(), 'volumes.0.arcs.0.beats.0.summary'))->toContain('旧档案保管人')
        ->and($fake->requests()[4]->prompt)->not->toContain('"volumes"')
        ->and(data_get($fake->requests()[4]->responseSchema, 'required'))->toBe(['node'])
        ->and(data_get($fake->requests()[4]->responseSchema, 'properties.node.properties.character_candidates.items.properties'))->not->toHaveKey('possible_duplicate_character_ids')
        ->and($novel->generationRuns()->count())->toBe(7)
        ->and($novel->generationRuns()->withCount('artifacts')->get()->sum('artifacts_count'))->toBe(6);
});

test('applying the same current outline twice has exactly once planning effects', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
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
    $fake = stagedNovelBlueprintProvider();
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
    $outline = app(CreateNormalizedNovelOutlineVersionAction::class)->handle($novel, novelBlueprint()['outline']);

    if ($existing === 'chapter') {
        Chapter::factory()->for($novel)->create();
    } else {
        StoryEvent::factory()->create(['novel_id' => $novel->getKey()]);
    }

    expect(fn () => app(ApplyNovelBlueprintAction::class)->handle($novel, $outline))
        ->toThrow(ValidationException::class, '章节或正式事件');
    expect($novel->volumes()->count())->toBe(0);
})->with(['chapter', 'event']);

test('outline stages freeze the batch route and bind every child run to the batch', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $frozenProvider = $batch->provider;
    $frozenModel = $batch->model_policy;

    config(['ai.provider' => 'changed-provider', 'ai.models.planner' => 'changed-model']);
    expect($pipeline->startOrResume($novel, 1)->is($batch))->toBeTrue();
    $pipeline->generateFoundation($batch);
    $skeleton = $pipeline->generateSkeleton($batch);
    foreach (['beat-01', 'beat-02'] as $beatKey) {
        $pipeline->generateBeatDetail($batch, $beatKey);
    }
    $pipeline->finalize($batch);

    expect($fake->requests())->each(
        fn ($request) => $request->provider->toBe($frozenProvider)->model->toBe($frozenModel)
    );
    expect($novel->generationRuns()->where('id', '!=', $batch->getKey())->get())
        ->each(fn ($run) => $run->scope_id->toBe($batch->getKey())->provider->toBe($frozenProvider)->model_policy->toBe($frozenModel));
    expect($skeleton)->not->toBeNull()
        ->and($novel->outlines()->count())->toBe(1);
});

test('a failed beat detail resumes only that beat and reuses successful stage artifacts', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $responses = stagedNovelBlueprintResponses();
    $fake = (new FakeAiProvider)
        ->enqueue($responses[0])
        ->enqueue($responses[1])
        ->enqueue($responses[2])
        ->enqueue(new AiProviderException('provider_timeout', 'temporary timeout', true))
        ->enqueue($responses[3]);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $foundation = $pipeline->generateFoundation($batch);
    $skeleton = $pipeline->generateSkeleton($batch);
    $firstDetail = $pipeline->generateBeatDetail($batch, 'beat-01');

    expect(fn () => $pipeline->generateBeatDetail($batch, 'beat-02'))
        ->toThrow(AiProviderException::class, 'temporary timeout');
    expect($novel->outlines()->count())->toBe(0)
        ->and($novel->bibles()->count())->toBe(0)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BEAT_DETAIL_SCOPE)->where('status', RunStatus::Failed)->count())->toBe(1);

    $pipeline->generateBeatDetail($batch, 'beat-02');
    $pipeline->finalize($batch);

    expect($foundation?->fresh()->checksum)->toBe($foundation?->checksum)
        ->and($skeleton?->fresh()->checksum)->toBe($skeleton?->checksum)
        ->and($firstDetail?->fresh()->checksum)->toBe($firstDetail?->checksum)
        ->and($fake->requests())->toHaveCount(5)
        ->and($novel->outlines()->count())->toBe(1);
});

test('pause prevents a new outline stage and resume continues from the last artifact', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $pipeline->generateFoundation($batch);
    $novel->update(['status' => NovelStatus::Paused]);

    expect(fn () => $pipeline->generateSkeleton($batch))->toThrow(AiProviderException::class, '小说已暂停');
    expect($fake->requests())->toHaveCount(1);

    $novel->update(['status' => NovelStatus::Planning]);
    $pipeline->generateSkeleton($batch);
    expect($fake->requests())->toHaveCount(2)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->count())->toBe(1);
});

test('a provider result that crashes before artifact persistence is recorded as uncertain and can recover locally', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $responses = stagedNovelBlueprintResponses();
    $fake = (new FakeAiProvider)->enqueue($responses[0])->enqueue($responses[0]);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $failPersistence = true;
    GenerationArtifact::creating(function (GenerationArtifact $artifact) use (&$failPersistence): void {
        if ($failPersistence && $artifact->type === ArtifactType::OutlineFoundation) {
            throw new RuntimeException('forced artifact persistence crash');
        }
    });

    try {
        expect(fn () => $pipeline->generateFoundation($batch))->toThrow(RuntimeException::class, 'forced artifact persistence crash');
    } finally {
        $failPersistence = false;
    }

    $failed = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->sole();
    expect($failed->error_code)->toBe('outline_stage_result_uncertain')
        ->and(data_get($failed->error_metadata, 'result_uncertain'))->toBeTrue()
        ->and($failed->artifacts()->count())->toBe(0)
        ->and($novel->outlines()->count())->toBe(0);

    $artifact = $pipeline->generateFoundation($batch);
    expect($artifact)->not->toBeNull()
        ->and($fake->requests())->toHaveCount(2)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->count())->toBe(2);
});

test('finalize rejects a missing beat detail without creating a partial outline', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $responses = stagedNovelBlueprintResponses();
    $fake = (new FakeAiProvider)->enqueue($responses[0])->enqueue($responses[1])->enqueue($responses[2]);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $pipeline->generateFoundation($batch);
    $pipeline->generateSkeleton($batch);
    $pipeline->generateBeatDetail($batch, 'beat-01');

    expect(fn () => $pipeline->finalize($batch))->toThrow(ValidationException::class, '缺少 Detail Artifact');
    expect($novel->outlines()->count())->toBe(0)
        ->and($novel->volumes()->count())->toBe(0)
        ->and($novel->bibles()->count())->toBe(0);
});

test('apply rejects an incomplete final artifact lineage before any formal initialization write', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);
    $artifact = app(NovelPlanner::class)->generate($novel, 1);
    $data = $artifact->data;
    unset($data['lineage']['foundation']['checksum']);
    DB::table('generation_artifacts')->where('id', $artifact->getKey())->update(['data' => json_encode($data, JSON_THROW_ON_ERROR)]);
    $outline = $novel->outlines()->sole();

    expect(fn () => app(ApplyNovelBlueprintAction::class)->handle($novel, $outline))
        ->toThrow(ValidationException::class, 'Checksum');
    expect($novel->fresh()->current_outline_id)->toBeNull()
        ->and($novel->bibles()->count())->toBe(0)
        ->and($novel->characters()->count())->toBe(0)
        ->and($novel->worldEntities()->count())->toBe(0)
        ->and($novel->volumes()->count())->toBe(0);
});
