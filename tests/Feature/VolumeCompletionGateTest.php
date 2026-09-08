<?php

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\ReviewDecision;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Models\Chapter;
use App\Models\Foreshadowing;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\StoryArc;
use App\Models\Volume;
use App\Services\VolumeCompletionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('completion gate reports every required checklist item', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    $result = app(VolumeCompletionGate::class)->evaluate($volume);

    expect(collect($result->checks)->pluck('key')->all())->toBe([
        'goal', 'climax', 'required_arcs', 'character_stage_changes', 'due_foreshadowings', 'blocking_findings',
    ])->and(collect($result->checks)->pluck('status')->unique()->diff(['PASS', 'WARNING', 'BLOCK']))->toBeEmpty();
});

test('open required arcs critical due foreshadowings and blocking reviews prevent completion', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    $chapter = Chapter::factory()->for($novel)->for($volume)->create(['sequence' => 20]);
    $arc = StoryArc::factory()->forVolume($volume)->create(['status' => StoryArcStatus::Active]);
    Foreshadowing::factory()->for($novel)->create([
        'owner_arc_id' => $arc->getKey(), 'due_from_chapter' => 10, 'due_to_chapter' => 18,
        'importance' => ForeshadowingImportance::Critical, 'status' => ForeshadowingStatus::Due,
    ]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create();
    Review::factory()->for($run)->create(['decision' => ReviewDecision::Block]);

    $result = app(VolumeCompletionGate::class)->evaluate($volume);
    $statuses = collect($result->checks)->pluck('status', 'key');

    expect($result->canComplete())->toBeFalse()
        ->and($statuses['required_arcs'])->toBe('BLOCK')
        ->and($statuses['due_foreshadowings'])->toBe('BLOCK')
        ->and($statuses['blocking_findings'])->toBe('BLOCK');

    app(VolumeCompletionGate::class)->complete($volume);
})->throws(ValidationException::class, 'Completion Checklist 仍有阻塞项');

test('warnings allow an active volume to complete idempotently', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    $gate = app(VolumeCompletionGate::class);

    expect($gate->evaluate($volume)->canComplete())->toBeTrue();

    $completed = $gate->complete($volume);
    $again = $gate->complete($completed);

    expect($completed->status)->toBe(VolumeStatus::Completed)
        ->and($again->status)->toBe(VolumeStatus::Completed);
});

test('a planned volume cannot be completed directly', function () {
    $volume = Volume::factory()->create(['status' => VolumeStatus::Planned]);

    app(VolumeCompletionGate::class)->complete($volume);
})->throws(ValidationException::class, '只有进行中的分卷可以完成');
