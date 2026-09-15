<?php

use App\Actions\Foreshadowings\AbandonForeshadowingAction;
use App\Actions\Foreshadowings\DeferForeshadowingAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\ForeshadowingStatus;
use App\Jobs\RefreshNovelProjectionJob;
use App\Models\Chapter;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Services\ForeshadowingPlanningGate;
use App\Services\ProjectionRebuilder;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('defer moves the payoff window and keeps an auditable management record', function () {
    $novel = Novel::factory()->create(['current_chapter_sequence' => 10]);
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 1,
        'due_to_chapter' => 10,
    ]);
    app(InitializeNovelStateAction::class)->handle($novel);

    $result = app(DeferForeshadowingAction::class)->execute(
        $foreshadowing,
        15,
        20,
        '主线冲突尚未结束，延期到下一阶段处理。',
        42,
    );
    $nextChapter = Chapter::factory()->for($novel)->create([
        'sequence' => 11,
        'status' => ChapterStatus::Planned,
    ]);
    app(ForeshadowingPlanningGate::class)->assertModelPlanningAllowed($nextChapter);

    $audit = $result->management_history[0];
    expect($result->due_from_chapter)->toBe(15)
        ->and($result->due_to_chapter)->toBe(20)
        ->and($result->status)->toBe(ForeshadowingStatus::Reinforced)
        ->and($audit['action'])->toBe('defer')
        ->and($audit['reason'])->toBe('主线冲突尚未结束，延期到下一阶段处理。')
        ->and($audit['before']['due_to_chapter'])->toBe(10)
        ->and($audit['after']['due_from_chapter'])->toBe(15)
        ->and($audit['canonical_chapter'])->toBe(10)
        ->and($audit['state_version'])->toBe(0)
        ->and($audit['actor_id'])->toBe(42)
        ->and($audit['performed_at'])->not->toBeEmpty();
});

test('defer rejects a terminal foreshadowing or a window that does not move forward', function () {
    $novel = Novel::factory()->create();
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'status' => ForeshadowingStatus::PaidOff,
        'due_from_chapter' => 1,
        'due_to_chapter' => 10,
    ]);
    app(InitializeNovelStateAction::class)->handle($novel);

    expect(fn () => app(DeferForeshadowingAction::class)->execute($foreshadowing, 11, 20, '不能延期。'))
        ->toThrow(ValidationException::class, '不能延期');

    $active = Foreshadowing::factory()->for($novel)->create([
        'status' => ForeshadowingStatus::Planted,
        'due_from_chapter' => 1,
        'due_to_chapter' => 10,
    ]);

    expect(fn () => app(DeferForeshadowingAction::class)->execute($active, 10, 20, '窗口没有后移。'))
        ->toThrow(ValidationException::class, '必须晚于当前最晚兑现章节');
});

test('abandon creates an audited canonical correction and refreshes the projection safely', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['current_chapter_sequence' => 10]);
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'status' => ForeshadowingStatus::Reinforced,
        'reinforce_count' => 2,
    ]);
    $initial = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 10, 'status' => ChapterStatus::Canonical]);
    $current = StoryStateVersion::factory()->for($novel)->for($chapter)->create([
        'version' => 1,
        'state' => $initial->state,
        'checksum' => app(StoryStateService::class)->checksum($initial->state),
    ]);
    $novel->update(['canonical_state_version_id' => $current->getKey()]);

    $version = app(AbandonForeshadowingAction::class)->execute(
        $foreshadowing,
        '该承诺与已确定结局冲突，明确放弃。',
        42,
    );

    $event = StoryEvent::query()->sole();
    expect(data_get($version->state, "foreshadowings.{$foreshadowing->getKey()}.status"))->toBe('abandoned')
        ->and($event->event_type)->toBe(EventType::ManualCorrection)
        ->and($event->payload['management_action'])->toBe('abandon')
        ->and($event->payload['reason'])->toBe('该承诺与已确定结局冲突，明确放弃。')
        ->and($event->payload['actor_id'])->toBe(42)
        ->and($event->payload['performed_at'])->not->toBeEmpty();

    Queue::assertPushed(RefreshNovelProjectionJob::class);
    (new RefreshNovelProjectionJob($novel->getKey(), $version->getKey()))
        ->handle(app(ProjectionRebuilder::class));

    expect($foreshadowing->fresh()->status)->toBe(ForeshadowingStatus::Abandoned)
        ->and($foreshadowing->fresh()->reinforce_count)->toBe(2);
});
