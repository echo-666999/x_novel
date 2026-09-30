<?php

use App\AI\OpenAiStructuredOutputSchema;
use App\Enums\ArtifactType;
use App\Services\NovelOutlinePipeline;
use App\Services\NovelOutlineStageContract;
use Illuminate\Validation\ValidationException;

function ogrStructurePayload(): array
{
    return [
        'title' => '星火长夜',
        'summary' => '主角从边城出发并揭开失落文明真相。',
        'must_include' => ['文明遗迹'],
        'must_not_include' => ['无代价复活'],
        'volumes' => [
            [
                'key' => 'vol-01',
                'title' => '离城',
                'goal' => '建立主角目标',
                'climax' => '突破封锁',
                'target_words' => 100_000,
                'arcs' => [[
                    'key' => 'arc-01',
                    'type' => 'main',
                    'title' => '逃离边城',
                    'goal' => '离开边城',
                    'stakes' => '失败将永久失去自由',
                    'completion_conditions' => ['越过城墙'],
                ]],
            ],
            [
                'key' => 'vol-02',
                'title' => '远航',
                'goal' => '寻找文明遗迹',
                'climax' => '开启遗迹',
                'target_words' => 120_000,
                'arcs' => [[
                    'key' => 'arc-02',
                    'type' => 'subplot',
                    'title' => '旧盟约',
                    'goal' => '修复盟友关系',
                    'stakes' => '失去进入遗迹的线索',
                    'completion_conditions' => ['盟约重建'],
                ]],
            ],
        ],
    ];
}

function ogrArcBeatsPayload(): array
{
    return [
        'arc_key' => 'arc-01',
        'beats' => [
            [
                'key' => 'beat-01',
                'title' => '封锁',
                'summary' => '主角发现城门已经关闭。',
                'chapter_budget' => ['min' => 2, 'max' => 4],
                'acceptance_criteria' => ['确认封锁原因'],
                'must_include' => ['城门'],
                'must_not_include' => [],
                'character_candidates' => [[
                    'candidate_key' => 'guard-captain',
                    'name' => '卫队长',
                    'deduplication_basis' => 'Foundation 中无同名角色',
                    'introduction_reason' => '代表封锁力量',
                    'target_scene_sequence' => 1,
                    'role' => '配角',
                    'motivation' => '守住城门',
                    'profile' => ['谨慎'],
                    'personality' => ['固执'],
                    'abilities' => ['调动卫队'],
                    'knowledge' => ['封锁命令来源'],
                ]],
                'world_entity_candidates' => [[
                    'candidate_key' => 'north-gate',
                    'name' => '北城门',
                    'deduplication_basis' => 'Foundation 中无同名地点',
                    'introduction_reason' => '逃离路线',
                    'target_scene_sequence' => 1,
                    'type' => 'location',
                    'description' => '边城唯一仍可通行的城门。',
                ]],
            ],
            [
                'key' => 'beat-02',
                'title' => '越墙',
                'summary' => '主角找到旧水道并离城。',
                'chapter_budget' => ['min' => 2, 'max' => null],
                'acceptance_criteria' => ['主角离开边城'],
                'must_include' => ['旧水道'],
                'must_not_include' => ['瞬移'],
                'character_candidates' => [],
                'world_entity_candidates' => [],
            ],
        ],
    ];
}

function ogrFoundationPayload(): array
{
    return [
        'bible' => [
            'logline' => '边城少年寻找失落文明。',
            'themes' => ['自由'],
            'tone' => '克制',
            'pov' => '第三人称限知',
            'tense' => '过去时',
            'taboos' => [],
            'hard_constraints' => ['死亡不可逆'],
            'ending_contract' => ['main_conflict_resolution' => '文明真相公开'],
            'style_profile' => ['primary_style' => '冷峻'],
        ],
        'characters' => [[
            'name' => '林野',
            'role' => '主角',
            'motivation' => '离开边城',
            'profile' => ['少年'],
            'current_state' => ['location' => '边城', 'summary' => '准备出发'],
        ]],
        'world_entities' => [[
            'type' => 'location',
            'name' => '边城',
            'description' => '封闭城邦',
            'rules' => ['夜间宵禁'],
            'current_state' => ['封锁'],
        ]],
        'foreshadowings' => [[
            'title' => '旧地图',
            'description' => '地图指向遗迹',
            'promised_payoff' => '找到遗迹',
            'importance' => 'high',
            'owner_arc_key' => 'arc-02',
        ]],
    ];
}

