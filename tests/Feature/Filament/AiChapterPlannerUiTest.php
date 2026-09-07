<?php

use App\Enums\RunStatus;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Bus::fake();
});

test('chapter detail queues ai plan generation', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();

    Livewire::test(ViewNovelChapter::class, ['record' => $novel->getRouteKey(), 'chapter' => $chapter->getRouteKey()])
        ->assertActionVisible('generatePlan')
        ->assertActionHidden('regeneratePlan')
        ->callAction('generatePlan')
        ->assertNotified('Chapter Plan 已加入生成队列');

    Bus::assertDispatched(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $chapter->getKey() && ! $job->regenerate);
});

test('chapter detail queues explicit plan regeneration and shows run status', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    ChapterPlan::factory()->for($chapter)->create();
    GenerationRun::factory()->for($novel)->for($chapter)->create([
        'attempt' => 2,
        'prompt_version' => 'chapter-planner-v1',
        'status' => RunStatus::Succeeded,
    ]);

    Livewire::test(ViewNovelChapter::class, ['record' => $novel->getRouteKey(), 'chapter' => $chapter->getRouteKey()])
        ->assertActionHidden('generatePlan')
        ->assertActionVisible('regeneratePlan')
        ->assertSee('chapter-planner-v1')
        ->callAction('regeneratePlan')
        ->assertNotified('Chapter Plan 重新生成已排队');

    Bus::assertDispatched(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $chapter->getKey() && $job->regenerate);
});
