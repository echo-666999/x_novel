<?php

use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Enums\ArtifactType;
use App\Enums\NovelOutlineSource;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Services\NormalizedNovelOutlineValidator;
use App\Services\NovelOutlineChecksum;
use App\Services\NovelOutlinePipeline;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

uses(RefreshDatabase::class);

function normalizedOutlineFixture(): array
{
    return [
        'title' => '关系化全书大纲',
        'summary' => '验证 Volume、Arc、Beat、Milestone 和 Handoff 的完整父链。',
        'must_include' => ['主角完成成长'],
        'must_not_include' => ['无因果跳跃'],
        'volumes' => [[
            'key' => 'vol-01',
            'sequence' => 1,
            'title' => '第一卷',
            'goal' => '建立主线冲突。',
            'climax' => '主角第一次反击。',
            'target_words' => 100_000,
            'arcs' => [[
                'key' => 'arc-01',
                'sequence' => 1,
                'mainline_sequence' => 1,
                'type' => 'main',
                'title' => '主线一',
                'goal' => '找到反击方向。',
                'stakes' => '失败会失去立足点。',
                'completion_conditions' => ['主角完成第一次反击。'],
                'beats' => [
                    normalizedBeat('beat-01', 1, 'beat-02'),
                    normalizedBeat('beat-02', 2, null),
                ],
            ], [
                'key' => 'arc-02',
                'sequence' => 2,
                'mainline_sequence' => null,
                'type' => 'subplot',
                'title' => '友情支线',
                'goal' => '建立同伴关系。',
                'stakes' => '误解会破坏合作。',
                'completion_conditions' => ['双方建立信任。'],
                'beats' => [[
                    ...normalizedBeat('subplot-beat-01', null, null),
                    'milestones' => [],
                ]],
            ]],
        ]],
    ];
}

function normalizedBeat(string $key, ?int $mainlineSequence, ?string $nextBeatKey): array
{
    return [
        'key' => $key,
        'sequence' => $mainlineSequence ?? 1,
        'mainline_sequence' => $mainlineSequence,
        'title' => $key.' 标题',
        'summary' => $key.' 剧情目标。',
        'chapter_budget' => ['min' => 1, 'max' => 4],
        'acceptance_criteria' => [$key.' 已完成。'],
        'must_include' => [],
        'must_not_include' => [],
        'character_candidates' => [],
        'world_entity_candidates' => [],
        'milestones' => [[
            'key' => $key.'-m01',
            'sequence' => 1,
            'title' => '进入',
            'objective' => '完成当前阶段目标。',
            'acceptance_criteria' => ['阶段结果已有正文证据。'],
            'must_include' => [],
            'must_not_include' => [],
        ]],
        'handoff' => [
            'next_beat_key' => $nextBeatKey,
            'transition_mode' => $nextBeatKey === null ? null : 'causal',
            'exit_result' => $nextBeatKey === null ? null : '前一目标完成。',
            'next_trigger' => $nextBeatKey === null ? null : '结果触发下一目标。',
            'carried_states' => [],
            'open_threads' => [],
            'required_transition' => [],
            'forbidden_jump' => [],
        ],
    ];
}

test('creates a complete normalized outline in one transaction with stable checksum', function () {
    $novel = Novel::factory()->create();
    $data = normalizedOutlineFixture();
    $outline = app(CreateNormalizedNovelOutlineVersionAction::class)->handle($novel, $data);

    expect($outline->volumes)->toHaveCount(1)
        ->and($outline->arcs)->toHaveCount(2)
        ->and($outline->beats)->toHaveCount(3)
        ->and($outline->milestones)->toHaveCount(2)
        ->and($outline->beats()->where('beat_key', 'beat-01')->firstOrFail()->handoffNextBeat->beat_key)->toBe('beat-02')
        ->and(app(NovelOutlineChecksum::class)->for($outline))->toBe($outline->checksum)
        ->and(app(NovelOutlineChecksum::class)->for($data))->toBe($outline->checksum);
});

test('rejects sequence gaps missing milestones and handoff jumps before persistence', function () {
    $novel = Novel::factory()->create();
    $data = normalizedOutlineFixture();
    $data['volumes'][0]['arcs'][0]['beats'][0]['sequence'] = 2;
    $data['volumes'][0]['arcs'][0]['beats'][0]['milestones'] = [];
    $data['volumes'][0]['arcs'][0]['beats'][0]['handoff']['next_beat_key'] = null;

    expect(fn () => app(CreateNormalizedNovelOutlineVersionAction::class)->handle($novel, $data))
        ->toThrow(ValidationException::class)
        ->and($novel->outlines()->count())->toBe(0);
});

