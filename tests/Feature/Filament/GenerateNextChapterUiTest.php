<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\NovelStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the novel overview can create the next chapter and open its workbench', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    NovelBible::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    $component = Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertActionEnabled('generateNextChapter')
        ->callAction('generateNextChapter')
        ->assertNotified('章节工作流已就绪');

    $chapter = $novel->chapters()->sole();

    $component->assertRedirect(NovelResource::getUrl('chapter', [
        'record' => $novel,
        'chapter' => $chapter,
    ]));
});

test('the novel overview displays a specific preflight failure reason', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->callAction('generateNextChapter')
        ->assertNotified('无法生成下一章');

    expect($novel->chapters()->count())->toBe(0);
});
