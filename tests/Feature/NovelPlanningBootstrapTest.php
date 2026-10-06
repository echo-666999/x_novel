<?php

use App\Actions\Novels\ApplyNovelBlueprintAction;
use App\Actions\Novels\CreateBibleVersionAction;
use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Actions\Novels\ResumeNovelOutlineGenerationAction;
use App\Actions\Novels\StartNovelGenerationAction;
use App\Actions\Novels\StartNovelOutlineGenerationAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Data\NormalizedNovelOutline;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\Pages\ManageNovelOutline;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Jobs\AssembleNovelOutlineSkeletonJob;
use App\Jobs\FinalizeNovelOutlineJob;
use App\Jobs\GenerateNovelArcBeatsJob;
use App\Jobs\GenerateNovelBeatDetailJob;
use App\Jobs\GenerateNovelFoundationJob;
use App\Jobs\GenerateNovelOutlineJob;
use App\Jobs\GenerateNovelOutlineSkeletonJob;
use App\Jobs\GenerateNovelOutlineStructureJob;
use App\Models\AIModelPrice;
use App\Models\AIModelRoute;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\StoryEvent;
use App\Models\User;
use App\Services\GenerationJobDispatcher;
use App\Services\NovelOutlinePipeline;
use App\Services\NovelOutlineStageContract;
use App\Services\NovelPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedVerifiedOutlineModelProfiles();
});

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
                'target_platform' => 'fanqie',
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
    $structure = collect($skeleton)->only(['title', 'summary', 'must_include', 'must_not_include'])->all();
    $structure['volumes'] = [];
    $arcBeats = [];
    $details = [];
    foreach ($skeleton['volumes'] as $volume) {
        $structureVolume = collect($volume)->only(['key', 'title', 'goal', 'climax', 'target_words'])->all();
        $structureVolume['arcs'] = [];
        foreach ($volume['arcs'] as $arc) {
            $structureVolume['arcs'][] = collect($arc)->only(['key', 'type', 'title', 'goal', 'stakes', 'completion_conditions'])->all();
            $arcResponse = ['arc_key' => $arc['key'], 'beats' => []];
            foreach ($arc['beats'] as $beat) {
                if ($arc['type'] === 'main') {
                    $milestones = $beat['milestones'];
                    foreach ($milestones as &$milestone) {
                        unset($milestone['key'], $milestone['sequence']);
                    }
                    unset($milestone);
                    $handoff = $beat['handoff'];
                    unset($handoff['next_beat_key']);
                    $details[] = [
                        'milestones' => $milestones,
                        'handoff' => $handoff,
                    ];
                }
                unset($beat['key'], $beat['sequence'], $beat['mainline_sequence'], $beat['milestones'], $beat['handoff']);
                foreach ($beat['character_candidates'] as &$candidate) {
                    unset($candidate['candidate_key'], $candidate['possible_duplicate_character_ids']);
                }
                unset($candidate);
                foreach ($beat['world_entity_candidates'] as &$candidate) {
                    unset($candidate['candidate_key'], $candidate['possible_duplicate_entity_ids']);
                }
                unset($candidate);
                $arcResponse['beats'][] = $beat;
            }
            $arcBeats[] = $arcResponse;
        }
        $structure['volumes'][] = $structureVolume;
    }

    return [
        outlineStageResponse($foundation, 'foundation-test'),
        outlineStageResponse($structure, 'structure-test'),
        ...array_map(
            fn (array $arc, int $index): AiResponse => outlineStageResponse($arc, 'arc-beats-'.($index + 1)),
            $arcBeats,
            array_keys($arcBeats),
        ),
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

function novelBlueprintWithTwoArcs(): array
{
    $blueprint = novelBlueprint();
    $arc = $blueprint['outline']['volumes'][0]['arcs'][0];
    $arc['key'] = 'arc-02';
    $arc['sequence'] = 2;
    $arc['mainline_sequence'] = null;
    $arc['type'] = 'subplot';
    $arc['title'] = '城墙守卫';
    $arc['goal'] = '取得守卫的有限信任';
    $arc['beats'] = [
        ...array_slice($arc['beats'], 0, 1),
    ];
    $arc['beats'][0]['key'] = 'beat-03';
    $arc['beats'][0]['sequence'] = 1;
    $arc['beats'][0]['mainline_sequence'] = null;
    $arc['beats'][0]['milestones'] = [];
    $arc['beats'][0]['handoff'] = [
        'next_beat_key' => null, 'transition_mode' => null, 'exit_result' => null, 'next_trigger' => null,
        'carried_states' => [], 'open_threads' => [], 'required_transition' => [], 'forbidden_jump' => [],
    ];
    $blueprint['outline']['volumes'][0]['arcs'][] = $arc;

    return $blueprint;
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
        ->and($fake->requests())->toHaveCount(5)
        ->and($fake->requests()[0]->promptVersion)->toBe(NovelOutlinePipeline::FOUNDATION_PROMPT_VERSION)
        ->and($fake->requests()[1]->promptVersion)->toBe(NovelOutlineStageContract::STRUCTURE_PROMPT_VERSION)
        ->and($fake->requests()[2]->promptVersion)->toBe(NovelOutlineStageContract::ARC_BEATS_PROMPT_VERSION)
        ->and($fake->requests()[3]->promptVersion)->toBe(NovelOutlinePipeline::BEAT_DETAIL_PROMPT_VERSION)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BEAT_DETAIL_SCOPE)->orderBy('id')->pluck('attempt')->all())->toBe([1, 1])
        ->and($fake->requests()[0]->systemPrompt)->toContain('规划语言必须落到具体人物、动作、选择、阻力、因果和可观察变化')
        ->and($fake->requests()[0]->maxTokens)->toBe(12_000)
        ->and($fake->requests()[0]->reasoningEffort)->toBeNull()
        ->and(data_get($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->sole()->context_snapshot, 'generation_preferences.outline_routes.outline_foundation.reasoning_effort'))->toBeNull()
        ->and(data_get($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->sole()->context_snapshot, 'target_platform'))->toBe([
            'code' => 'fanqie',
            'label' => '番茄小说',
            'source' => 'default_config',
        ])
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.bible.properties.style_profile.properties.target_platform.enum'))->toBe(['fanqie'])
        ->and($fake->requests()[0]->prompt)->toContain('"target_platform":{"code":"fanqie"')
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.bible.required'))->toContain('style_profile')
        ->and(data_get($fake->requests()[1]->responseSchema, 'properties.volumes.minItems'))->toBe(1)
        ->and(data_get($fake->requests()[1]->responseSchema, 'properties.volumes.maxItems'))->toBe(1)
        ->and(data_get($fake->requests()[1]->responseSchema, 'properties.volumes.items.properties.arcs.items.properties'))->not->toHaveKey('beats')
        ->and(data_get($fake->requests()[2]->responseSchema, 'properties.beats.items.required'))->toContain('chapter_budget');
});

