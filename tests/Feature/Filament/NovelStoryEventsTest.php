<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\EventType;
use App\Enums\StoryEventStatus;
use App\Filament\Resources\Novels\Pages\ViewNovelStoryState;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\StoryEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the inspector shows active formal story events with provenance by default', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 8]);
    $scene = Scene::factory()->for($chapter)->create(['sequence' => 2]);
    StoryEvent::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
        'scene_id' => $scene->getKey(),
        'event_type' => EventType::CharacterMoved,
        'evidence' => [['quote' => '沈澜走进了灯塔。']],
    ]);
    StoryEvent::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
        'event_type' => EventType::ItemLost,
        'evidence' => [['quote' => '旧钥匙已经遗失。']],
        'status' => StoryEventStatus::Invalidated,
        'invalidated_at' => now(),
    ]);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->call('selectDomain', 'story_events')
        ->assertSee('故事事件')
        ->assertSee('正式故事事件 · 默认只读')
        ->assertSee('有效')
        ->assertSee('已失效')
        ->assertSee('全部')
        ->assertSee('第 8 章')
        ->assertSee('场景 2')
        ->assertSee('character_moved')
        ->assertSee('沈澜走进了灯塔。')
        ->assertDontSee('旧钥匙已经遗失。');
});

test('the inspector can query invalidated and all story events', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create();
    StoryEvent::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
        'evidence' => [['quote' => '有效事件证据。']],
    ]);
    StoryEvent::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
        'evidence' => [['quote' => '失效事件证据。']],
        'status' => StoryEventStatus::Invalidated,
        'invalidated_at' => now(),
    ]);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->call('selectDomain', 'story_events')
        ->call('selectEventStatus', 'invalidated')
        ->assertSet('eventStatus', 'invalidated')
        ->assertSee('失效事件证据。')
        ->assertDontSee('有效事件证据。')
        ->call('selectEventStatus', 'all')
        ->assertSee('失效事件证据。')
        ->assertSee('有效事件证据。');
});