test('outline structure and arc beats schemas are strict and exclude provider-owned ids and ordering', function () {
    $contract = app(NovelOutlineStageContract::class);
    $checker = app(OpenAiStructuredOutputSchema::class);
    $structure = $contract->structureSchema(2);
    $arcBeats = $contract->arcBeatsSchema();

    expect($checker->errors($structure))->toBe([])
        ->and($checker->errors($arcBeats))->toBe([])
        ->and(json_encode($structure, JSON_THROW_ON_ERROR))->not->toContain('beats')
        ->and(json_encode([$structure, $arcBeats], JSON_THROW_ON_ERROR))
        ->not->toContain('milestone')
        ->not->toContain('handoff')
        ->not->toContain('_id')
        ->not->toContain('mainline_sequence')
        ->not->toContain('"sequence"');
});

test('structure validation rejects beats ids extra ordering duplicate keys and wrong volume count', function (callable $mutate, int $volumeCount) {
    $payload = ogrStructurePayload();
    $mutate($payload);

    expect(fn () => app(NovelOutlineStageContract::class)->validateStructure($payload, $volumeCount))
        ->toThrow(ValidationException::class);
})->with([
    'beat data' => [function (array &$payload): void {
        $payload['volumes'][0]['arcs'][0]['beats'] = [];
    }, 2],
    'database id' => [function (array &$payload): void {
        $payload['volumes'][0]['id'] = 9;
    }, 2],
    'provider sequence' => [function (array &$payload): void {
        $payload['volumes'][0]['sequence'] = 1;
    }, 2],
    'duplicate volume key' => [function (array &$payload): void {
        $payload['volumes'][1]['key'] = 'vol-01';
    }, 2],
    'duplicate arc key' => [function (array &$payload): void {
        $payload['volumes'][1]['arcs'][0]['key'] = 'arc-01';
    }, 2],
    'wrong volume count' => [function (): void {}, 1],
]);

test('laravel assigns deterministic sibling sequences to structure and arc beats', function () {
    $contract = app(NovelOutlineStageContract::class);
    $structure = $contract->validateStructure(ogrStructurePayload(), 2);
    $arcBeats = $contract->validateArcBeats(ogrArcBeatsPayload(), 'arc-01', $structure);

    expect(array_column($structure['volumes'], 'sequence'))->toBe([1, 2])
        ->and(array_column($structure['volumes'][0]['arcs'], 'sequence'))->toBe([1])
        ->and(array_column($arcBeats['beats'], 'sequence'))->toBe([1, 2])
        ->and(json_encode([$structure, $arcBeats], JSON_THROW_ON_ERROR))->not->toContain('mainline_sequence');
});

