<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the novel overview offers story state initialization when state is missing', function () {
    $novel = Novel::factory()->create();

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertSee('Story State: Not Initialized')
        ->assertActionVisible('initializeStoryState')
        ->callAction('initializeStoryState')
        ->assertHasNoActionErrors()
        ->assertNotified('Story State 已初始化')
        ->assertSee('Current State Version: 0')
        ->assertActionHidden('initializeStoryState');

    expect($novel->storyStateVersions()->count())->toBe(1)
        ->and($novel->refresh()->canonicalStateVersion->version)->toBe(0);
});

test('the novel overview shows an initialized state without another initialize action', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertSee('Current State Version: 0')
        ->assertActionHidden('initializeStoryState');
});
