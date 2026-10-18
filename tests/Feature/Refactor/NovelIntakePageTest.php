<?php

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\EditNovel;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Jobs\ExecuteGenerationRunJob;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('create form needs only premise and creates one durable book task', function () {
    $this->actingAs(User::factory()->create());
    Queue::fake([ExecuteGenerationRunJob::class]);
    Livewire::test(CreateNovel::class)->assertSee('故事概述')->assertSee('默认统计用量与费用')->fillForm(['premise' => '旧港邮差收到一封未来的来信。'])->call('create')->assertHasNoFormErrors();
    expect(Novel::count())->toBe(1)->and(NovelGenerationTask::count())->toBe(1);
    Queue::assertPushed(ExecuteGenerationRunJob::class);
});

test('save draft pauses without dispatch and can show current task', function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();
    Livewire::test(CreateNovel::class)->fillForm(['premise' => '只有一名主角的故事。'])->callAction(TestAction::make('saveDraft')->schemaComponent('form-actions', 'content'))->assertHasNoFormErrors();
    $task = NovelGenerationTask::firstOrFail();
    expect($task->status)->toBe('paused')->and($task->stop_reason)->toBe('draft_saved');
    Queue::assertNothingPushed();
    Livewire::test(ViewNovel::class, ['record' => $task->novel_id])->assertOk()->assertSee('原始创作概述')->assertSee('已暂停')->assertActionExists('book_resume');
});

test('invalid input remains in form and creates no novel', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test(CreateNovel::class)->fillForm(['premise' => '港城故事', 'target_words' => 0])->call('create')->assertHasFormErrors(['target_words'])->assertSet('data.premise', '港城故事');
    expect(Novel::count())->toBe(0);
});

test('edit explicitly confirms impact and keeps hard cap opt in', function () {
    $this->actingAs(User::factory()->create());
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '原来的港城故事', ['save_draft' => true]);
    Livewire::test(EditNovel::class, ['record' => $task->novel_id])->fillForm(['premise' => '新的山城故事', 'confirm_impact' => true, 'budget_limits' => ['warning_amount' => 1]])->call('save')->assertHasNoFormErrors();
    expect($task->novel->fresh()->premise)->toBe('新的山城故事')->and(data_get($task->novel->fresh()->settings, 'budget.novel_total_limit_enabled'))->toBeFalse();
});
