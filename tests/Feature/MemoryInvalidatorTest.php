<?php

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Providers\FakeEmbeddingProvider;
use App\Data\MemoryQuery;
use App\Enums\MemoryStatus;
use App\Models\Chapter;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Services\MemoryInvalidator;
use App\Services\MemoryQueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('chapter rollback invalidates every memory derived from that chapter without deleting it', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 12]);
    $otherChapter = Chapter::factory()->for($novel)->create(['sequence' => 11]);
    $event = StoryEvent::factory()->for($novel)->for($chapter)->create();
    $otherEvent = StoryEvent::factory()->for($novel)->for($otherChapter)->create();
    $stateVersion = StoryStateVersion::factory()->for($novel)->for($chapter)->create();

    $fromChapter = rollbackMemory($novel, 'chapter', $chapter->getKey());
    $fromEvent = rollbackMemory($novel, 'story_event', $event->getKey());
    $fromState = rollbackMemory($novel, 'story_state_version', $stateVersion->getKey());
    $unrelated = rollbackMemory($novel, 'story_event', $otherEvent->getKey());

    $affected = app(MemoryInvalidator::class)->invalidateForChapter($chapter->getKey());

    expect($affected)->toBe(3)
        ->and($fromChapter->fresh()->status)->toBe(MemoryStatus::Invalid)
        ->and($fromEvent->fresh()->status)->toBe(MemoryStatus::Invalid)
        ->and($fromState->fresh()->status)->toBe(MemoryStatus::Invalid)
        ->and($unrelated->fresh()->status)->toBe(MemoryStatus::Active)
        ->and(Memory::query()->count())->toBe(4);
});

test('memory invalidation is novel scoped and idempotent', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $event = StoryEvent::factory()->for($novel)->for($chapter)->create();
    $memory = rollbackMemory($novel, 'story_event', $event->getKey());
    $otherNovel = Novel::factory()->create();
    $foreign = rollbackMemory($otherNovel, 'story_event', $event->getKey());

    $invalidator = app(MemoryInvalidator::class);

    expect($invalidator->invalidateForChapter($chapter->getKey()))->toBe(1)
        ->and($invalidator->invalidateForChapter($chapter->getKey()))->toBe(0)
        ->and($memory->fresh()->status)->toBe(MemoryStatus::Invalid)
        ->and($foreign->fresh()->status)->toBe(MemoryStatus::Active);
});

test('invalidated rollback memory cannot enter vector retrieval', function () {
    config()->set('ai.embedding.model', 'embedding-fixed-v1');
    config()->set('ai.embedding.dimensions', 3);
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $event = StoryEvent::factory()->for($novel)->for($chapter)->create();
    rollbackMemory($novel, 'story_event', $event->getKey(), [
        'embedding' => '[1,0,0]',
        'embedding_model' => 'embedding-fixed-v1',
    ]);
    app(MemoryInvalidator::class)->invalidateForChapter($chapter->getKey());
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);

    $results = app(MemoryQueryBuilder::class)->search(new MemoryQuery(
        novelId: $novel->getKey(),
        queryText: '回滚剧情',
    ));

    expect($results)->toBeEmpty();
});

/** @param array<string, mixed> $attributes */
function rollbackMemory(Novel $novel, string $sourceType, int $sourceId, array $attributes = []): Memory
{
    return Memory::factory()->for($novel)->create(array_merge([
        'source_type' => $sourceType,
        'source_id' => $sourceId,
    ], $attributes));
}
