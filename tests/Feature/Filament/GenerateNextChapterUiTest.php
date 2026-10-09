<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\NovelStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Jobs\PlanChapterJob;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\StoryArc;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the chapter start modal exposes exactly three automation modes', function () {
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'settings' => ['auto_commit' => true],
    ]);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->mountAction('startAutoGenerate')
        ->assertActionDataSet(['automation_mode' => 'review_only'])
        ->assertMountedActionModalSee([
            '当前章生成到审校',
            '连续生成 · 每章 PASS 后人工确认提交',
            '全自动连续生成',
        ]);
});

test('the novel overview starts each explicit automation mode and opens the chapter workbench', function (string $mode, bool $autoGenerate, bool $autoCommit, string $notification) {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    NovelBible::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    StoryArc::factory()->forVolume($volume)->create(['status' => 'active']);

    $component = Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertActionEnabled('startAutoGenerate')
        ->callAction('startAutoGenerate', data: ['automation_mode' => $mode])
        ->assertNotified($notification);

    $chapter = $novel->chapters()->sole();
    $novel->refresh();

    // 三种模式只映射既有布尔配置，不创建新的运行状态来源。
    expect(data_get($novel->settings, 'auto_generate'))->toBe($autoGenerate)
        ->and(data_get($novel->settings, 'auto_commit'))->toBe($autoCommit)
        ->and(data_get($novel->settings, 'auto_commit_configured'))->toBeTrue();
    $component->assertRedirect(NovelResource::getUrl('chapter', [
        'record' => $novel,
        'chapter' => $chapter,
    ]));
    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $chapter->getKey());
})->with([
    '当前章生成到审校' => ['review_only', false, false, '当前章生成到审校已启动'],
    '连续生成逐章确认' => ['continuous_manual', true, false, '连续生成 · 逐章确认已启动'],
    '全自动连续生成' => ['full_auto', true, true, '全自动连续生成已启动'],
]);

test('the novel overview displays a specific preflight failure reason', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->callAction('startAutoGenerate', data: ['automation_mode' => 'review_only'])
        ->assertNotified('无法启动章节生成');

    expect($novel->chapters()->count())->toBe(0);
});

test('automatic generation stays off when the current chapter cannot start', function () {
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'settings' => ['auto_generate' => false],
    ]);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->callAction('startAutoGenerate', data: ['automation_mode' => 'full_auto'])
        ->assertNotified('无法启动章节生成');

    expect(data_get($novel->fresh()->settings, 'auto_generate'))->toBeFalse()
        ->and(data_get($novel->settings, 'auto_commit'))->toBeNull()
        ->and($novel->chapters()->count())->toBe(0);
});