test('ai planning honors another valid configured default target platform', function () {
    config()->set('narrative.default_platform', 'qimao');
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $blueprint = novelBlueprint();
    data_set($blueprint, 'bible.style_profile.target_platform', 'qimao');
    $fake = stagedNovelBlueprintProvider($blueprint);
    app()->instance(AiProvider::class, $fake);

    $artifact = app(NovelPlanner::class)->generate($novel, 1);
    $batch = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->sole();

    expect(data_get($batch->context_snapshot, 'target_platform.code'))->toBe('qimao')
        ->and(data_get($batch->context_snapshot, 'target_platform.source'))->toBe('default_config')
        ->and(data_get($artifact->data, 'bible.style_profile.target_platform'))->toBe('qimao');
});

test('an existing bible target platform overrides the configured default in a new planning batch', function () {
    config()->set('narrative.default_platform', 'qimao');
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $bible = novelBlueprint()['bible'];
    data_set($bible, 'style_profile.target_platform', 'qidian');
    app(CreateBibleVersionAction::class)->execute($novel, $bible);

    $batch = app(NovelOutlinePipeline::class)->startOrResume($novel, 1);

    expect(data_get($batch->context_snapshot, 'target_platform'))->toBe([
        'code' => 'qidian',
        'label' => '起点中文网',
        'source' => 'current_bible',
    ])->and(data_get($novel->fresh()->currentBible->style_profile, 'target_platform'))->toBe('qidian');
});

test('invalid default target platform stops before creating a run or calling the provider', function () {
    config()->set('narrative.default_platform', 'unsupported-platform');
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(NovelPlanner::class)->generate($novel, 1))
        ->toThrow(ValidationException::class, 'NARRATIVE_DEFAULT_TARGET_PLATFORM');

    expect($fake->requests())->toBeEmpty()
        ->and($novel->generationRuns()->count())->toBe(0)
        ->and($novel->outlines()->count())->toBe(0);
});

test('missing dedicated outline routes stop before creating a run or calling the provider', function () {
    foreach ([
        AiStage::OutlineFoundation,
        AiStage::OutlineStructure,
        AiStage::OutlineArcBeats,
        AiStage::OutlineBeatDetail,
    ] as $stage) {
        config()->set("ai.models.{$stage->value}", null);
        config()->set("ai.stage_providers.{$stage->value}", null);
    }
    AIModelRoute::query()->create([
        'role' => AiStage::Planner,
        'provider' => 'openai',
        'model' => 'planner-must-not-be-used',
        'reasoning_effort' => 'high',
    ]);
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);

    try {
        app(NovelOutlinePipeline::class)->startOrResume($novel, 1);
        $this->fail('Expected missing dedicated Outline routes to be rejected.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('outline_route_not_configured')
            ->and($exception->retryable)->toBeFalse();
    }

    expect($fake->requests())->toHaveCount(0)
        ->and($novel->generationRuns()->count())->toBe(0);
});

test('foundation rejects a target platform different from the frozen selection', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $blueprint = novelBlueprint();
    data_set($blueprint, 'bible.style_profile.target_platform', 'qidian');
    $fake = stagedNovelBlueprintProvider($blueprint);
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(NovelPlanner::class)->generate($novel, 1))
        ->toThrow(ValidationException::class);

    expect($fake->requests())->toHaveCount(1)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->sole()->status)->toBe(RunStatus::Failed)
        ->and($novel->outlines()->count())->toBe(0);
});

test('outline workspace reports invalid default platform readiness without dispatching a job', function () {
    Queue::fake();
    config()->set('narrative.default_platform', 'unsupported-platform');
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft]);

    Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
        ->callAction('generateOutlineCandidate', ['volume_count' => 1])
        ->assertNotified('无法生成大纲候选');

    Queue::assertNotPushed(GenerateNovelOutlineJob::class);
});

test('ai planning response schema contains only strict objects accepted by the provider', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);

    app(NovelPlanner::class)->generate($novel, 1);

    $schemas = collect($fake->requests())->pluck('responseSchema');
    $invalidObjects = [];
    $missingBounds = [];
    $inspect = function (mixed $node, string $path = '$') use (&$inspect, &$invalidObjects, &$missingBounds): void {
        if (! is_array($node)) {
            return;
        }

        $type = $node['type'] ?? null;
        if ($type === 'object') {
            $properties = array_keys($node['properties'] ?? []);
            $required = $node['required'] ?? [];

            if (($node['additionalProperties'] ?? null) !== false || $properties !== $required) {
                $invalidObjects[] = $path;
            }
        }
        if ($type === 'array' && ! isset($node['maxItems'])) {
            $missingBounds[] = $path.'.maxItems';
        }
        if (($type === 'string' || (is_array($type) && array_is_list($type) && in_array('string', $type, true))) && ! isset($node['maxLength'])) {
            $missingBounds[] = $path.'.maxLength';
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $inspect($value, $path.'.'.$key);
            }
        }
    };
    $schemas->each(fn (array $schema) => $inspect($schema));

    expect($invalidObjects)->toBe([])
        ->and($missingBounds)->toBe([])
        ->and(data_get($schemas[1], 'properties.volumes.items.properties.key.pattern'))->toBe('^vol-[0-9]{2}$')
        ->and(data_get($schemas[1], 'properties.volumes.items.properties.arcs.items.properties.key.pattern'))->toBe('^arc-[0-9]{2,}$')
        ->and(data_get($schemas[1], 'properties.volumes.items.properties.arcs.items.properties'))->not->toHaveKey('beats')
        ->and(data_get($schemas[2], 'properties.beats.items.properties'))->not->toHaveKey('key')
        ->and(data_get($schemas[2], 'properties.beats.items.properties.character_candidates.items.properties'))->not->toHaveKey('candidate_key')
        ->and(data_get($schemas[3], 'properties'))->not->toHaveKey('beat_key')
        ->and(data_get($schemas[3], 'properties.milestones.items.properties'))->not->toHaveKeys(['key', 'sequence'])
        ->and(data_get($schemas[3], 'properties.handoff.properties'))->not->toHaveKey('next_beat_key');
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
        ->and(data_get($artifact->data, 'outline.volumes.0.arcs.0.beats.0.key'))->toBe('beat-01')
        ->and(data_get($artifact->data, 'outline.volumes.0.arcs.0.beats.0.sequence'))->toBe(1)
        ->and(data_get($artifact->data, 'outline.volumes.0.arcs.0.beats.1.sequence'))->toBe(2)
        ->and(data_get($artifact->data, 'outline.volumes.0.arcs.0.beats.0.milestones.0.key'))->toBe('beat-01-milestone-01');
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
    $batch = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->sole();
    expect($fake->requests())->toHaveCount(0)
        ->and($batch->status)->toBe(RunStatus::Queued)
        ->and($batch->started_at)->toBeNull();

    $pipeline = app(NovelOutlinePipeline::class);
    (new GenerateNovelOutlineJob($novel->getKey(), 1))->handle($pipeline);
    expect($batch->fresh()->status)->toBe(RunStatus::Running)
        ->and($batch->fresh()->started_at)->not->toBeNull();
    (new GenerateNovelFoundationJob($batch->getKey()))->handle($pipeline);
    (new GenerateNovelOutlineStructureJob($batch->getKey()))->handle($pipeline);
    (new GenerateNovelArcBeatsJob($batch->getKey(), 'arc-01'))->handle($pipeline);
    (new AssembleNovelOutlineSkeletonJob($batch->getKey()))->handle($pipeline);
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

