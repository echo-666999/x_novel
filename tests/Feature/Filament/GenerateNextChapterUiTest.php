<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\NovelStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Jobs\PlanChapterJob;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the novel overview can create the next chapter and open its workbench', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    NovelBible::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    $component = Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertActionEnabled('generateNextChapter')
        ->callAction('generateNextChapter')
        ->assertNotified('章节流水线已启动');

    $chapter = $novel->chapters()->sole();

    $component->assertRedirect(NovelResource::getUrl('chapter', [
        'record' => $novel,
        'chapter' => $chapter,
    ]));
    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $chapter->getKey());
});

test('the novel overview displays a specific preflight failure reason', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->callAction('generateNextChapter')
        ->assertNotified('无法生成下一章');

    expect($novel->chapters()->count())->toBe(0);
});

test('automatic generation stays off when the current chapter cannot start', function () {
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'settings' => ['auto_generate' => false],
    ]);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->callAction('startAutoGenerate')
        ->assertNotified('无法开始自动生成');

    expect(data_get($novel->fresh()->settings, 'auto_generate'))->toBeFalse()
        ->and($novel->chapters()->count())->toBe(0);
});
