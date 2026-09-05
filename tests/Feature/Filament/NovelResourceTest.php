<?php

use App\Enums\NovelStatus;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\EditNovel;
use App\Filament\Resources\Novels\Pages\ListNovels;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the owner can list search and filter novels', function () {
    $draft = Novel::factory()->create([
        'title' => '雾海长明',
        'status' => NovelStatus::Draft,
    ]);
    $completed = Novel::factory()->create([
        'title' => '星河终章',
        'status' => NovelStatus::Completed,
    ]);

    Livewire::test(ListNovels::class)
        ->assertOk()
        ->assertSee('创建小说')
        ->assertCanSeeTableRecords([$draft, $completed])
        ->searchTable('雾海')
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$completed]);

    Livewire::test(ListNovels::class)
        ->filterTable('status', NovelStatus::Completed->value)
        ->assertCanSeeTableRecords([$completed])
        ->assertCanNotSeeTableRecords([$draft]);
});

test('the owner can create a novel and enters its workbench', function () {
    Livewire::test(CreateNovel::class)
        ->fillForm([
            'title' => '长夜将明',
            'genre' => '玄幻',
            'premise' => '失去故乡的少年踏上寻找真相的旅程。',
            'target_words' => 1_000_000,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $novel = Novel::query()->sole();

    expect($novel->title)->toBe('长夜将明')
        ->and($novel->status)->toBe(NovelStatus::Draft);

    $this->get(route('filament.x.resources.novels.edit', $novel))
        ->assertOk()
        ->assertSee('小说工作台');
});

test('the owner can edit a novels basic information', function () {
    $novel = Novel::factory()->create();

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->fillForm([
            'title' => '群星彼岸',
            'genre' => '科幻',
            'premise' => '远航者寻找失落文明。',
            'target_words' => 600_000,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($novel->refresh())
        ->title->toBe('群星彼岸')
        ->genre->toBe('科幻')
        ->target_words->toBe(600_000)
        ->status->toBe(NovelStatus::Draft)
        ->current_chapter_sequence->toBeNull();
});

test('novel form validates required fields and positive target words', function () {
    Livewire::test(CreateNovel::class)
        ->fillForm([
            'title' => '',
            'genre' => '',
            'target_words' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'title' => 'required',
            'genre' => 'required',
            'target_words' => 'min',
        ]);
});