test('reuses the outline created by the same final artifact', function () {
    $novel = Novel::factory()->create();
    $batch = GenerationRun::factory()->for($novel)->create([
        'scope_type' => NovelOutlinePipeline::BATCH_SCOPE,
        'scope_id' => $novel->getKey(),
        'status' => RunStatus::Running,
    ]);
    $run = GenerationRun::factory()->for($novel)->create([
        'scope_type' => NovelOutlinePipeline::FINALIZE_SCOPE,
        'scope_id' => $batch->getKey(),
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::OutlineBlueprint,
        'data' => ['outline' => normalizedOutlineFixture(), 'lineage' => ['batch_run_id' => $batch->getKey()]],
    ]);
    $action = app(CreateNormalizedNovelOutlineVersionAction::class);

    $first = $action->handle($novel, normalizedOutlineFixture(), NovelOutlineSource::Ai, sourceArtifact: $artifact);
    $second = $action->handle($novel, normalizedOutlineFixture(), NovelOutlineSource::Ai, sourceArtifact: $artifact);

    expect($second->is($first))->toBeTrue()
        ->and($novel->outlines()->count())->toBe(1);
});

test('protects persisted outline headers and children from in-place mutation', function () {
    $outline = app(CreateNormalizedNovelOutlineVersionAction::class)
        ->handle(Novel::factory()->create(), normalizedOutlineFixture());

    expect(fn () => $outline->update(['title' => '禁止原地修改']))->toThrow(LogicException::class)
        ->and(fn () => $outline->beats()->firstOrFail()->update(['title' => '禁止原地修改']))->toThrow(LogicException::class);
});

test('database rejects a milestone parent chain from another outline', function () {
    $action = app(CreateNormalizedNovelOutlineVersionAction::class);
    $first = $action->handle(Novel::factory()->create(), normalizedOutlineFixture());
    $second = $action->handle(Novel::factory()->create(), normalizedOutlineFixture());
    $foreignBeat = $second->beats()->whereNotNull('mainline_sequence')->firstOrFail();

    expect(fn () => $first->milestones()->create([
        'novel_outline_beat_id' => $foreignBeat->getKey(),
        'milestone_key' => 'cross-outline-milestone',
        'sequence' => 99,
        'title' => '非法节点',
        'objective' => '跨版本引用。',
        'acceptance_criteria' => ['不应保存。'],
        'must_include' => [],
        'must_not_include' => [],
    ]))->toThrow(QueryException::class);
});

test('checksum changes when a business field changes', function () {
    $checksum = app(NovelOutlineChecksum::class);
    $before = normalizedOutlineFixture();
    $after = normalizedOutlineFixture();
    $after['volumes'][0]['arcs'][0]['beats'][0]['milestones'][0]['objective'] = '修改后的目标。';

    expect($checksum->for($before))->not->toBe($checksum->for($after))
        ->and(app(NormalizedNovelOutlineValidator::class)->validate($before)->isValid())->toBeTrue();
});

test('chapter plan composite foreign keys reject a crossed primary target chain', function () {
    $action = app(CreateNormalizedNovelOutlineVersionAction::class);
    $novel = Novel::factory()->create();
    $first = $action->handle($novel, normalizedOutlineFixture());
    $second = $action->handle($novel, [
        ...normalizedOutlineFixture(),
        'title' => '第二版本',
    ]);
    $chapter = Chapter::factory()->for($novel)->create();
    $arc = $first->arcs()->where('type', 'main')->firstOrFail();
    $beat = $first->beats()->whereNotNull('mainline_sequence')->firstOrFail();
    $foreignMilestone = $second->milestones()->firstOrFail();

    expect(fn () => ChapterPlan::factory()->for($chapter)->create([
        'novel_outline_id' => $first->getKey(),
        'primary_outline_arc_id' => $arc->getKey(),
        'primary_outline_beat_id' => $beat->getKey(),
        'primary_outline_milestone_id' => $foreignMilestone->getKey(),
    ]))->toThrow(QueryException::class);
});

test('postgres completion checks distinguish beat and milestone references', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Completion CHECK Constraint 由 PostgreSQL 验证。');
    }

    $novel = Novel::factory()->create();
    $outline = app(CreateNormalizedNovelOutlineVersionAction::class)->handle($novel, normalizedOutlineFixture());
    $arc = $outline->arcs()->where('type', 'main')->firstOrFail();
    $beat = $outline->beats()->whereNotNull('mainline_sequence')->firstOrFail();
    $milestone = $beat->milestones()->firstOrFail();
    $chapter = Chapter::factory()->for($novel)->create();

    StoryEvent::factory()->for($novel)->for($chapter)->create([
        'event_type' => 'story_arc_beat_completed',
        'novel_outline_id' => $outline->getKey(),
        'novel_outline_arc_id' => $arc->getKey(),
        'novel_outline_beat_id' => $beat->getKey(),
        'novel_outline_milestone_id' => null,
    ]);

    expect(fn () => StoryEvent::factory()->for($novel)->for($chapter)->create([
        'event_type' => 'story_arc_beat_milestone_completed',
        'novel_outline_id' => $outline->getKey(),
        'novel_outline_arc_id' => $arc->getKey(),
        'novel_outline_beat_id' => $beat->getKey(),
        'novel_outline_milestone_id' => null,
    ]))->toThrow(QueryException::class);
});
