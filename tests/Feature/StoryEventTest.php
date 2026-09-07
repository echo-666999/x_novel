<?php

use App\Enums\EventType;
use App\Enums\StoryEventStatus;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\StoryEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;

uses(RefreshDatabase::class);

test('a formal story event keeps queryable canonical provenance', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $scene = Scene::factory()->for($chapter)->create();

    $event = StoryEvent::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
        'scene_id' => $scene->getKey(),
        'event_type' => EventType::CharacterLearned,
        'evidence' => [['quote' => '沈澜终于知道灯塔的秘密。', 'artifact_id' => 41, 'scene_id' => $scene->getKey()]],
        'state_version' => 3,
    ]);

    expect($event->fresh())
        ->event_type->toBe(EventType::CharacterLearned)
        ->status->toBe(StoryEventStatus::Active)
        ->evidence->toBe([['quote' => '沈澜终于知道灯塔的秘密。', 'artifact_id' => 41, 'scene_id' => $scene->getKey()]])
        ->and($novel->storyEvents()->active()->sole()->is($event))->toBeTrue()
        ->and($chapter->storyEvents()->sole()->is($event))->toBeTrue()
        ->and($scene->storyEvents()->sole()->is($event))->toBeTrue();
});

test('a formal story event is append only except for invalidation metadata', function () {
    $event = StoryEvent::factory()->create();

    $event->update([
        'status' => StoryEventStatus::Invalidated,
        'invalidated_at' => now(),
    ]);

    expect($event->fresh()->status)->toBe(StoryEventStatus::Invalidated)
        ->and($event->fresh()->invalidated_at)->not->toBeNull();

    expect(fn () => $event->update(['payload' => ['to' => '被改写的地点']]))
        ->toThrow(LogicException::class, 'append-only');
    expect(fn () => $event->delete())
        ->toThrow(LogicException::class, 'append-only');
});

test('postgres rejects invalid event status and empty evidence', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL CHECK constraints only.');
    }

    $event = StoryEvent::factory()->make();

    expect(fn () => DB::table('story_events')->insert([
        ...$event->getAttributes(),
        'status' => 'unknown',
        'evidence' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]))->toThrow(Throwable::class);
});
