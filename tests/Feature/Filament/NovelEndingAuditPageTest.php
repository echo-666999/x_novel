<?php

use App\Enums\NovelStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ViewNovelEndingAudit;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\StoryStateVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the ending audit workspace runs an audit and displays each evidence group', function () {
    $novel = Novel::factory()->create(['title' => '雾海终章', 'status' => NovelStatus::Completing]);
    NovelBible::factory()->for($novel)->create();
    $state = StoryStateVersion::factory()->for($novel)->create([
        'state' => [
            'characters' => [],
            'relationships' => [],
            'locations' => [],
            'items' => [],
            'world' => ['crises' => []],
            'timeline' => [],
            'open_threads' => [],
            'foreshadowings' => [],
            'reader_promises' => [],
            'character_arcs' => [['title' => '主角成长弧', 'status' => 'completed']],
        ],
    ]);
    $novel->update(['canonical_state_version_id' => $state->getKey()]);

    Livewire::test(ViewNovelEndingAudit::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSeeText('尚未运行结局审计')
        ->callAction('runAudit')
        ->assertNotified('结局审计已完成')
        ->assertSeeText('PASS')
        ->assertSeeText('结局契约')
        ->assertSeeText('关键收束债务')
        ->assertSeeText('伏笔回收')
        ->assertSeeText('故事线收束')
        ->assertSeeText('人物弧完成度')
        ->assertSeeText('故事状态完整性');

    $this->get(NovelResource::getUrl('ending-audit', ['record' => $novel]))
        ->assertOk()
        ->assertSee('结局审计');
});

test('the ending audit action is disabled before completing mode', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);

    Livewire::test(ViewNovelEndingAudit::class, ['record' => $novel->getRouteKey()])
        ->assertActionDisabled('runAudit');
});
