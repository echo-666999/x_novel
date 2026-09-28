<?php

use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Enums\EventType;
use App\Enums\StoryArcStatus;
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
use Illuminate\Validation\ValidationException;
use LogicException;

uses(RefreshDatabase::class);

/** @return array{Novel, Volume, StoryArc} */
function currentOutlineProjection(): array
{
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    $arc = StoryArc::factory()->forVolume($volume)->create(['status' => StoryArcStatus::Active]);

    return [$novel->fresh(), $volume, $arc];
}

test('normalized validator accepts the complete relationship contract', function () {
    expect(app(NormalizedNovelOutlineValidator::class)
        ->validate(NormalizedOutlineDefinition::make())
        ->isValid())->toBeTrue();
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
