<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\UsageRecorder;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Jobs\GenerateCanonicalChapterSummaryJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\UsageRecord;
use App\Services\CanonicalChapterSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('a non-canonical chapter is rejected before the provider is called', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Review]);
    $fake = (new FakeAiProvider)->enqueue(summaryResponse());
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(CanonicalChapterSummaryService::class)->generate($chapter->getKey()))
        ->toThrow(ValidationException::class);
    expect($fake->requests())->toBeEmpty()
        ->and(GenerationRun::query()->count())->toBe(0);
});

test('a successful canonical summary persists its run artifact usage and input hash', function () {
    [$chapter] = canonicalSummaryChapter();
    $fake = (new FakeAiProvider)->enqueue(summaryResponse());
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));

    $result = app(CanonicalChapterSummaryService::class)->generate($chapter->getKey());
    $run = GenerationRun::query()->where('scope_type', 'chapter_summary')->sole();

    expect($result['reused'])->toBeFalse()
        ->and($result['applied'])->toBeTrue()
        ->and($chapter->fresh()->summary)->toBe('林舟取得航海图，却发现灯塔仍在异常运转。')
        ->and($run->stage)->toBe(GenerationStage::MemorySummary)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->input_hash)->toHaveLength(64)
        ->and($run->prompt_version)->toBe('summary-v2+natural-prose-v1')
        ->and(data_get($run->context_snapshot, 'prompt_version'))->toBe('summary-v2+natural-prose-v1')
        ->and($fake->requests()[0]->systemPrompt)->toContain('不得评价文笔、解释主题')
        ->and($result['artifact']->type)->toBe(ArtifactType::Summary)
        ->and(data_get($result['artifact']->data, 'source_canonical_artifact_id'))->toBe($chapter->canonical_artifact_id)
        ->and(UsageRecord::query()->where('generation_run_id', $run->getKey())->count())->toBe(1);
});

test('the same canonical checksum reuses the successful summary without another provider call', function () {
    [$chapter] = canonicalSummaryChapter();
    $fake = (new FakeAiProvider)->enqueue(summaryResponse());
    app()->instance(AiProvider::class, $fake);
    $service = app(CanonicalChapterSummaryService::class);

    $first = $service->generate($chapter->getKey());
    $chapter->update(['summary' => null]);
    $second = $service->generate($chapter->getKey());

    expect($second['reused'])->toBeTrue()
        ->and($second['artifact']->is($first['artifact']))->toBeTrue()
        ->and($second['applied'])->toBeTrue()
        ->and($chapter->fresh()->summary)->toBe('林舟取得航海图，却发现灯塔仍在异常运转。')
        ->and($fake->requests())->toHaveCount(1)
        ->and(GenerationRun::query()->where('scope_type', 'chapter_summary')->count())->toBe(1);
});

test('a summary for an old artifact cannot overwrite the chapter after the canonical artifact changes', function () {
    [$chapter] = canonicalSummaryChapter(['summary' => '保留当前摘要']);
    $replacementRun = GenerationRun::factory()->for($chapter->novel)->for($chapter)->create([
        'stage' => GenerationStage::Rewrite,
        'status' => RunStatus::Succeeded,
    ]);
    $replacement = GenerationArtifact::factory()->for($replacementRun)->create([
        'type' => ArtifactType::RewriteDraft,
        'content' => '替换后的正式正文',
        'checksum' => hash('sha256', '替换后的正式正文'),
    ]);
    $response = summaryResponse();
    $provider = new class($chapter->getKey(), $replacement->getKey(), $response) implements AiProvider
    {
        public function __construct(
            private readonly int $chapterId,
            private readonly int $artifactId,
            private readonly AiResponse $response,
        ) {}

        public function generate(AiRequest $request): AiResponse
        {
            Chapter::query()->findOrFail($this->chapterId)->update(['canonical_artifact_id' => $this->artifactId]);

            return $this->response;
        }
    };
    app()->instance(AiProvider::class, new TrackingAiProvider($provider, app(UsageRecorder::class)));

    $result = app(CanonicalChapterSummaryService::class)->generate($chapter->getKey());

    expect($result['applied'])->toBeFalse()
        ->and($chapter->fresh()->canonical_artifact_id)->toBe($replacement->getKey())
        ->and($chapter->fresh()->summary)->toBe('保留当前摘要')
        ->and($result['artifact']->type)->toBe(ArtifactType::Summary)
        ->and($result['artifact']->generationRun->status)->toBe(RunStatus::Succeeded)
        ->and(UsageRecord::query()->count())->toBe(1);
});

