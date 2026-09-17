<?php

use App\Filament\Resources\Novels\Pages\EditNovel;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('ai.models.planner', 'global-planner');
    config()->set('ai.models.writer', 'global-writer');
});

test('novel settings shows global and overridden resolved models', function () {
    $novel = Novel::factory()->create([
        'settings' => ['ai' => ['models' => ['writer' => 'novel-writer']]],
    ]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSee('AI Model Overrides')
        ->assertSee('章节规划 Resolved Model')
        ->assertSee('global-planner · Global Default')
        ->assertSee('场景写作 Resolved Model')
        ->assertSee('novel-writer · Novel Override')
        ->assertFormSet([
            'ai_model_overrides.writer' => 'novel-writer',
        ]);
});

test('saving novel model overrides preserves unrelated settings and removes blank overrides', function () {
    $novel = Novel::factory()->create([
        'settings' => [
            'temperature' => 0.4,
            'ai' => [
                'custom_option' => true,
                'models' => [
                    'planner' => 'old-planner',
                    'writer' => 'old-writer',
                ],
            ],
        ],
    ]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->fillForm([
            'ai_model_overrides' => [
                'planner' => ' new-planner ',
                'writer' => '',
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($novel->refresh()->settings)->toBe([
        'temperature' => 0.4,
        'ai' => [
            'custom_option' => true,
            'models' => ['planner' => 'new-planner'],
        ],
        'auto_commit' => false,
        'auto_commit_configured' => true,
    ]);
});

test('novel settings exposes explicit automatic canonical commit and ignores the legacy key', function () {
    $novel = Novel::factory()->create(['settings' => ['auto_commit' => true]]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertSee('Review 通过后自动提交正式章节')
        ->assertFormSet(['workflow_auto_commit' => false])
        ->fillForm(['workflow_auto_commit' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($novel->refresh()->settings, 'auto_commit'))->toBeTrue()
        ->and(data_get($novel->settings, 'auto_commit_configured'))->toBeTrue();
});