test('duplicate outline starts reuse the queued batch and dispatch one coordinator job', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $action = app(StartNovelOutlineGenerationAction::class);

    $first = $action->handle($novel, 1);
    $second = $action->handle($novel, 1);

    expect($second->is($first))->toBeTrue()
        ->and($first->status)->toBe(RunStatus::Queued)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->count())->toBe(1);
    Queue::assertPushed(GenerateNovelOutlineJob::class, 1);

    app(GenerationJobDispatcher::class)->release(new GenerateNovelOutlineJob($novel->getKey(), 1));
});

test('outline start records queue dispatch failure on the prepared batch', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $dispatcher = Mockery::mock(GenerationJobDispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('forced queue dispatch failure'));
    $action = new StartNovelOutlineGenerationAction(app(NovelOutlinePipeline::class), $dispatcher);

    expect(fn () => $action->handle($novel, 1))
        ->toThrow(RuntimeException::class, 'forced queue dispatch failure');

    $batch = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->sole();
    expect($batch->status)->toBe(RunStatus::Failed)
        ->and($batch->error_code)->toBe('queue_dispatch_failed')
        ->and(data_get($batch->error_metadata, 'failed_scope'))->toBe(NovelOutlinePipeline::BATCH_SCOPE)
        ->and($batch->finished_at)->not->toBeNull();
});

test('a freshly unserialized coordinator failure still closes its prepared batch', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $batch = app(NovelOutlinePipeline::class)->prepareBatch($novel, 1);

    (new GenerateNovelOutlineJob($novel->getKey(), 1))->failed(new RuntimeException('coordinator crashed'));

    expect($batch->fresh()->status)->toBe(RunStatus::Failed)
        ->and($batch->fresh()->error_code)->toBe('outline_batch_failed')
        ->and($batch->fresh()->finished_at)->not->toBeNull();
});

test('retryable outline stage failure keeps the batch running until queue retries are exhausted', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $exception = new AiProviderException('provider_timeout', 'temporary timeout', true);
    $fake = (new FakeAiProvider)->enqueue($exception);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $job = new GenerateNovelFoundationJob($batch->getKey());

    expect(fn () => $job->handle($pipeline))->toThrow(AiProviderException::class, 'temporary timeout');
    expect($batch->fresh()->status)->toBe(RunStatus::Running);

    $job->failed($exception);
    $batch->refresh();
    $child = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->sole();
    expect($batch->status)->toBe(RunStatus::Failed)
        ->and($batch->error_code)->toBe('provider_timeout')
        ->and(data_get($batch->error_metadata, 'failed_scope'))->toBe(NovelOutlinePipeline::FOUNDATION_SCOPE)
        ->and(data_get($batch->error_metadata, 'child_run_id'))->toBe($child->getKey())
        ->and(data_get($batch->error_metadata, 'auto_retry_exhausted'))->toBeTrue()
        ->and($batch->finished_at)->not->toBeNull();
});

test('domain validation failure keeps its error meaning and closes the outline batch immediately', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $blueprint = novelBlueprint();
    data_set($blueprint, 'bible.style_profile.target_platform', 'qidian');
    $fake = stagedNovelBlueprintProvider($blueprint);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);

    (new GenerateNovelFoundationJob($batch->getKey()))->handle($pipeline);

    $child = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->sole();
    expect($child->error_code)->toBe('outline_stage_domain_validation_failed')
        ->and($child->error_code)->not->toBe('outline_stage_result_uncertain')
        ->and($batch->fresh()->status)->toBe(RunStatus::Failed)
        ->and($batch->fresh()->error_code)->toBe('outline_stage_domain_validation_failed');
});

test('truncated outline output closes the batch without repeating the same request', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $exception = new AiProviderException('outline_output_truncated', 'output was truncated', true);
    $fake = (new FakeAiProvider)->enqueue($exception);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);

    (new GenerateNovelFoundationJob($batch->getKey()))->handle($pipeline);

    expect($batch->fresh()->status)->toBe(RunStatus::Failed)
        ->and($batch->fresh()->error_code)->toBe('outline_output_truncated')
        ->and(data_get($batch->fresh()->error_metadata, 'auto_retry_exhausted'))->toBeFalse()
        ->and($fake->requests())->toHaveCount(1);
});

test('outline reasoning exhaustion keeps a distinct terminal error and category', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = (new FakeAiProvider)->enqueue(new AiResponse(
        content: '',
        structuredData: null,
        inputTokens: 2_000,
        outputTokens: 12_000,
        cachedTokens: 0,
        latencyMs: 500,
        providerRequestId: 'outline-reasoning-exhausted',
        model: 'gpt-5.6-terra',
        metadata: [
            'finish_reason' => 'length',
            'completion_limit_reason' => 'reasoning_budget_exhausted',
        ],
        reasoningTokens: 12_000,
    ));
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);

    (new GenerateNovelFoundationJob($batch->getKey()))->handle($pipeline);

    $child = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->sole();
    expect($child->error_code)->toBe('novel_outline_foundation_reasoning_budget_exhausted')
        ->and($child->error_retryable)->toBeFalse()
        ->and(data_get($child->error_metadata, 'category'))->toBe('reasoning_budget_exhausted')
        ->and($batch->fresh()->status)->toBe(RunStatus::Failed)
        ->and($batch->fresh()->error_code)->toBe('novel_outline_foundation_reasoning_budget_exhausted')
        ->and($fake->requests())->toHaveCount(1);
});

test('artifact persistence failure is the only outline result uncertainty and closes the batch', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = (new FakeAiProvider)->enqueue(stagedNovelBlueprintResponses()[0]);
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
        (new GenerateNovelFoundationJob($batch->getKey()))->handle($pipeline);
    } finally {
        $failPersistence = false;
    }

    $child = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->sole();
    expect($child->error_code)->toBe('outline_stage_result_uncertain')
        ->and(data_get($child->error_metadata, 'result_uncertain'))->toBeTrue()
        ->and($batch->fresh()->status)->toBe(RunStatus::Failed)
        ->and($batch->fresh()->error_code)->toBe('outline_stage_result_uncertain');
});