test('arc beats rejects wrong target cross arc data duplicate keys invalid budgets and extra fields', function (callable $mutate, string $targetArcKey) {
    $contract = app(NovelOutlineStageContract::class);
    $structure = $contract->validateStructure(ogrStructurePayload(), 2);
    $payload = ogrArcBeatsPayload();
    $mutate($payload);

    expect(fn () => $contract->validateArcBeats($payload, $targetArcKey, $structure))
        ->toThrow(ValidationException::class);
})->with([
    'wrong arc key' => [function (array &$payload): void {
        $payload['arc_key'] = 'arc-02';
    }, 'arc-01'],
    'unknown target arc' => [function (): void {}, 'arc-99'],
    'cross arc field' => [function (array &$payload): void {
        $payload['beats'][0]['arc_key'] = 'arc-02';
    }, 'arc-01'],
    'duplicate beat key' => [function (array &$payload): void {
        $payload['beats'][1]['key'] = 'beat-01';
    }, 'arc-01'],
    'duplicate candidate key' => [function (array &$payload): void {
        $payload['beats'][0]['world_entity_candidates'][0]['candidate_key'] = 'guard-captain';
    }, 'arc-01'],
    'invalid budget' => [function (array &$payload): void {
        $payload['beats'][0]['chapter_budget'] = ['min' => 4, 'max' => 2];
    }, 'arc-01'],
    'milestones' => [function (array &$payload): void {
        $payload['milestones'] = [];
    }, 'arc-01'],
    'handoff' => [function (array &$payload): void {
        $payload['handoff'] = [];
    }, 'arc-01'],
    'database id' => [function (array &$payload): void {
        $payload['beats'][0]['id'] = 7;
    }, 'arc-01'],
    'provider ordering' => [function (array &$payload): void {
        $payload['beats'][0]['sequence'] = 1;
    }, 'arc-01'],
]);

test('arc beats context includes only foundation summary complete structure target and adjacent summaries', function () {
    $contract = app(NovelOutlineStageContract::class);
    $structure = $contract->validateStructure(ogrStructurePayload(), 2);
    $context = $contract->arcBeatsContext(ogrFoundationPayload(), $structure, 'arc-01');

    expect($context)->toHaveKeys(['foundation_summary', 'structure', 'target_arc', 'adjacent_arcs'])
        ->and($context['structure'])->toBe($structure)
        ->and($context['target_arc']['key'])->toBe('arc-01')
        ->and($context['adjacent_arcs']['previous'])->toBeNull()
        ->and($context['adjacent_arcs']['next']['key'])->toBe('arc-02')
        ->and($context['foundation_summary']['characters'][0])->not->toHaveKey('profile')
        ->and(json_encode($context, JSON_THROW_ON_ERROR))->not->toContain('beat-01');
});

test('outline stage capacity is checked against frozen context and model output limits', function () {
    $contract = app(NovelOutlineStageContract::class);
    $request = [
        'system_prompt' => '只返回符合 Schema 的 JSON。',
        'prompt' => '生成 Structure。',
        'schema' => $contract->structureSchema(2),
        'context' => ogrFoundationPayload(),
    ];
    $snapshot = $contract->capacitySnapshot(
        $request,
        NovelOutlineStageContract::STRUCTURE_MAX_OUTPUT_TOKENS,
        1_050_000,
        128_000,
    );

    expect($snapshot['requested_output_tokens'])->toBe(12_000)
        ->and($snapshot['estimated_input_tokens'])->toBeGreaterThan(0)
        ->and($snapshot['remaining_context_tokens'])->toBeGreaterThan(12_000);

    expect(fn () => $contract->capacitySnapshot($request, 12_000, 20_000, 8_000))
        ->toThrow(ValidationException::class)
        ->and(fn () => $contract->capacitySnapshot($request, 12_000, 100, 128_000))
        ->toThrow(ValidationException::class);
});

test('new prompt and artifact types preserve the legacy skeleton version', function () {
    expect(NovelOutlineStageContract::STRUCTURE_PROMPT_VERSION)->toBe('novel-outline-structure-v1')
        ->and(NovelOutlineStageContract::ARC_BEATS_PROMPT_VERSION)->toBe('novel-outline-arc-beats-v1')
        ->and(NovelOutlinePipeline::SKELETON_PROMPT_VERSION)->toBe('novel-outline-skeleton-v1')
        ->and(ArtifactType::OutlineStructure->value)->toBe('outline_structure')
        ->and(ArtifactType::OutlineArcBeats->value)->toBe('outline_arc_beats');
});
