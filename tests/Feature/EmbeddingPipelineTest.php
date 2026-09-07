<?php

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeEmbeddingProvider;
use App\AI\Providers\TrackingEmbeddingProvider;
use App\AI\UsageRecorder;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Jobs\GenerateEmbeddingJob;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\UsageRecord;
use App\Services\MemoryEmbedder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.embedding.model', 'embedding-fixed-v1');
    config()->set('ai.embedding.dimensions', 3);
});

test('memory embedder stores the fixed model and vector', function () {
    $memory = Memory::factory()->for(Novel::factory())->create(['summary' => '灯塔钟声将在第三卷回收。']);
    $provider = (new FakeEmbeddingProvider)->enqueue(embeddingResponse());
    app()->instance(EmbeddingProvider::class, $provider);

    $result = app(MemoryEmbedder::class)->embed($memory->getKey());
    $run = GenerationRun::query()->where('stage', GenerationStage::Embedding)->sole();

    expect($result->embedding)->not->toBeNull()
        ->and($result->embedding_model)->toBe('embedding-fixed-v1')
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->idempotency_key)->toBe("embedding:{$memory->getKey()}:embedding-fixed-v1")
        ->and($run->context_snapshot['embedding_dimensions'])->toBe(3)
        ->and($provider->requests())->toHaveCount(1)
        ->and($provider->requests()[0]->input)->toBe('灯塔钟声将在第三卷回收。')
        ->and($provider->requests()[0]->dimensions)->toBe(3);
});

test('tracked embedding requests write provider usage for the memory run', function () {
    $novel = Novel::factory()->create();
    $memory = Memory::factory()->for($novel)->create();
    $fake = (new FakeEmbeddingProvider)->enqueue(embeddingResponse());
    app()->instance(EmbeddingProvider::class, new TrackingEmbeddingProvider($fake, app(UsageRecorder::class)));

    app(MemoryEmbedder::class)->embed($memory->getKey());

    $run = GenerationRun::query()->where('stage', GenerationStage::Embedding)->sole();
    $usage = UsageRecord::query()->sole();

    expect($usage->generation_run_id)->toBe($run->getKey())
        ->and($usage->novel_id)->toBe($novel->getKey())
        ->and($usage->model)->toBe('embedding-fixed-v1')
        ->and($usage->input_tokens)->toBe(8)
        ->and($usage->output_tokens)->toBe(0);
});

test('duplicate embedding delivery reuses the successful result without another provider request', function () {
    $memory = Memory::factory()->create();
    $provider = (new FakeEmbeddingProvider)->enqueue(embeddingResponse());
    app()->instance(EmbeddingProvider::class, $provider);

    app(MemoryEmbedder::class)->embed($memory->getKey());
    app(MemoryEmbedder::class)->embed($memory->getKey());

    expect($provider->requests())->toHaveCount(1)
        ->and(GenerationRun::query()->where('stage', GenerationStage::Embedding)->count())->toBe(1);
});

test('duplicate delivery does not call the provider while the same embedding run is active', function () {
    $memory = Memory::factory()->create();
    GenerationRun::factory()->for($memory->novel)->create([
        'scope_type' => 'memory',
        'scope_id' => $memory->getKey(),
        'stage' => GenerationStage::Embedding,
        'status' => RunStatus::Running,
        'idempotency_key' => "embedding:{$memory->getKey()}:embedding-fixed-v1",
        'input_hash' => hash('sha256', $memory->summary."\0embedding-fixed-v1\0".'3'),
    ]);
    $provider = new FakeEmbeddingProvider;
    app()->instance(EmbeddingProvider::class, $provider);

    app(MemoryEmbedder::class)->embed($memory->getKey());

    expect($provider->requests())->toBeEmpty()
        ->and(GenerationRun::query()->where('stage', GenerationStage::Embedding)->count())->toBe(1);
});

test('retryable embedding failure keeps memory pending and can recover', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 1,
        'status' => ChapterStatus::Canonical,
    ]);
    $memory = Memory::factory()->for($novel)->create(['valid_from_chapter' => 1]);
    $provider = (new FakeEmbeddingProvider)
        ->enqueue(new AiProviderException('provider_timeout', 'timeout', true))
        ->enqueue(embeddingResponse());
    app()->instance(EmbeddingProvider::class, $provider);

    expect(fn () => app(MemoryEmbedder::class)->embed($memory->getKey()))
        ->toThrow(AiProviderException::class, 'timeout');

    expect($memory->fresh()->embedding)->toBeNull()
        ->and($chapter->fresh()->status)->toBe(ChapterStatus::Canonical)
        ->and(GenerationRun::query()->where('stage', GenerationStage::Embedding)->sole()->status)->toBe(RunStatus::Failed);

    app(MemoryEmbedder::class)->embed($memory->getKey());

    expect($memory->fresh()->hasEmbedding())->toBeTrue()
        ->and(GenerationRun::query()->where('stage', GenerationStage::Embedding)->sole()->attempt)->toBe(2);
});

test('dimension mismatch is terminal and does not modify memory', function () {
    $memory = Memory::factory()->create();
    $provider = (new FakeEmbeddingProvider)->enqueue(new EmbeddingResponse(
        embedding: [0.1, 0.2],
        inputTokens: 2,
        latencyMs: 5,
        providerRequestId: 'bad-dimensions',
        model: 'embedding-fixed-v1',
    ));
    app()->instance(EmbeddingProvider::class, $provider);

    expect(fn () => app(MemoryEmbedder::class)->embed($memory->getKey()))
        ->toThrow(AiProviderException::class, '向量维度');

    expect($memory->fresh()->embedding)->toBeNull()
        ->and(GenerationRun::query()->where('stage', GenerationStage::Embedding)->sole()->error_code)
        ->toBe('embedding_dimensions_mismatch');
});

test('embedding job uses the default queue and retries recoverable failures', function () {
    $job = new GenerateEmbeddingJob(123);

    expect($job->queue)->toBe('default')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 30]);
});

function embeddingResponse(): EmbeddingResponse
{
    return new EmbeddingResponse(
        embedding: [0.1, -0.2, 0.3],
        inputTokens: 8,
        latencyMs: 12,
        providerRequestId: 'embedding-request-1',
        model: 'embedding-fixed-v1',
    );
}