test('outline resume reuses successful artifacts and keeps the batch frozen routes', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $responses = stagedNovelBlueprintResponses();
    $fake = (new FakeAiProvider)
        ->enqueue($responses[0])
        ->enqueue($responses[1]);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $foundation = $pipeline->generateFoundation($batch);
    $frozenStructureRoute = data_get(
        $batch->context_snapshot,
        'generation_preferences.outline_routes.outline_structure',
    );
    AIModelRoute::query()->create([
        'role' => AiStage::OutlineStructure,
        'provider' => (string) config('ai.provider'),
        'model' => 'changed-after-batch',
        'reasoning_effort' => 'high',
    ]);
    $novel->update(['settings' => ['ai' => ['stages' => ['outline_structure' => [
        'provider' => (string) config('ai.provider'),
        'model' => 'changed-novel-override-after-batch',
    ]]]]]);
    $batch->update([
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
        'error_message' => 'temporary timeout',
        'error_retryable' => true,
        'error_metadata' => ['category' => 'external_temporary'],
        'finished_at' => now(),
    ]);

    $resumed = app(ResumeNovelOutlineGenerationAction::class)->handle($novel, $batch);

    expect($resumed->status)->toBe(RunStatus::Running)
        ->and($resumed->error_code)->toBeNull()
        ->and(data_get($resumed->context_snapshot, 'recovery.previous_error_code'))->toBe('provider_timeout')
        ->and(data_get($resumed->context_snapshot, 'generation_preferences.outline_routes.outline_structure'))->toBe($frozenStructureRoute)
        ->and($foundation)->not->toBeNull()
        ->and($fake->requests())->toHaveCount(1);
    Queue::assertPushed(GenerateNovelOutlineStructureJob::class, 1);

    $structure = $pipeline->generateStructure($resumed);

    expect($structure)->not->toBeNull()
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->provider)->toBe($frozenStructureRoute['provider'])
        ->and($fake->requests()[1]->model)->toBe($frozenStructureRoute['model'])
        ->and($fake->requests()[1]->reasoningEffort)->toBe($frozenStructureRoute['reasoning_effort'])
        ->and($fake->requests()[1]->model)->not->toBe('changed-after-batch')
        ->and($fake->requests()[1]->model)->not->toBe('changed-novel-override-after-batch');

    expect(fn () => app(ResumeNovelOutlineGenerationAction::class)->handle($novel, $resumed))
        ->toThrow(ValidationException::class, '只有 failed');
    Queue::assertPushed(GenerateNovelOutlineStructureJob::class, 1);
});

test('outline configuration failures cannot resume a frozen batch', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);
    $batch = app(NovelOutlinePipeline::class)->startOrResume($novel, 1);
    $frozenContext = $batch->context_snapshot;
    $batch->update([
        'status' => RunStatus::Failed,
        'error_code' => 'outline_route_not_configured',
        'error_message' => 'outline route is missing',
        'error_retryable' => false,
        'error_metadata' => ['category' => 'provider_configuration'],
        'finished_at' => now(),
    ]);

    expect(fn () => app(ResumeNovelOutlineGenerationAction::class)->handle($novel, $batch))
        ->toThrow(ValidationException::class, '不能继续冻结批次');

    expect($batch->fresh()->status)->toBe(RunStatus::Failed)
        ->and($batch->fresh()->context_snapshot)->toBe($frozenContext)
        ->and($fake->requests())->toHaveCount(0);
    Queue::assertNothingPushed();
});

test('outline restart creates a new batch from current routes without rewriting the failed v4 batch', function () {
    Queue::fake();
    config()->set('ai.providers.openai.api_key', 'test-key');
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $pipeline = app(NovelOutlinePipeline::class);
    $oldBatch = $pipeline->startOrResume($novel, 1);
    $oldContext = $oldBatch->context_snapshot;
    $oldInputHash = $oldBatch->input_hash;
    $oldFoundationRoute = data_get($oldContext, 'generation_preferences.outline_routes.outline_foundation');
    $oldBatch->update([
        'status' => RunStatus::Failed,
        'error_code' => 'provider_run_route_missing',
        'error_message' => 'frozen route is incomplete',
        'error_retryable' => false,
        'error_metadata' => ['category' => 'provider_configuration'],
        'finished_at' => now(),
    ]);

    AIModelPrice::query()->create([
        'provider' => 'openai',
        'model' => 'outline-foundation-restart-model',
        'currency' => 'USD',
        'billing_unit' => 1_000_000,
        'context_window_tokens' => 500_000,
        'max_output_tokens' => 40_000,
        'supports_structured_output' => true,
        'supports_reasoning_effort' => true,
        'input_price' => 1,
        'output_price' => 2,
        'is_enabled' => true,
    ]);
    AIModelRoute::query()->updateOrCreate([
        'role' => AiStage::OutlineFoundation,
    ], [
        'provider' => 'openai',
        'model' => 'outline-foundation-restart-model',
        'reasoning_effort' => 'high',
    ]);

    $newBatch = app(StartNovelOutlineGenerationAction::class)->handle($novel, 1);

    expect($newBatch->getKey())->not->toBe($oldBatch->getKey())
        ->and($newBatch->status)->toBe(RunStatus::Queued)
        ->and($newBatch->attempt)->toBe(2)
        ->and($newBatch->prompt_version)->toBe(NovelOutlinePipeline::BATCH_PROMPT_VERSION)
        ->and($newBatch->input_hash)->not->toBe($oldInputHash)
        ->and(data_get($newBatch->context_snapshot, 'generation_preferences.outline_routes.outline_foundation.model'))
        ->toBe('outline-foundation-restart-model')
        ->and(data_get($newBatch->context_snapshot, 'generation_preferences.outline_routes.outline_foundation.reasoning_effort'))
        ->toBe('high');

    $unchangedOldBatch = $oldBatch->fresh();
    expect($unchangedOldBatch->status)->toBe(RunStatus::Failed)
        ->and($unchangedOldBatch->prompt_version)->toBe(NovelOutlinePipeline::BATCH_PROMPT_VERSION)
        ->and($unchangedOldBatch->input_hash)->toBe($oldInputHash)
        ->and($unchangedOldBatch->context_snapshot)->toBe($oldContext)
        ->and(data_get($unchangedOldBatch->context_snapshot, 'generation_preferences.outline_routes.outline_foundation'))
        ->toBe($oldFoundationRoute)
        ->and($unchangedOldBatch->error_code)->toBe('provider_run_route_missing');
    Queue::assertPushed(GenerateNovelOutlineJob::class, 1);
});

test('outline resume rejects paused cancelled succeeded and incompatible batches', function () {
    $pipeline = app(NovelOutlinePipeline::class);

    $pausedNovel = Novel::factory()->create(['status' => NovelStatus::Draft]);
    $pausedBatch = $pipeline->startOrResume($pausedNovel, 1);
    $pausedBatch->update(['status' => RunStatus::Failed, 'finished_at' => now()]);
    $pausedNovel->update(['status' => NovelStatus::Paused]);
    expect(fn () => app(ResumeNovelOutlineGenerationAction::class)->handle($pausedNovel, $pausedBatch))
        ->toThrow(AiProviderException::class, '小说已暂停');

    $cancelledNovel = Novel::factory()->create(['status' => NovelStatus::Draft]);
    $cancelledBatch = $pipeline->startOrResume($cancelledNovel, 1);
    $cancelledBatch->update(['status' => RunStatus::Cancelled, 'finished_at' => now()]);
    expect(fn () => app(ResumeNovelOutlineGenerationAction::class)->handle($cancelledNovel, $cancelledBatch))
        ->toThrow(ValidationException::class, '只有 failed');

    $succeededNovel = Novel::factory()->create(['status' => NovelStatus::Draft]);
    $succeededBatch = $pipeline->startOrResume($succeededNovel, 1);
    $succeededBatch->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);
    expect(fn () => app(ResumeNovelOutlineGenerationAction::class)->handle($succeededNovel, $succeededBatch))
        ->toThrow(ValidationException::class, '只有 failed');

    $legacyNovel = Novel::factory()->create(['status' => NovelStatus::Draft]);
    $legacyBatch = $pipeline->startOrResume($legacyNovel, 1);
    $legacyBatch->update([
        'status' => RunStatus::Failed,
        'prompt_version' => 'novel-outline-pipeline-v1',
        'finished_at' => now(),
    ]);
    expect(fn () => app(ResumeNovelOutlineGenerationAction::class)->handle($legacyNovel, $legacyBatch))
        ->toThrow(ValidationException::class, '版本');
});

