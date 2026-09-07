<?php

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingResponse;
use App\AI\Providers\FakeEmbeddingProvider;
use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Models\Memory;
use App\Models\Novel;
use App\Services\MemoryRetrievalEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('fixed retrieval evaluation measures hits and blocks invalid and cross novel leakage', function () {
    config()->set('ai.embedding.model', 'embedding-fixed-v1');
    config()->set('ai.embedding.dimensions', 3);
    $novel = Novel::factory()->create();
    evaluationMemory($novel, MemoryType::Event, '早期关键事实');
    evaluationMemory($novel, MemoryType::Foreshadowing, '早期伏笔');
    evaluationMemory($novel, MemoryType::CharacterMilestone, '人物尚不知道密令');
    evaluationMemory($novel, MemoryType::Item, '王室密钥属于主角');
    evaluationMemory($novel, MemoryType::Event, '已经回滚的错误剧情', MemoryStatus::Invalid);
    evaluationMemory(Novel::factory()->create(), MemoryType::Event, '其他小说的同名角色');
    $provider = new FakeEmbeddingProvider;

    foreach (range(1, 6) as $_) {
        $provider->enqueue(new EmbeddingResponse([1.0, 0.0, 0.0], 3, 1, null, 'embedding-fixed-v1'));
    }

    app()->instance(EmbeddingProvider::class, $provider);
    $results = app(MemoryRetrievalEvaluator::class)->evaluate($novel->getKey());

    expect($results)->toHaveCount(6)
        ->and(collect($results)->pluck('case')->all())->toBe([
            '早期关键事实', '早期伏笔', '人物知识边界', '物品归属', '失效记忆', '其他小说记忆',
        ])
        ->and(collect($results)->take(4)->every(fn (array $result): bool => $result['hit'] === '是' && $result['status'] === '通过'))->toBeTrue()
        ->and($results[4]['leakage'])->toBe('否')
        ->and($results[5]['leakage'])->toBe('否');
});

test('fixed retrieval evaluation marks missing samples instead of reporting false passes', function () {
    $results = app(MemoryRetrievalEvaluator::class)->evaluate(Novel::factory()->create()->getKey());

    expect($results)->toHaveCount(6)
        ->and(collect($results)->every(fn (array $result): bool => $result['status'] === '未配置'))->toBeTrue();
});

function evaluationMemory(Novel $novel, MemoryType $type, string $summary, MemoryStatus $status = MemoryStatus::Active): Memory
{
    return Memory::factory()->for($novel)->create([
        'type' => $type,
        'summary' => $summary,
        'status' => $status,
        'embedding' => '[1,0,0]',
        'embedding_model' => 'embedding-fixed-v1',
    ]);
}
