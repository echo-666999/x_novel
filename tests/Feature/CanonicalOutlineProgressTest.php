<?php

use App\Actions\Novels\ApplyNovelBlueprintAction;
use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Enums\EventType;
use App\Enums\StoryEventStatus;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\NovelOutlineBeat;
use App\Models\StoryArc;
use App\Models\StoryEvent;
use App\Services\OutlineProgressResolver;
use Database\Factories\Support\NormalizedOutlineDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array{novel: Novel, arc: StoryArc, beats: Collection<int, NovelOutlineBeat>} */
function ngc003ProgressFixture(): array
{
    $novel = Novel::factory()->create();
    NovelBible::factory()->for($novel)->create();
    $definition = NormalizedOutlineDefinition::make();
    $firstBeat = $definition['volumes'][0]['arcs'][0]['beats'][0];
    $firstBeat['milestones'][] = [
        ...$firstBeat['milestones'][0],
        'key' => 'factory-milestone-2',
        'sequence' => 2,
        'title' => 'Factory Milestone 2',
        'objective' => '完成第二阶段目标。',
    ];
    $firstBeat['handoff'] = [
        'next_beat_key' => 'factory-beat-2',
        'transition_mode' => '因果承接',
        'exit_result' => '第一阶段目标完成。',
        'next_trigger' => '新的线索出现。',
        'carried_states' => ['保留调查结果。'],
        'open_threads' => ['继续追查来源。'],
        'required_transition' => ['明确进入下一阶段。'],
        'forbidden_jump' => ['不得跳过线索发现。'],
    ];
    $secondBeat = $firstBeat;
    $secondBeat['key'] = 'factory-beat-2';
    $secondBeat['sequence'] = 2;
    $secondBeat['mainline_sequence'] = 2;
    $secondBeat['title'] = 'Factory Beat 2';
    $secondBeat['milestones'][0]['key'] = 'factory-beat-2-milestone-1';
    $secondBeat['milestones'][1]['key'] = 'factory-beat-2-milestone-2';
    $secondBeat['handoff'] = [
        'next_beat_key' => null,
        'transition_mode' => null,
        'exit_result' => null,
        'next_trigger' => null,
        'carried_states' => [],
        'open_threads' => [],
        'required_transition' => [],
        'forbidden_jump' => [],
    ];
    $definition['volumes'][0]['arcs'][0]['beats'] = [$firstBeat, $secondBeat];

    $outline = app(CreateNormalizedNovelOutlineVersionAction::class)->handle($novel, $definition);
    app(ApplyNovelBlueprintAction::class)->handle($novel, $outline);
    $novel = $novel->fresh();

    return [
        'novel' => $novel,
        'arc' => $novel->storyArcs()->where('source_outline_arc_id', $outline->arcs()->firstOrFail()->getKey())->firstOrFail(),
        'beats' => $outline->beats()->whereNotNull('mainline_sequence')->with('milestones')->orderBy('mainline_sequence')->get(),
    ];
}

function ngc003CompletionEvent(
    array $fixture,
    EventType $type,
    int $beatIndex,
    ?int $milestoneIndex = null,
    StoryEventStatus $status = StoryEventStatus::Active,
): StoryEvent {
    $novel = $fixture['novel'];
    $beat = $fixture['beats'][$beatIndex];
    $milestone = $milestoneIndex === null ? null : $beat->milestones[$milestoneIndex];
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => ((int) $novel->chapters()->max('sequence')) + 1,
    ]);

    return StoryEvent::factory()->for($novel)->for($chapter)->create([
        'event_type' => $type,
        'subject_type' => 'story_arc',
        'subject_id' => (string) $fixture['arc']->getKey(),
        'payload' => array_filter([
            'beat_key' => $beat->beat_key,
            'milestone_key' => $milestone?->milestone_key,
        ]),
        'status' => $status,
        'invalidated_at' => $status === StoryEventStatus::Invalidated ? now() : null,
        'novel_outline_id' => $beat->novel_outline_id,
        'novel_outline_arc_id' => $beat->novel_outline_arc_id,
        'novel_outline_beat_id' => $beat->getKey(),
        'novel_outline_milestone_id' => $milestone?->getKey(),
    ]);
}