test('delayed outline failure callback cannot overwrite a newer successful stage', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $exception = new AiProviderException('provider_timeout', 'temporary timeout', true);
    $fake = (new FakeAiProvider)
        ->enqueue($exception)
        ->enqueue(stagedNovelBlueprintResponses()[0]);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $oldJob = new GenerateNovelFoundationJob($batch->getKey());

    expect(fn () => $oldJob->handle($pipeline))->toThrow(AiProviderException::class);
    expect($pipeline->generateFoundation($batch))->not->toBeNull();

    $oldJob->failed($exception);

    expect($batch->fresh()->status)->toBe(RunStatus::Running)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->where('status', RunStatus::Succeeded)->count())->toBe(1);
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
        ->and($fake->requests()[5]->prompt)->not->toContain('"volumes"')
        ->and(data_get($fake->requests()[5]->responseSchema, 'required'))->toBe(['node'])
        ->and(data_get($fake->requests()[5]->responseSchema, 'properties.node.properties.character_candidates.items.properties'))->not->toHaveKey('possible_duplicate_character_ids')
        ->and($novel->generationRuns()->count())->toBe(9)
        ->and($novel->generationRuns()->withCount('artifacts')->get()->sum('artifacts_count'))->toBe(8);
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

test('outline stages freeze each task route and bind every child run to the batch', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.deepseek.enabled', true);
    config()->set('ai.providers.deepseek.api_key', 'deepseek-test-key');
    $routeProfiles = [
        AiStage::OutlineFoundation->value => ['provider' => 'openai', 'model' => 'outline-foundation-model', 'reasoning' => 'low', 'context' => 500_000, 'output' => 40_000],
        AiStage::OutlineStructure->value => ['provider' => 'deepseek', 'model' => 'outline-structure-model', 'reasoning' => 'medium', 'context' => 600_000, 'output' => 50_000],
        AiStage::OutlineArcBeats->value => ['provider' => 'openai', 'model' => 'outline-arc-beats-model', 'reasoning' => 'high', 'context' => 700_000, 'output' => 60_000],
        AiStage::OutlineBeatDetail->value => ['provider' => 'deepseek', 'model' => 'outline-beat-detail-model', 'reasoning' => null, 'context' => 800_000, 'output' => 70_000],
    ];
    foreach ($routeProfiles as $stage => $profile) {
        AIModelPrice::query()->create([
            'provider' => $profile['provider'],
            'model' => $profile['model'],
            'currency' => 'USD',
            'billing_unit' => 1_000_000,
            'context_window_tokens' => $profile['context'],
            'max_output_tokens' => $profile['output'],
            'supports_structured_output' => true,
            'supports_reasoning_effort' => true,
            'input_price' => 1,
            'output_price' => 2,
            'is_enabled' => true,
        ]);
        AIModelRoute::query()->create([
            'role' => $stage,
            'provider' => $profile['provider'],
            'model' => $profile['model'],
            'reasoning_effort' => $profile['reasoning'],
        ]);
    }
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);

    AIModelRoute::query()->update(['model' => 'changed-after-batch']);
    expect($pipeline->startOrResume($novel, 1)->is($batch))->toBeTrue();
    $pipeline->generateFoundation($batch);
    $pipeline->generateStructure($batch);
    $pipeline->generateArcBeats($batch, 'arc-01');
    $requestCountBeforeAssembly = count($fake->requests());
    $skeleton = $pipeline->assembleSkeleton($batch);
    foreach (['beat-01', 'beat-02'] as $beatKey) {
        $pipeline->generateBeatDetail($batch, $beatKey);
    }
    $requestCountBeforeFinalize = count($fake->requests());
    $pipeline->finalize($batch);

    $requestStages = [
        AiStage::OutlineFoundation->value,
        AiStage::OutlineStructure->value,
        AiStage::OutlineArcBeats->value,
        AiStage::OutlineBeatDetail->value,
        AiStage::OutlineBeatDetail->value,
    ];
    expect($batch->provider)->toBeNull()
        ->and($batch->model_policy)->toBeNull()
        ->and($fake->requests())->toHaveCount(5);
    foreach ($routeProfiles as $stage => $profile) {
        $frozen = data_get($batch->context_snapshot, "generation_preferences.outline_routes.{$stage}");
        $configuredBudget = config("generation.outline_request_budgets.{$stage}");
        expect($frozen['model'])->toBe($profile['model'])
            ->and($frozen['request_budget'])->toBe([
                'output_tokens' => $configuredBudget['output_tokens'],
                'reasoning_reserve_tokens' => $configuredBudget['reasoning_reserve_tokens'],
                'max_completion_tokens' => $configuredBudget['output_tokens'] + $configuredBudget['reasoning_reserve_tokens'],
            ])
            ->and($frozen['model_capacity']['model_price_id'])->toBeInt()
            ->and($frozen['model_capacity']['context_window_tokens'])->toBe($profile['context'])
            ->and($frozen['model_capacity']['max_output_tokens'])->toBe($profile['output'])
            ->and($frozen['model_capacity']['supports_structured_output'])->toBeTrue()
            ->and($frozen['model_capacity']['supports_reasoning_effort'])->toBeTrue();
    }
    foreach ($fake->requests() as $index => $request) {
        $stage = $requestStages[$index];
        $configuredBudget = config("generation.outline_request_budgets.{$stage}");
        $childRun = $novel->generationRuns()->findOrFail($request->metadata['generation_run_id']);
        expect($request->provider)->toBe($routeProfiles[$stage]['provider'])
            ->and($request->model)->toBe($routeProfiles[$stage]['model'])
            ->and($request->reasoningEffort)->toBe($routeProfiles[$stage]['reasoning'])
            ->and($request->maxTokens)->toBe($configuredBudget['output_tokens'] + $configuredBudget['reasoning_reserve_tokens'])
            ->and($request->metadata['stage'])->toBe($stage)
            ->and($request->metadata['route_key'])->toBe($stage)
            ->and(data_get($childRun->context_snapshot, 'request_budget.max_completion_tokens'))->toBe($request->maxTokens)
            ->and(data_get($childRun->context_snapshot, 'input.capacity_snapshot.max_completion_tokens'))->toBe($request->maxTokens);
    }
    expect($novel->generationRuns()
        ->where('id', '!=', $batch->getKey())
        ->whereNotIn('scope_type', [NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE, NovelOutlinePipeline::FINALIZE_SCOPE])
        ->get())->each(fn ($run) => $run->scope_id->toBe($batch->getKey()));
    $assemblyRun = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE)->sole();
    $finalizeRun = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FINALIZE_SCOPE)->sole();
    expect($skeleton)->not->toBeNull()
        ->and($requestCountBeforeAssembly)->toBe(3)
        ->and($requestCountBeforeFinalize)->toBe(5)
        ->and($fake->requests())->toHaveCount($requestCountBeforeFinalize)
        ->and($assemblyRun->provider)->toBeNull()
        ->and($assemblyRun->model_policy)->toBeNull()
        ->and($assemblyRun->usageRecords()->count())->toBe(0)
        ->and($finalizeRun->provider)->toBeNull()
        ->and($finalizeRun->model_policy)->toBeNull()
        ->and($finalizeRun->usageRecords()->count())->toBe(0)
        ->and($novel->outlines()->count())->toBe(1);
});

