<?php

use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Enums\EventType;
use App\Enums\NovelOutlineStatus;
use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Enums\StoryEventStatus;
use App\Enums\VolumeStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\StoryEvent;
use App\Models\Volume;
use App\Services\NormalizedNovelOutlineValidator;
use App\Services\NovelOutlineChecksum;
use App\Services\OutlineContextBuilder;
use App\Services\OutlineProgressResolver;
use Database\Factories\Support\NormalizedOutlineDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array{Novel, Volume, StoryArc} */
function currentOutlineProjection(): array
{
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    $arc = StoryArc::factory()->forVolume($volume)->create(['status' => StoryArcStatus::Active]);

    return [$novel->fresh(), $volume, $arc];
}

/** @return array<string, mixed> */
function largeNormalizedOutlineDefinition(): array
{
    $volumes = [];
    $globalBeatSequence = 0;

    foreach (range(1, 3) as $volumeSequence) {
        $beats = [];
        foreach (range(1, 30) as $beatSequence) {
            $globalBeatSequence++;
            $beatKey = sprintf('large-beat-%03d', $globalBeatSequence);
            $nextBeatKey = $globalBeatSequence < 90 ? sprintf('large-beat-%03d', $globalBeatSequence + 1) : null;
            $beats[] = [
                'key' => $beatKey,
                'sequence' => $beatSequence,
                'mainline_sequence' => $globalBeatSequence,
                'title' => "大纲节拍 {$globalBeatSequence}",
                'summary' => "推进第 {$globalBeatSequence} 个主线目标。",
                'chapter_budget' => ['min' => 1, 'max' => 3],
                'acceptance_criteria' => ["主线目标 {$globalBeatSequence} 已完成。"],
                'must_include' => [],
                'must_not_include' => [],
                'character_candidates' => [],
                'world_entity_candidates' => [],
                'milestones' => collect(range(1, 2))->map(fn (int $milestoneSequence): array => [
                    'key' => "{$beatKey}-m0{$milestoneSequence}",
                    'sequence' => $milestoneSequence,
                    'title' => "里程碑 {$milestoneSequence}",
                    'objective' => "完成 {$beatKey} 的第 {$milestoneSequence} 步。",
                    'acceptance_criteria' => ["第 {$milestoneSequence} 步有正文证据。"],
                    'must_include' => [],
                    'must_not_include' => [],
                ])->all(),
                'handoff' => [
                    'next_beat_key' => $nextBeatKey,
                    'transition_mode' => $nextBeatKey === null ? null : 'causal',
                    'exit_result' => $nextBeatKey === null ? null : "{$beatKey} 已完成。",
                    'next_trigger' => $nextBeatKey === null ? null : "结果触发 {$nextBeatKey}。",
                    'carried_states' => [],
                    'open_threads' => [],
                    'required_transition' => [],
                    'forbidden_jump' => [],
                ],
            ];
        }

        $volumes[] = [
            'key' => sprintf('large-vol-%02d', $volumeSequence),
            'sequence' => $volumeSequence,
            'title' => "大纲分卷 {$volumeSequence}",
            'goal' => "完成第 {$volumeSequence} 卷目标。",
            'climax' => "第 {$volumeSequence} 卷高潮。",
            'target_words' => 100000,
            'arcs' => [[
                'key' => sprintf('large-arc-%02d', $volumeSequence),
                'sequence' => 1,
                'mainline_sequence' => $volumeSequence,
                'type' => StoryArcType::Main->value,
                'title' => "大纲主线 {$volumeSequence}",
                'goal' => "完成第 {$volumeSequence} 段主线。",
                'stakes' => '失败将阻断后续主线。',
                'completion_conditions' => ["第 {$volumeSequence} 段主线完成。"],
                'beats' => $beats,
            ]],
        ];
    }

    return [
        'title' => 'NGC-012 大规模关系化大纲',
        'summary' => '用于验证关系化读取查询数、外键连接与索引执行计划。',
        'must_include' => [],
        'must_not_include' => [],
        'volumes' => $volumes,
    ];
}

test('normalized validator accepts the complete relationship contract', function () {
    expect(app(NormalizedNovelOutlineValidator::class)
        ->validate(NormalizedOutlineDefinition::make())
        ->isValid())->toBeTrue();
});