test('canonical outline progress selects the earliest incomplete milestone', function () {
    $fixture = ngc003ProgressFixture();

    $target = app(OutlineProgressResolver::class)->resolve($fixture['novel']);

    expect($target)
        ->beat->key->toBe('factory-beat')
        ->milestone->key->toBe('factory-milestone')
        ->canonicalCompletedBeatKeys->toBe([])
        ->canonicalCompletedMilestoneKeys->toBe([]);
});

test('an active canonical milestone completion advances to the next milestone', function () {
    $fixture = ngc003ProgressFixture();
    ngc003CompletionEvent($fixture, EventType::StoryArcBeatMilestoneCompleted, 0, 0);

    $target = app(OutlineProgressResolver::class)->resolve($fixture['novel']->fresh());

    expect($target)
        ->beat->key->toBe('factory-beat')
        ->milestone->key->toBe('factory-milestone-2')
        ->canonicalCompletedMilestoneKeys->toBe(['factory-milestone']);
});

test('active completion uniqueness rejects duplicates and invalidation releases the key', function () {
    $fixture = ngc003ProgressFixture();
    $event = ngc003CompletionEvent($fixture, EventType::StoryArcBeatMilestoneCompleted, 0, 0);

    expect(fn () => ngc003CompletionEvent($fixture, EventType::StoryArcBeatMilestoneCompleted, 0, 0))
        ->toThrow(QueryException::class);

    $event->update([
        'status' => StoryEventStatus::Invalidated,
        'invalidated_at' => now(),
    ]);
    ngc003CompletionEvent($fixture, EventType::StoryArcBeatMilestoneCompleted, 0, 0);

    expect(StoryEvent::query()
        ->where('event_type', EventType::StoryArcBeatMilestoneCompleted)
        ->where('status', StoryEventStatus::Active)
        ->count())->toBe(1);
});

test('beat completion cannot advance while any milestone remains incomplete', function () {
    $fixture = ngc003ProgressFixture();
    ngc003CompletionEvent($fixture, EventType::StoryArcBeatMilestoneCompleted, 0, 0);
    ngc003CompletionEvent($fixture, EventType::StoryArcBeatCompleted, 0);

    expect(fn () => app(OutlineProgressResolver::class)->resolve($fixture['novel']->fresh()))
        ->toThrow(ValidationException::class, 'factory-milestone-2 尚未正式完成');
});

test('a fully completed beat advances to the next beat entry milestone', function () {
    $fixture = ngc003ProgressFixture();
    ngc003CompletionEvent($fixture, EventType::StoryArcBeatMilestoneCompleted, 0, 0);
    ngc003CompletionEvent($fixture, EventType::StoryArcBeatMilestoneCompleted, 0, 1);
    ngc003CompletionEvent($fixture, EventType::StoryArcBeatCompleted, 0);

    $target = app(OutlineProgressResolver::class)->resolve($fixture['novel']->fresh());

    expect($target)
        ->beat->key->toBe('factory-beat-2')
        ->milestone->key->toBe('factory-beat-2-milestone-1')
        ->canonicalCompletedBeatKeys->toBe(['factory-beat'])
        ->canonicalCompletedMilestoneKeys->toBe(['factory-milestone', 'factory-milestone-2']);
});

test('postgres migration accepts milestone completion and installs logical active indexes', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('NGC-003 PostgreSQL migration contract.');
    }

    $fixture = ngc003ProgressFixture();
    ngc003CompletionEvent($fixture, EventType::StoryArcBeatMilestoneCompleted, 0, 0);
    $indexes = collect(DB::select(
        'SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname IN (?, ?)',
        [
            DB::getTablePrefix().'story_events',
            'story_events_active_milestone_completion_unique',
            'story_events_active_beat_completion_unique',
        ],
    ))->pluck('indexdef', 'indexname');

    expect($indexes)->toHaveCount(2)
        ->and($indexes['story_events_active_milestone_completion_unique'])
        ->toContain('subject_id', 'novel_outline_beat_id', 'novel_outline_milestone_id')
        ->and($indexes['story_events_active_beat_completion_unique'])
        ->toContain('subject_id', 'novel_outline_beat_id');
});
