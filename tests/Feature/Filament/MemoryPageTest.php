<?php

use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Filament\Pages\Memory;
use App\Models\Memory as MemoryModel;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('memory page shows active memories and their retrieval metadata by default', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $active = MemoryModel::factory()->for($novel)->create([
        'type' => MemoryType::Foreshadowing,
        'summary' => '沉没灯塔的钟声将在第三卷回收。',
        'salience' => 0.950,
        'embedding' => '[0.1,0.2]',
        'embedding_model' => 'embedding-test',
    ]);
    $invalid = MemoryModel::factory()->for($novel)->create([
        'summary' => '已失效的错误记忆。',
        'status' => MemoryStatus::Invalid,
    ]);

    Livewire::test(Memory::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$invalid])
        ->assertSee('雾海长明')
        ->assertSee('伏笔')
        ->assertSee('沉没灯塔的钟声将在第三卷回收。')
        ->assertSee('故事事件 #')
        ->assertSee('已就绪')
        ->assertSee('embedding-test');
});

test('memory page can explicitly inspect invalid memories', function () {
    $novel = Novel::factory()->create();
    $active = MemoryModel::factory()->for($novel)->create();
    $invalid = MemoryModel::factory()->for($novel)->create([
        'summary' => '回滚后失效的记忆。',
        'status' => MemoryStatus::Invalid,
    ]);

    Livewire::test(Memory::class)
        ->filterTable('status', MemoryStatus::Invalid->value)
        ->assertCanSeeTableRecords([$invalid])
        ->assertCanNotSeeTableRecords([$active])
        ->assertSee('回滚后失效的记忆。');
});