test('large normalized outline keeps bounded progress queries and a PostgreSQL indexed foreign key join plan', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('NGC-012 查询计划只在 PostgreSQL 验证。');
    }

    $novel = Novel::factory()->create();
    $outline = app(CreateNormalizedNovelOutlineVersionAction::class)->handle($novel, largeNormalizedOutlineDefinition());
    $outline->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
    $novel->update(['current_outline_id' => $outline->getKey()]);
    $sourceVolume = $outline->volumes()->where('sequence', 1)->firstOrFail();
    $sourceArc = $sourceVolume->arcs()->where('type', StoryArcType::Main->value)->firstOrFail();
    $volume = Volume::factory()->for($novel)->create([
        'source_outline_volume_id' => $sourceVolume->getKey(),
        'sequence' => 1,
        'status' => VolumeStatus::Active,
    ]);
    StoryArc::factory()->for($novel)->forVolume($volume)->create([
        'source_outline_arc_id' => $sourceArc->getKey(),
        'sequence' => 1,
        'type' => StoryArcType::Main,
        'status' => StoryArcStatus::Active,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $target = app(OutlineProgressResolver::class)->resolve($novel->fresh());
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $grammar = DB::connection()->getQueryGrammar();
    $beatsTable = $grammar->wrapTable('novel_outline_beats');
    $arcsTable = $grammar->wrapTable('novel_outline_arcs');
    $volumesTable = $grammar->wrapTable('novel_outline_volumes');
    $planRows = DB::select("EXPLAIN
SELECT b.id
FROM {$beatsTable} AS b
JOIN {$arcsTable} AS a
  ON a.id = b.novel_outline_arc_id
 AND a.novel_outline_id = b.novel_outline_id
JOIN {$volumesTable} AS v
  ON v.id = a.novel_outline_volume_id
 AND v.novel_outline_id = a.novel_outline_id
WHERE b.novel_outline_id = ?
  AND b.novel_outline_arc_id = ?
  AND b.mainline_sequence IS NOT NULL
ORDER BY b.mainline_sequence", [$outline->getKey(), $sourceArc->getKey()]);
    $plan = collect($planRows)
        ->flatMap(fn (object $row): array => array_values((array) $row))
        ->implode("\n");

    expect($outline->volumes()->count())->toBe(3)
        ->and($outline->arcs()->count())->toBe(3)
        ->and($outline->beats()->count())->toBe(90)
        ->and($outline->milestones()->count())->toBe(180)
        ->and($target?->beat['key'])->toBe('large-beat-001')
        ->and($queryCount)->toBeLessThanOrEqual(20)
        ->and($plan)->toContain('outline_beats_mainline_unique')
        ->and(Schema::hasColumn('novel_outlines', 'content'))->toBeFalse()
        ->and(Schema::hasColumn('story_arcs', 'beats'))->toBeFalse();
});

test('normalized validator rejects invalid sequence budget milestone and handoff contracts', function (string $case) {
    $data = NormalizedOutlineDefinition::make();

    match ($case) {
        'sequence' => $data['volumes'][0]['sequence'] = 2,
        'budget' => $data['volumes'][0]['arcs'][0]['beats'][0]['chapter_budget'] = ['min' => 3, 'max' => 2],
        'milestone' => $data['volumes'][0]['arcs'][0]['beats'][0]['milestones'] = [],
        'handoff' => $data['volumes'][0]['arcs'][0]['beats'][0]['handoff']['next_beat_key'] = 'missing-beat',
    };

    expect(app(NormalizedNovelOutlineValidator::class)->validate($data)->isValid())->toBeFalse();
})->with(['sequence', 'budget', 'milestone', 'handoff']);

test('create action persists deterministic immutable versions without changing canonical state', function () {
    $novel = Novel::factory()->create();
    $data = NormalizedOutlineDefinition::make();
    $outline = app(CreateNormalizedNovelOutlineVersionAction::class)->handle($novel, $data);

    expect($outline->checksum)->toBe(app(NovelOutlineChecksum::class)->for($data))
        ->and($novel->fresh()->current_outline_id)->toBeNull()
        ->and(fn () => $outline->update(['summary' => '禁止原地修改']))->toThrow(LogicException::class);
});

test('outline progress reads active canonical completion references only', function () {
    [$novel, , $arc] = currentOutlineProjection();
    $target = app(OutlineProgressResolver::class)->resolve($novel);
    $chapter = Chapter::factory()->for($novel)->create();

    StoryEvent::factory()->for($novel)->for($chapter)->create([
        'event_type' => EventType::StoryArcBeatMilestoneCompleted,
        'status' => StoryEventStatus::Active,
        'subject_type' => 'story_arc',
        'subject_id' => (string) $arc->getKey(),
        'novel_outline_id' => $target->outlineId,
        'novel_outline_arc_id' => $target->outlineArcId,
        'novel_outline_beat_id' => $target->outlineBeatId,
        'novel_outline_milestone_id' => $target->outlineMilestoneId,
    ]);
    StoryEvent::factory()->for($novel)->for($chapter)->create([
        'event_type' => EventType::StoryArcBeatCompleted,
        'status' => StoryEventStatus::Active,
        'subject_type' => 'story_arc',
        'subject_id' => (string) $arc->getKey(),
        'novel_outline_id' => $target->outlineId,
        'novel_outline_arc_id' => $target->outlineArcId,
        'novel_outline_beat_id' => $target->outlineBeatId,
        'novel_outline_milestone_id' => null,
    ]);

    expect($target->beat['key'])->toBe('factory-beat')
        ->and(app(OutlineProgressResolver::class)->resolve($novel->fresh()))->toBeNull();
});

test('outline context freezes the current milestone contract', function () {
    [$novel] = currentOutlineProjection();
    $context = app(OutlineContextBuilder::class)->build($novel);

    expect($context['primary_beat_key'])->toBe('factory-beat')
        ->and($context['primary_milestone_key'])->toBe('factory-milestone')
        ->and($context['acceptance_criteria'])->toBe(['完整父链存在。']);
});

test('outline versions referenced by chapter plans cannot be deleted', function () {
    [$novel, $volume] = currentOutlineProjection();
    $chapter = Chapter::factory()->for($novel)->for($volume)->create();
    $plan = ChapterPlan::factory()->for($chapter)->create();

    expect(fn () => $plan->novelOutline->delete())->toThrow(LogicException::class);
});

test('outline context fails before provider work when runtime source is not current', function () {
    [$novel, $volume] = currentOutlineProjection();
    $other = app(CreateNormalizedNovelOutlineVersionAction::class)->handle(
        $novel,
        [...NormalizedOutlineDefinition::make(), 'title' => '另一版本'],
    );
    $novel->update(['current_outline_id' => $other->getKey()]);

    expect(fn () => app(OutlineProgressResolver::class)->resolve($novel->fresh()))
        ->toThrow(ValidationException::class, '权威来源外键');
});