test('provider failure preserves the existing summary and canonical story state', function () {
    [$chapter] = canonicalSummaryChapter(['summary' => '原摘要'], true);
    $novel = $chapter->novel->fresh();
    $stateId = $novel->canonical_state_version_id;
    $fake = (new FakeAiProvider)->enqueue(new AiProviderException('provider_timeout', '供应商超时', true));
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));

    expect(fn () => app(CanonicalChapterSummaryService::class)->generate($chapter->getKey()))
        ->toThrow(AiProviderException::class, '供应商超时');

    expect($chapter->fresh()->summary)->toBe('原摘要')
        ->and($chapter->novel->fresh()->canonical_state_version_id)->toBe($stateId)
        ->and(GenerationRun::query()->where('scope_type', 'chapter_summary')->sole()->status)->toBe(RunStatus::Failed)
        ->and(GenerationRun::query()->where('scope_type', 'chapter_summary')->sole()->error_retryable)->toBeTrue()
        ->and(data_get(GenerationRun::query()->where('scope_type', 'chapter_summary')->sole()->error_metadata, 'category'))->toBe('external_temporary')
        ->and(GenerationArtifact::query()->where('type', ArtifactType::Summary)->count())->toBe(0)
        ->and(UsageRecord::query()->count())->toBe(0);
});

test('summary job ignores a stale canonical artifact without calling the provider', function () {
    [$chapter, $source] = canonicalSummaryChapter();
    $replacementRun = GenerationRun::factory()->for($chapter->novel)->for($chapter)->create([
        'stage' => GenerationStage::Rewrite,
        'status' => RunStatus::Succeeded,
    ]);
    $replacement = GenerationArtifact::factory()->for($replacementRun)->create([
        'type' => ArtifactType::RewriteDraft,
        'content' => '新的正式正文。',
        'checksum' => hash('sha256', '新的正式正文。'),
    ]);
    $chapter->update(['canonical_artifact_id' => $replacement->getKey()]);
    $fake = (new FakeAiProvider)->enqueue(summaryResponse());
    app()->instance(AiProvider::class, $fake);

    (new GenerateCanonicalChapterSummaryJob($chapter->getKey(), $source->getKey()))
        ->handle(app(CanonicalChapterSummaryService::class));

    expect($fake->requests())->toBeEmpty()
        ->and($chapter->fresh()->summary)->toBeNull()
        ->and(GenerationRun::query()->where('scope_type', 'chapter_summary')->count())->toBe(0);
});

test('the backfill command is dry-run by default and requires the reviewed plan hash to execute', function () {
    [$chapter] = canonicalSummaryChapter();
    $fake = (new FakeAiProvider)->enqueue(summaryResponse());
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));
    $plan = app(CanonicalChapterSummaryService::class)->preview($chapter->novel);

    $this->artisan('novel:backfill-chapter-summaries', ['novel' => $chapter->novel_id])
        ->expectsOutputToContain('dry-run 未调用 AI、未写入数据')
        ->assertSuccessful();

    expect($fake->requests())->toBeEmpty()
        ->and($chapter->fresh()->summary)->toBeNull();

    $this->artisan('novel:backfill-chapter-summaries', [
        'novel' => $chapter->novel_id,
        '--execute' => true,
        '--plan-hash' => $plan['plan_hash'],
    ])->expectsOutputToContain('摘要补写完成')->assertSuccessful();

    expect($fake->requests())->toHaveCount(1)
        ->and($chapter->fresh()->summary)->not->toBeNull();
});

/** @return array{Chapter, GenerationArtifact} */
function canonicalSummaryChapter(array $chapterAttributes = [], bool $initializeState = false): array
{
    $novel = Novel::factory()->create();
    if ($initializeState) {
        app(InitializeNovelStateAction::class)->handle($novel);
    }
    $chapter = Chapter::factory()->for($novel)->create([
        'status' => ChapterStatus::Canonical,
        ...$chapterAttributes,
    ]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => '林舟取得航海图，灯塔仍在异常运转。',
        'checksum' => hash('sha256', '林舟取得航海图，灯塔仍在异常运转。'),
    ]);
    $chapter->update(['canonical_artifact_id' => $artifact->getKey()]);

    return [$chapter->fresh(), $artifact];
}

function summaryResponse(): AiResponse
{
    $data = [
        'summary' => '林舟取得航海图，却发现灯塔仍在异常运转。',
        'key_events' => ['林舟取得航海图'],
        'character_changes' => [],
        'unresolved_threads' => ['灯塔异常仍未解决'],
    ];

    return new AiResponse(
        content: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $data,
        inputTokens: 120,
        outputTokens: 80,
        cachedTokens: 0,
        latencyMs: 45,
        providerRequestId: 'summary-request-1',
        model: 'summary-test',
    );
}
