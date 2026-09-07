<?php

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingResponse;
use App\AI\Providers\FakeEmbeddingProvider;
use App\Enums\GenerationStage;
use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Enums\RunStatus;
use App\Filament\Pages\Memory;
use App\Jobs\GenerateEmbeddingJob;
use App\Models\GenerationRun;
use App\Models\Memory as MemoryModel;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

test('memory page shows failed embeddings and can queue a retry', function () {
    Queue::fake();
    $novel = Novel::factory()->create();
    $memory = MemoryModel::factory()->for($novel)->create();
    GenerationRun::factory()->for($novel)->create([
        'scope_type' => 'memory',
        'scope_id' => $memory->getKey(),
        'stage' => GenerationStage::Embedding,
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
    ]);

    Livewire::test(Memory::class)
        ->assertSee('生成失败')
        ->assertTableActionVisible('retryEmbedding', $memory)
        ->callTableAction('retryEmbedding', $memory)
        ->assertNotified('向量化任务已重新排队');

    Queue::assertPushed(GenerateEmbeddingJob::class, fn (GenerateEmbeddingJob $job): bool => $job->memoryId === $memory->getKey());
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

test('memory inspector shows query filters and top vector results in chinese', function () {
    config()->set('ai.embedding.model', 'embedding-fixed-v1');
    config()->set('ai.embedding.dimensions', 3);
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    MemoryModel::factory()->for($novel)->create([
        'summary' => '潮汐门曾在月落时开启。',
        'embedding' => '[1,0,0]',
        'embedding_model' => 'embedding-fixed-v1',
    ]);
    app()->instance(EmbeddingProvider::class, (new FakeEmbeddingProvider)->enqueue(new EmbeddingResponse(
        embedding: [1.0, 0.0, 0.0],
        inputTokens: 4,
        latencyMs: 5,
        providerRequestId: null,
        model: 'embedding-fixed-v1',
    )));

    Livewire::test(Memory::class)
        ->assertSee('记忆检索检查器')
        ->assertSee('查询内容')
        ->set('retrieval.novel_id', $novel->getKey())
        ->set('retrieval.query', '潮汐门何时开启')
        ->set('retrieval.candidate_k', 5)
        ->set('retrieval.final_k', 2)
        ->call('runRetrieval')
        ->assertSet('retrievalResults.0.summary', '潮汐门曾在月落时开启。')
        ->assertSee('最相似结果')
        ->assertSee('相似度')
        ->assertSee('显著度')
        ->assertNotified('记忆检索完成');
});