test('v3 outline batches keep their original single frozen route after the v4 upgrade', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $route = data_get($batch->context_snapshot, 'generation_preferences.outline_routes.outline_foundation');
    $context = $batch->context_snapshot;
    data_forget($context, 'generation_preferences.outline_routes');
    data_set($context, 'generation_preferences.provider', $route['provider']);
    data_set($context, 'generation_preferences.model', $route['model']);
    data_set($context, 'generation_preferences.reasoning_effort', $route['reasoning_effort']);
    data_set($context, 'generation_preferences.model_capacity', $route['model_capacity']);
    $batch->update([
        'prompt_version' => NovelOutlinePipeline::SINGLE_ROUTE_BATCH_PROMPT_VERSION,
        'provider' => $route['provider'],
        'model_policy' => $route['model'],
        'context_snapshot' => $context,
    ]);

    config()->set('ai.models.planner', 'changed-after-v3-batch');
    $pipeline->assertResumeCompatible($batch->refresh());
    $pipeline->generateFoundation($batch);

    expect($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->provider)->toBe($route['provider'])
        ->and($fake->requests()[0]->model)->toBe($route['model']);
});

test('a dedicated outline route without verified matching capacity is rejected before batch creation', function () {
    config()->set('ai.providers.openai.api_key', 'test-key');
    AIModelRoute::query()->create([
        'role' => AiStage::OutlineStructure,
        'provider' => 'openai',
        'model' => 'unverified-structure-model',
        'reasoning_effort' => 'medium',
    ]);
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);

    expect(fn () => app(NovelOutlinePipeline::class)->prepareBatch($novel, 1))
        ->toThrow(ValidationException::class, 'outline_structure');
    expect($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->count())->toBe(0);
});

test('outline request budgets are frozen into the batch hash', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $pipeline = app(NovelOutlinePipeline::class);
    $first = $pipeline->prepareBatch($novel, 1);
    $first->update(['status' => RunStatus::Cancelled]);

    config()->set('generation.outline_request_budgets.outline_foundation.output_tokens', 9_000);
    $second = $pipeline->prepareBatch($novel, 1);

    expect($second->input_hash)->not->toBe($first->input_hash)
        ->and(data_get($first->context_snapshot, 'generation_preferences.outline_routes.outline_foundation.request_budget.max_completion_tokens'))->toBe(12_000)
        ->and(data_get($second->context_snapshot, 'generation_preferences.outline_routes.outline_foundation.request_budget.max_completion_tokens'))->toBe(13_000);
});

test('outline request budget exceeding model capacity is rejected before batch creation', function () {
    config()->set('generation.outline_request_budgets.outline_foundation.output_tokens', 128_000);
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(NovelOutlinePipeline::class)->prepareBatch($novel, 1))
        ->toThrow(ValidationException::class, '超过模型静态容量');
    expect($novel->generationRuns()->count())->toBe(0)
        ->and($fake->requests())->toHaveCount(0);
});

test('outline stage input hash includes its frozen request budget', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $foundationResponse = stagedNovelBlueprintResponses()[0];
    $fake = (new FakeAiProvider)->enqueue($foundationResponse)->enqueue($foundationResponse);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $pipeline->generateFoundation($batch);

    $context = $batch->context_snapshot;
    data_set($context, 'generation_preferences.outline_routes.outline_foundation.request_budget.output_tokens', 8_001);
    data_set($context, 'generation_preferences.outline_routes.outline_foundation.request_budget.max_completion_tokens', 12_001);
    $batch->update(['context_snapshot' => $context]);
    $pipeline->generateFoundation($batch->refresh());

    $runs = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->orderBy('id')->get();
    expect($runs)->toHaveCount(2)
        ->and($runs[1]->input_hash)->not->toBe($runs[0]->input_hash)
        ->and($fake->requests()[0]->maxTokens)->toBe(12_000)
        ->and($fake->requests()[1]->maxTokens)->toBe(12_001);
});

test('all four outline stages reject insufficient frozen capacity before creating a child run', function (AiStage $stage, string $scopeType) {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);

    if ($stage !== AiStage::OutlineFoundation) {
        $pipeline->generateFoundation($batch);
    }
    if (in_array($stage, [AiStage::OutlineArcBeats, AiStage::OutlineBeatDetail], true)) {
        $pipeline->generateStructure($batch);
    }
    if ($stage === AiStage::OutlineBeatDetail) {
        $pipeline->generateArcBeats($batch, 'arc-01');
        $pipeline->assembleSkeleton($batch);
    }

    $requestCount = count($fake->requests());
    $context = $batch->context_snapshot;
    data_set($context, "generation_preferences.outline_routes.{$stage->value}.model_capacity.max_output_tokens", 1);
    $batch->update(['context_snapshot' => $context]);
    $batch->refresh();

    $invoke = match ($stage) {
        AiStage::OutlineFoundation => fn () => $pipeline->generateFoundation($batch),
        AiStage::OutlineStructure => fn () => $pipeline->generateStructure($batch),
        AiStage::OutlineArcBeats => fn () => $pipeline->generateArcBeats($batch, 'arc-01'),
        AiStage::OutlineBeatDetail => fn () => $pipeline->generateBeatDetail($batch, 'beat-01'),
        default => throw new RuntimeException('Unexpected Outline stage.'),
    };

    expect($invoke)->toThrow(ValidationException::class, '请求完成预算');
    expect($novel->generationRuns()->where('scope_type', $scopeType)->count())->toBe(0)
        ->and($fake->requests())->toHaveCount($requestCount);
})->with([
    'foundation' => [AiStage::OutlineFoundation, NovelOutlinePipeline::FOUNDATION_SCOPE],
    'structure' => [AiStage::OutlineStructure, NovelOutlinePipeline::STRUCTURE_SCOPE],
    'arc beats' => [AiStage::OutlineArcBeats, NovelOutlinePipeline::ARC_BEATS_SCOPE],
    'beat detail' => [AiStage::OutlineBeatDetail, NovelOutlinePipeline::BEAT_DETAIL_SCOPE],
]);

test('a failure while dispatching the next outline stage closes the batch instead of leaving it running', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $pipeline->generateFoundation($batch);
    $pipeline->generateStructure($batch);

    $context = $batch->context_snapshot;
    data_forget($context, 'generation_preferences.outline_routes.outline_arc_beats');
    $batch->update(['context_snapshot' => $context]);

    expect(fn () => $pipeline->dispatchNext($batch->refresh()))
        ->toThrow(AiProviderException::class, '完整冻结路由');

    $batch->refresh();
    expect($batch->status)->toBe(RunStatus::Failed)
        ->and($batch->error_code)->toBe('provider_run_route_missing')
        ->and(data_get($batch->error_metadata, 'failed_scope'))->toBe(NovelOutlinePipeline::BATCH_SCOPE)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::ARC_BEATS_SCOPE)->count())->toBe(0)
        ->and($fake->requests())->toHaveCount(2);
});

