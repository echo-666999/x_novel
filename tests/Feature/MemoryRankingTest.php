<?php

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingResponse;
use App\AI\Providers\FakeEmbeddingProvider;
use App\Data\MemoryQuery;
use App\Data\MemorySearchResult;
use App\Enums\MemoryType;
use App\Models\Memory;
use App\Models\Novel;
use App\Services\MemoryRanker;
use App\Services\MemoryRetriever;
use App\Services\TokenBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.embedding.model', 'embedding-fixed-v1');
    config()->set('ai.embedding.dimensions', 3);
    config()->set('context.memory_candidate_k', 10);
    config()->set('context.memory_final_k', 3);
    config()->set('context.memory_semantic_dedup_threshold', 0.98);
});

test('ranking combines similarity salience recency and entity match', function () {
    $novel = Novel::factory()->create();
    $olderImportant = rankingMemory($novel, '早期关键秘密', '[0.8,0.2,0]', [
        'salience' => 1.0,
        'valid_from_chapter' => 1,
        'entities' => ['characters' => ['7']],
    ]);
    $recentOrdinary = rankingMemory($novel, '近期普通对话', '[0.9,0.1,0]', [
        'salience' => 0.4,
        'valid_from_chapter' => 100,
        'entities' => ['characters' => ['8']],
    ]);
    $query = new MemoryQuery(
        novelId: $novel->getKey(),
        queryText: '角色七的秘密',
        entityIds: ['characters' => ['7']],
        chapterTo: 100,
        finalK: 2,
        tokenBudget: 1_000,
    );

    $ranked = app(MemoryRanker::class)->rank(collect([
        new MemorySearchResult($olderImportant, 0.8),
        new MemorySearchResult($recentOrdinary, 0.9),
    ]), $query);

    expect($ranked->first()->memory->is($olderImportant))->toBeTrue()
        ->and($ranked->first()->entityMatchScore)->toBe(1.0)
        ->and($ranked->first()->finalScore)->toBeGreaterThan($ranked->last()->finalScore)
        ->and($ranked->every(fn (MemorySearchResult $result): bool => $result->selected))->toBeTrue();
});

test('same source and semantic duplicates cannot fill top k', function () {
    $novel = Novel::factory()->create();
    $first = rankingMemory($novel, '主角取得潮汐密钥', '[1,0,0]', [
        'source_id' => 10,
        'entities' => ['characters' => ['7']],
    ]);
    $sameSource = rankingMemory($novel, '同一事件的另一摘要', '[0.7,0.3,0]', [
        'type' => MemoryType::Relationship,
        'source_id' => 10,
        'entities' => ['characters' => ['7']],
    ]);
    $semanticDuplicate = rankingMemory($novel, '主角获得了潮汐密钥', '[0.999,0.001,0]', [
        'source_id' => 11,
        'entities' => ['characters' => ['7']],
    ]);
    $distinct = rankingMemory($novel, '王城议会宣布休战', '[0,1,0]', [
        'source_id' => 12,
        'entities' => ['characters' => ['8']],
    ]);
    $query = new MemoryQuery(
        novelId: $novel->getKey(), queryText: '历史', chapterTo: 20, finalK: 3, tokenBudget: 1_000,
    );

    $ranked = app(MemoryRanker::class)->rank(collect([
        new MemorySearchResult($first, 1.0),
        new MemorySearchResult($sameSource, 0.9),
        new MemorySearchResult($semanticDuplicate, 0.99),
        new MemorySearchResult($distinct, 0.5),
    ]), $query);

    expect($ranked->where('selected', true)->pluck('memory.id')->all())
        ->toContain($first->getKey(), $distinct->getKey())
        ->not->toContain($sameSource->getKey(), $semanticDuplicate->getKey())
        ->and($ranked->firstWhere('memory.id', $sameSource->getKey())->reason)->toContain('来源重复')
        ->and($ranked->firstWhere('memory.id', $semanticDuplicate->getKey())->reason)->toContain('语义重复');
});

test('token assembly rejects lower ranked memories after the l3 budget is exhausted', function () {
    $novel = Novel::factory()->create();
    $first = rankingMemory($novel, str_repeat('关键记忆', 20), '[1,0,0]');
    $second = rankingMemory($novel, str_repeat('次要记忆', 20), '[0,1,0]');
    $budget = app(TokenBudget::class)->estimate(['summary' => $first->summary, 'source' => $first->sourceLabel()]);
    $query = new MemoryQuery(
        novelId: $novel->getKey(), queryText: '历史', chapterTo: 20, finalK: 2, tokenBudget: $budget,
    );

    $ranked = app(MemoryRanker::class)->rank(collect([
        new MemorySearchResult($first, 1.0),
        new MemorySearchResult($second, 0.8),
    ]), $query);

    expect($ranked->where('selected', true))->toHaveCount(1)
        ->and($ranked->where('selected', false))->toHaveCount(1)
        ->and($ranked->where('selected', false)->first()->reason)->toContain('Token Budget');
});

test('retriever returns both selected and rejected candidates with explainable decisions', function () {
    $novel = Novel::factory()->create();
    rankingMemory($novel, '同源高分摘要', '[1,0,0]', ['source_id' => 20]);
    rankingMemory($novel, '同源低分摘要', '[0.9,0.1,0]', [
        'type' => MemoryType::World,
        'source_id' => 20,
    ]);
    app()->instance(EmbeddingProvider::class, (new FakeEmbeddingProvider)->enqueue(new EmbeddingResponse(
        embedding: [1.0, 0.0, 0.0], inputTokens: 2, latencyMs: 3, providerRequestId: null, model: 'embedding-fixed-v1',
    )));

    $ranked = app(MemoryRetriever::class)->retrieve(new MemoryQuery(
        novelId: $novel->getKey(), queryText: '同源', candidateK: 5, finalK: 2, tokenBudget: 1_000,
    ));

    expect($ranked)->toHaveCount(2)
        ->and($ranked->where('selected', true))->toHaveCount(1)
        ->and($ranked->where('selected', false)->first()->reason)->toContain('来源重复');
});

/** @param array<string, mixed> $attributes */
function rankingMemory(Novel $novel, string $summary, string $embedding, array $attributes = []): Memory
{
    return Memory::factory()->for($novel)->create(array_merge([
        'summary' => $summary,
        'embedding' => $embedding,
        'embedding_model' => 'embedding-fixed-v1',
        'valid_from_chapter' => 10,
        'salience' => 0.7,
    ], $attributes));
}
