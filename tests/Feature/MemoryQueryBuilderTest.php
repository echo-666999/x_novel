<?php

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeEmbeddingProvider;
use App\Data\MemoryQuery;
use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Models\Memory;
use App\Models\Novel;
use App\Services\MemoryQueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException as QueryInvalidArgumentException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.embedding.model', 'embedding-fixed-v1');
    config()->set('ai.embedding.dimensions', 3);
    config()->set('context.memory_candidate_k', 3);
    config()->set('context.memory_final_k', 2);
});

test('vector retrieval returns similarity ordered active memories from only the requested novel and model', function () {
    $novel = Novel::factory()->create();
    $otherNovel = Novel::factory()->create();
    $best = vectorMemory($novel, '最相关记忆', '[1,0,0]');
    $second = vectorMemory($novel, '次相关记忆', '[0.8,0.2,0]');
    vectorMemory($novel, '反向记忆', '[-1,0,0]');
    vectorMemory($novel, '失效记忆', '[1,0,0]', ['status' => MemoryStatus::Invalid]);
    vectorMemory($novel, '其他模型', '[1,0,0]', ['embedding_model' => 'embedding-other']);
    vectorMemory($otherNovel, '其他小说', '[1,0,0]');
    bindQueryEmbedding([1.0, 0.0, 0.0]);

    $results = app(MemoryQueryBuilder::class)->search(new MemoryQuery(
        novelId: $novel->getKey(),
        queryText: '寻找相关记忆',
    ));

    expect($results)->toHaveCount(2)
        ->and($results->pluck('memory.id')->all())->toBe([$best->getKey(), $second->getKey()])
        ->and($results->first()->similarity)->toBeGreaterThan($results->last()->similarity);
});

test('metadata filters apply before vector candidate selection', function () {
    $novel = Novel::factory()->create();
    $selected = vectorMemory($novel, '角色在旧城获得密钥', '[0.9,0.1,0]', [
        'type' => MemoryType::CharacterMilestone,
        'entities' => ['characters' => ['7']],
        'valid_from_chapter' => 20,
    ]);
    vectorMemory($novel, '同人物但过早', '[1,0,0]', [
        'type' => MemoryType::CharacterMilestone,
        'entities' => ['characters' => ['7']],
        'valid_from_chapter' => 5,
        'valid_to_chapter' => 9,
    ]);
    vectorMemory($novel, '章节匹配但人物不同', '[1,0,0]', [
        'type' => MemoryType::CharacterMilestone,
        'entities' => ['characters' => ['8']],
        'valid_from_chapter' => 20,
    ]);
    vectorMemory($novel, '类型不同', '[1,0,0]', [
        'type' => MemoryType::World,
        'entities' => ['characters' => ['7']],
        'valid_from_chapter' => 20,
    ]);
    bindQueryEmbedding([1.0, 0.0, 0.0]);

    $results = app(MemoryQueryBuilder::class)->search(new MemoryQuery(
        novelId: $novel->getKey(),
        queryText: '角色密钥',
        types: [MemoryType::CharacterMilestone],
        entityIds: ['characters' => ['7']],
        chapterFrom: 10,
        chapterTo: 30,
        candidateK: 5,
        finalK: 3,
    ));

    expect($results)->toHaveCount(1)
        ->and($results->first()->memory->is($selected))->toBeTrue();
});

test('excluded ids and configured top k are enforced', function () {
    $novel = Novel::factory()->create();
    $excluded = vectorMemory($novel, '应排除', '[1,0,0]');
    $selected = vectorMemory($novel, '应返回', '[0.9,0.1,0]');
    vectorMemory($novel, '超出 final k', '[0.8,0.2,0]');
    bindQueryEmbedding([1.0, 0.0, 0.0]);

    $results = app(MemoryQueryBuilder::class)->search(new MemoryQuery(
        novelId: $novel->getKey(),
        queryText: '检索',
        excludeIds: [$excluded->getKey()],
        candidateK: 2,
        finalK: 1,
    ));

    expect($results)->toHaveCount(1)
        ->and($results->first()->memory->is($selected))->toBeTrue();
});

test('retrieval rejects invalid top k and query embedding dimensions', function () {
    $novel = Novel::factory()->create();
    bindQueryEmbedding([1.0, 0.0, 0.0]);

    expect(fn () => app(MemoryQueryBuilder::class)->search(new MemoryQuery(
        novelId: $novel->getKey(), queryText: '检索', candidateK: 2, finalK: 3,
    )))->toThrow(QueryInvalidArgumentException::class, 'candidate_k');

    bindQueryEmbedding([1.0, 0.0]);

    expect(fn () => app(MemoryQueryBuilder::class)->search(new MemoryQuery(
        novelId: $novel->getKey(), queryText: '检索',
    )))->toThrow(AiProviderException::class, '模型或维度');
});

/** @param array<string, mixed> $attributes */
function vectorMemory(Novel $novel, string $summary, string $embedding, array $attributes = []): Memory
{
    return Memory::factory()->for($novel)->create(array_merge([
        'summary' => $summary,
        'embedding' => $embedding,
        'embedding_model' => 'embedding-fixed-v1',
    ], $attributes));
}

/** @param array<int, float> $embedding */
function bindQueryEmbedding(array $embedding): FakeEmbeddingProvider
{
    $provider = (new FakeEmbeddingProvider)->enqueue(new EmbeddingResponse(
        embedding: $embedding,
        inputTokens: 3,
        latencyMs: 5,
        providerRequestId: null,
        model: 'embedding-fixed-v1',
    ));
    app()->instance(EmbeddingProvider::class, $provider);

    return $provider;
}