test('a frozen prompt version mismatch is reported as a stale outline worker before any provider request', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);

    $context = $batch->context_snapshot;
    data_set($context, 'generation_preferences.outline_routes.outline_foundation.prompt_version', 'novel-outline-foundation-newer-than-worker');
    $batch->update(['context_snapshot' => $context]);

    expect(fn () => $pipeline->generateFoundation($batch->refresh()))
        ->toThrow(AiProviderException::class, '请重启 Horizon 后继续该批次');

    expect($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::FOUNDATION_SCOPE)->count())->toBe(0)
        ->and($fake->requests())->toHaveCount(0);
});

test('arc two retries locally while arc one and its upstream artifacts are reused', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $responses = stagedNovelBlueprintResponses(novelBlueprintWithTwoArcs());
    $fake = (new FakeAiProvider)
        ->enqueue($responses[0])
        ->enqueue($responses[1])
        ->enqueue($responses[2])
        ->enqueue(new AiProviderException('provider_timeout', 'arc two timeout', true))
        ->enqueue($responses[3]);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $foundation = $pipeline->generateFoundation($batch);
    $structure = $pipeline->generateStructure($batch);
    $firstArc = $pipeline->generateArcBeats($batch, 'arc-01');

    expect(fn () => $pipeline->generateArcBeats($batch, 'arc-02'))
        ->toThrow(AiProviderException::class, 'arc two timeout');
    $secondArc = $pipeline->generateArcBeats($batch, 'arc-02');

    expect($foundation?->fresh()->checksum)->toBe($foundation?->checksum)
        ->and($structure?->fresh()->checksum)->toBe($structure?->checksum)
        ->and($firstArc?->fresh()->checksum)->toBe($firstArc?->checksum)
        ->and($secondArc)->not->toBeNull()
        ->and($fake->requests())->toHaveCount(5)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::ARC_BEATS_SCOPE)->where('context_snapshot->discriminator', 'arc-01')->count())->toBe(1)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::ARC_BEATS_SCOPE)->where('context_snapshot->discriminator', 'arc-01')->sole()->attempt)->toBe(1)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::ARC_BEATS_SCOPE)->where('context_snapshot->discriminator', 'arc-02')->count())->toBe(2)
        ->and($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::ARC_BEATS_SCOPE)->where('context_snapshot->discriminator', 'arc-02')->orderBy('id')->pluck('attempt')->all())->toBe([1, 2]);
});

test('an arc response for the wrong key cannot create a skeleton', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $responses = stagedNovelBlueprintResponses();
    $wrongArc = $responses[2]->structuredData;
    $wrongArc['arc_key'] = 'arc-99';
    $fake = (new FakeAiProvider)
        ->enqueue($responses[0])
        ->enqueue($responses[1])
        ->enqueue(outlineStageResponse($wrongArc, 'wrong-arc'));
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $pipeline->generateFoundation($batch);
    $pipeline->generateStructure($batch);

    expect(fn () => $pipeline->generateArcBeats($batch, 'arc-01'))
        ->toThrow(ValidationException::class, 'arc-01');
    expect(GenerationArtifact::query()->whereIn('type', [ArtifactType::OutlineArcBeats, ArtifactType::OutlineSkeleton])->whereHas('generationRun', fn ($query) => $query->where('novel_id', $novel->getKey()))->count())->toBe(0);
});

test('skeleton assembly requires every arc and is deterministic without a provider request or usage', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $blueprint = novelBlueprintWithTwoArcs();
    $candidate = [
        'candidate_key' => 'provider-repeated-key',
        'name' => '巡墙人',
        'deduplication_basis' => 'Foundation 中无同名角色',
        'introduction_reason' => '承担环墙线索',
        'target_scene_sequence' => 1,
        'role' => '配角',
        'motivation' => '守住环墙',
        'profile' => ['巡墙人'],
        'personality' => ['谨慎'],
        'abilities' => ['熟悉城墙'],
        'knowledge' => ['知道暗门'],
    ];
    $blueprint['outline']['volumes'][0]['arcs'][0]['beats'][0]['character_candidates'] = [$candidate];
    $blueprint['outline']['volumes'][0]['arcs'][1]['beats'][0]['character_candidates'] = [$candidate];
    $responses = stagedNovelBlueprintResponses($blueprint);
    $fake = (new FakeAiProvider)
        ->enqueue($responses[0])
        ->enqueue($responses[1])
        ->enqueue($responses[2])
        ->enqueue($responses[3]);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $pipeline->generateFoundation($batch);
    $pipeline->generateStructure($batch);
    $pipeline->generateArcBeats($batch, 'arc-01');

    expect(fn () => $pipeline->assembleSkeleton($batch))
        ->toThrow(ValidationException::class, 'arc-02');
    expect(GenerationArtifact::query()->where('type', ArtifactType::OutlineSkeleton)->whereHas('generationRun', fn ($query) => $query->where('novel_id', $novel->getKey()))->count())->toBe(0);

    $pipeline->generateArcBeats($batch, 'arc-02');
    $requestCount = count($fake->requests());
    $first = $pipeline->assembleSkeleton($batch);
    $second = $pipeline->assembleSkeleton($batch);
    $assemblyRun = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE)->sole();

    expect($second?->is($first))->toBeTrue()
        ->and($fake->requests())->toHaveCount($requestCount)
        ->and($assemblyRun->usageRecords()->count())->toBe(0)
        ->and($assemblyRun->provider)->toBeNull()
        ->and($assemblyRun->artifacts()->where('type', ArtifactType::OutlineSkeleton)->count())->toBe(1)
        ->and(data_get($first?->data, 'payload.volumes.0.arcs.0.mainline_sequence'))->toBe(1)
        ->and(data_get($first?->data, 'payload.volumes.0.arcs.1.mainline_sequence'))->toBeNull()
        ->and(data_get($first?->data, 'payload.volumes.0.arcs.0.beats.1.mainline_sequence'))->toBe(2)
        ->and(collect(data_get($first?->data, 'payload.volumes'))->flatMap(
            fn (array $volume) => collect($volume['arcs'])->flatMap(fn (array $arc): array => $arc['beats'])
        )->pluck('key')->all())->toBe(['beat-01', 'beat-02', 'beat-03'])
        ->and(data_get($first?->data, 'payload.volumes.0.arcs.0.beats.0.character_candidates.0.candidate_key'))->toBe('beat-01-character-01')
        ->and(data_get($first?->data, 'payload.volumes.0.arcs.1.beats.0.character_candidates.0.candidate_key'))->toBe('beat-03-character-01');
});

