<?php

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\EditNovel;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Jobs\ExecuteGenerationRunJob;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\UsageRecord;
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

// 控制动作的同一次响应必须更新按钮，不能要求用户刷新才能继续。
test('book controls immediately show the next valid action', function (bool $draft, string $action, string $nextAction, string $status, string $label, int $dispatched) {
    $this->actingAs(User::factory()->create());
    Queue::fake([ExecuteGenerationRunJob::class]);
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '港城邮差追查未来来信。', ['save_draft' => $draft]);

    Livewire::test(ViewNovel::class, ['record' => $task->novel_id])
        ->callAction($action)
        ->assertActionHidden($action)
        ->assertActionVisible($nextAction)
        ->assertSee($label);

    expect($task->fresh()->status)->toBe($status);
    Queue::assertPushedTimes(ExecuteGenerationRunJob::class, $dispatched);
})->with([
    '暂停后可继续' => [false, 'book_pause', 'book_resume', 'paused', '继续生成', 0],
    '继续后可暂停' => [true, 'book_resume', 'book_pause', 'running', '暂停', 1],
]);

test('edit explicitly confirms impact and keeps hard cap opt in', function () {
    $this->actingAs(User::factory()->create());
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '原来的港城故事', ['save_draft' => true]);
    Livewire::test(EditNovel::class, ['record' => $task->novel_id])->fillForm(['premise' => '新的山城故事', 'confirm_impact' => true, 'budget_limits' => ['warning_amount' => 1]])->call('save')->assertHasNoFormErrors();
    expect($task->novel->fresh()->premise)->toBe('新的山城故事')->and(data_get($task->novel->fresh()->settings, 'budget.novel_total_limit_enabled'))->toBeFalse();
});

test('book cost warning uses current task known costs without changing control state', function (?int $local, bool $reached, string $source) {
    $this->actingAs(User::factory()->create());
    Queue::fake();
    setGenerationSettingsForTest(['currency' => 'USD', 'budget.warning_amount' => 2]);
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '港城邮差追查未来来信。', ['save_draft' => true, 'budget_limits' => ['warning_amount' => $local]]);
    $before = $task->getAttributes();
    foreach ([
        ['currency' => 'USD', 'actual_cost' => 1, 'estimated_cost' => 90, 'status' => 'settled'],
        ['currency' => 'USD', 'actual_cost' => null, 'estimated_cost' => 1, 'status' => 'settled'],
        ['currency' => 'USD', 'actual_cost' => null, 'estimated_cost' => null, 'status' => 'unknown'],
        ['currency' => 'EUR', 'actual_cost' => 100, 'estimated_cost' => 100, 'status' => 'settled'],
        ['currency' => 'JPY', 'actual_cost' => null, 'estimated_cost' => null, 'status' => 'unknown'],
        ['currency' => 'USD', 'actual_cost' => null, 'estimated_cost' => 100, 'status' => 'prepared'],
        ['currency' => 'USD', 'actual_cost' => null, 'estimated_cost' => 100, 'status' => 'not_sent'],
    ] as $usage) {
        UsageRecord::factory()->create($usage + ['generation_task_id' => $task->id, 'novel_id' => $task->novel_id]);
    }
    UsageRecord::factory()->create(['novel_id' => $task->novel_id, 'generation_task_id' => null, 'currency' => 'USD', 'status' => 'settled', 'actual_cost' => 100]);
    $page = Livewire::test(ViewNovel::class, ['record' => $task->novel_id])->assertOk()
        ->assertSee('已知累计 2.0000')->assertSee('已知累计 100.0000')->assertSee('尚无已知金额')
        ->assertSee('未确认费用 1 笔')->assertSee($source);
    if ($reached) {
        $page->assertSee('已达费用预警');
    } else {
        $page->assertDontSee('已达费用预警');
    }
    expect($task->fresh()->getAttributes())->toBe($before);
    Queue::assertNothingPushed();
})->with([
    'global default reached' => [null, true, '全局默认'],
    'novel override below threshold' => [3, false, '小说设置'],
    'novel threshold reached exactly' => [2, true, '小说设置'],
]);