test('legacy skeleton job cannot participate in a current outline batch', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = (new FakeAiProvider)->enqueue(stagedNovelBlueprintResponses()[0]);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $pipeline->generateFoundation($batch);

    (new GenerateNovelOutlineSkeletonJob($batch->getKey()))->handle($pipeline);

    expect($batch->fresh()->status)->toBe(RunStatus::Failed)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::OutlineSkeleton)->whereHas('generationRun', fn ($query) => $query->where('novel_id', $novel->getKey()))->count())->toBe(0)
        ->and($fake->requests())->toHaveCount(1);
    Queue::assertNotPushed(GenerateNovelOutlineSkeletonJob::class);
});

test('a deserialized legacy skeleton job can finish only a legacy batch', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $responses = stagedNovelBlueprintResponses();
    $legacySkeleton = novelBlueprint()['outline'];
    foreach ($legacySkeleton['volumes'] as &$volume) {
        foreach ($volume['arcs'] as &$arc) {
            foreach ($arc['beats'] as &$beat) {
                unset($beat['milestones'], $beat['handoff']);
            }
            unset($beat);
        }
        unset($arc);
    }
    unset($volume);
    $fake = (new FakeAiProvider)
        ->enqueue($responses[0])
        ->enqueue(outlineStageResponse($legacySkeleton, 'legacy-skeleton'));
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $foundationRoute = data_get($batch->context_snapshot, 'generation_preferences.outline_routes.outline_foundation');
    $legacyContext = $batch->context_snapshot;
    data_set($legacyContext, 'generation_preferences.reasoning_effort', $foundationRoute['reasoning_effort']);
    data_set($legacyContext, 'generation_preferences.model_capacity', $foundationRoute['model_capacity']);
    $batch->update([
        'prompt_version' => NovelOutlinePipeline::LEGACY_BATCH_PROMPT_VERSION,
        'provider' => $foundationRoute['provider'],
        'model_policy' => $foundationRoute['model'],
        'context_snapshot' => $legacyContext,
    ]);
    $pipeline->generateFoundation($batch);

    (new GenerateNovelOutlineSkeletonJob($batch->getKey()))->handle($pipeline);

    $legacyRun = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::SKELETON_SCOPE)->sole();
    expect($legacyRun->status)->toBe(RunStatus::Succeeded)
        ->and($legacyRun->artifacts()->where('type', ArtifactType::OutlineSkeleton)->count())->toBe(1)
        ->and($fake->requests())->toHaveCount(2);
    Queue::assertPushed(GenerateNovelBeatDetailJob::class, fn (GenerateNovelBeatDetailJob $job): bool => $job->beatKey === 'beat-01');
    Queue::assertNotPushed(GenerateNovelOutlineStructureJob::class);
});

test('a failed beat detail resumes only that beat and reuses successful stage artifacts', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $responses = stagedNovelBlueprintResponses();
    $fake = (new FakeAiProvider)
        ->enqueue($responses[0])
        ->enqueue($responses[1])
        ->enqueue($responses[2])
        ->enqueue($responses[3])
        ->enqueue(new AiProviderException('provider_timeout', 'temporary timeout', true))
        ->enqueue($responses[4]);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $foundation = $pipeline->generateFoundation($batch);
    $pipeline->generateStructure($batch);
    $pipeline->generateArcBeats($batch, 'arc-01');
    $skeleton = $pipeline->assembleSkeleton($batch);
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
        ->and($fake->requests())->toHaveCount(6)
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

    expect(fn () => $pipeline->generateStructure($batch))->toThrow(AiProviderException::class, '小说已暂停');
    expect($fake->requests())->toHaveCount(1);

    $novel->update(['status' => NovelStatus::Planning]);
    $pipeline->generateStructure($batch);
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
    $fake = (new FakeAiProvider)->enqueue($responses[0])->enqueue($responses[1])->enqueue($responses[2])->enqueue($responses[3]);
    app()->instance(AiProvider::class, $fake);
    $pipeline = app(NovelOutlinePipeline::class);
    $batch = $pipeline->startOrResume($novel, 1);
    $pipeline->generateFoundation($batch);
    $pipeline->generateStructure($batch);
    $pipeline->generateArcBeats($batch, 'arc-01');
    $pipeline->assembleSkeleton($batch);
    $pipeline->generateBeatDetail($batch, 'beat-01');

    expect(fn () => $pipeline->finalize($batch))->toThrow(ValidationException::class, '缺少 Detail Artifact');
    expect($novel->outlines()->count())->toBe(0)
        ->and($novel->volumes()->count())->toBe(0)
        ->and($novel->bibles()->count())->toBe(0);
});

test('apply rejects a new blueprint missing its structure source before any formal initialization write', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    $fake = stagedNovelBlueprintProvider();
    app()->instance(AiProvider::class, $fake);
    $artifact = app(NovelPlanner::class)->generate($novel, 1);
    $data = $artifact->data;
    unset($data['lineage']['structure']);
    DB::table('generation_artifacts')->where('id', $artifact->getKey())->update([
        'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
    ]);
    $outline = $novel->outlines()->sole();

    expect(fn () => app(ApplyNovelBlueprintAction::class)->handle($novel, $outline))
        ->toThrow(ValidationException::class, 'Structure');
    expect($novel->fresh()->current_outline_id)->toBeNull()
        ->and($novel->bibles()->count())->toBe(0)
        ->and($novel->characters()->count())->toBe(0)
        ->and($novel->worldEntities()->count())->toBe(0)
        ->and($novel->volumes()->count())->toBe(0);
});

test('apply rejects a new blueprint with a wrong source type or checksum', function (string $mutation) {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    app()->instance(AiProvider::class, stagedNovelBlueprintProvider());
    $artifact = app(NovelPlanner::class)->generate($novel, 1);
    $data = $artifact->data;
    if ($mutation === 'type') {
        $data['lineage']['structure']['type'] = ArtifactType::OutlineSkeleton->value;
    } else {
        $data['lineage']['structure']['checksum'] = str_repeat('0', 64);
    }
    DB::table('generation_artifacts')->where('id', $artifact->getKey())->update([
        'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
    ]);

    expect(fn () => app(ApplyNovelBlueprintAction::class)->handle($novel, $novel->outlines()->sole()))
        ->toThrow(ValidationException::class);
    expect($novel->fresh()->current_outline_id)->toBeNull()
        ->and($novel->bibles()->count())->toBe(0);
})->with(['type', 'checksum']);

test('apply rejects a structure source from another novel and batch', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    app()->instance(AiProvider::class, stagedNovelBlueprintProvider());
    $artifact = app(NovelPlanner::class)->generate($novel, 1);

    $otherNovel = Novel::factory()->create(['status' => NovelStatus::Draft, 'target_words' => 200000]);
    app()->instance(AiProvider::class, stagedNovelBlueprintProvider());
    $otherArtifact = app(NovelPlanner::class)->generate($otherNovel, 1);
    $data = $artifact->data;
    $data['lineage']['structure'] = data_get($otherArtifact->data, 'lineage.structure');
    DB::table('generation_artifacts')->where('id', $artifact->getKey())->update([
        'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
    ]);

    expect(fn () => app(ApplyNovelBlueprintAction::class)->handle($novel, $novel->outlines()->sole()))
        ->toThrow(ValidationException::class, '其他小说、其他批次');
    expect($novel->fresh()->current_outline_id)->toBeNull()
        ->and($novel->bibles()->count())->toBe(0);
});
