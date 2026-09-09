<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\AssembleChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Models\StoryStateVersion;
use App\Services\ChapterAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function chapterAssemblyFixture(int $sceneCount = 2): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create(['tone' => '克制悬疑']);
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 20,
        'status' => ChapterStatus::Generating,
    ]);
    ChapterPlan::factory()->for($chapter)->create([
        'tone' => '紧张',
        'scene_plans' => [],
    ]);
    $scenes = collect(range(1, $sceneCount))->map(function (int $sequence) use ($chapter, $novel): Scene {
        $scene = Scene::factory()->for($chapter)->create([
            'sequence' => $sequence,
            'status' => SceneStatus::Draft,
        ]);
        $run = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
            'stage' => GenerationStage::SceneGeneration,
            'status' => RunStatus::Succeeded,
        ]);
        $content = "Scene {$sequence} 正文";
        $artifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::SceneDraft,
            'content' => $content,
            'checksum' => hash('sha256', $content),
        ]);
        $scene->update(['current_artifact_id' => $artifact->getKey()]);

        return $scene->fresh();
    });

    return compact('novel', 'chapter', 'scenes');
}

function assemblyResponse(string $content = '完整章节正文'): AiResponse
{
    return new AiResponse(
        content: $content,
        structuredData: null,
        inputTokens: 300,
        outputTokens: 600,
        cachedTokens: 0,
        latencyMs: 500,
        providerRequestId: 'assembly-request',
        model: 'assembler-test',
    );
}

test('assembler combines multiple scene drafts in sequence into a chapter draft', function () {
    $fixture = chapterAssemblyFixture(3);
    $fake = (new FakeAiProvider)->enqueue(assemblyResponse('第一幕。第二幕。第三幕。'));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());
    $run = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->sole();
    $prompt = $fake->requests()[0]->prompt;

    expect($artifact->type)->toBe(ArtifactType::ChapterDraft)
        ->and($artifact->version)->toBe(1)
        ->and($artifact->content)->toBe('第一幕。第二幕。第三幕。')
        ->and($artifact->data['ordered_scene_checksums'])->toBe($fixture['scenes']->pluck('currentArtifact.checksum')->all())
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->context_snapshot['ordered_scene_checksums'])->toHaveCount(3)
        ->and(data_get($run->context_snapshot, 'writing_constraints.chapter_target_words'))->toBe($fixture['chapter']->latestPlan->target_words)
        ->and(data_get($run->context_snapshot, 'writing_constraints.chapter_minimum_words'))->toBe(2550)
        ->and(data_get($run->context_snapshot, 'writing_constraints.chapter_maximum_words'))->toBe(3450)
        ->and($artifact->data['word_count'])->toBe(mb_strlen('第一幕。第二幕。第三幕。'))
        ->and($fake->requests()[0]->systemPrompt)->toContain('不得把正文压缩成摘要')
        ->and(mb_strpos($prompt, 'Scene 1 正文'))->toBeLessThan(mb_strpos($prompt, 'Scene 2 正文'))
        ->and(mb_strpos($prompt, 'Scene 2 正文'))->toBeLessThan(mb_strpos($prompt, 'Scene 3 正文'));
});

test('assembly refuses to run until every scene has a successful draft', function () {
    $fixture = chapterAssemblyFixture();
    $fixture['scenes']->last()->update(['status' => SceneStatus::Failed, 'current_artifact_id' => null]);
    $fake = (new FakeAiProvider)->enqueue(assemblyResponse());
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, 'Scene 2 尚未成功');

    expect($fake->requests())->toHaveCount(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(0);
});

test('duplicate assembly delivery reuses the successful artifact', function () {
    $fixture = chapterAssemblyFixture();
    $fake = (new FakeAiProvider)->enqueue(assemblyResponse());
    app()->instance(AiProvider::class, $fake);
    $assembler = app(ChapterAssembler::class);

    $first = $assembler->assemble($fixture['chapter']->getKey());
    $second = $assembler->assemble($fixture['chapter']->getKey());

    expect($second?->is($first))->toBeTrue()
        ->and($fake->requests())->toHaveCount(1)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(1);
});

test('regeneration creates a new immutable chapter draft version', function () {
    $fixture = chapterAssemblyFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(assemblyResponse('Draft v1'))
        ->enqueue(assemblyResponse('Draft v2'));
    app()->instance(AiProvider::class, $fake);
    $assembler = app(ChapterAssembler::class);

    $first = $assembler->assemble($fixture['chapter']->getKey());
    $second = $assembler->assemble($fixture['chapter']->getKey(), true);

    expect($first->fresh()->content)->toBe('Draft v1')
        ->and($first->version)->toBe(1)
        ->and($second->version)->toBe(2)
        ->and($second->content)->toBe('Draft v2');
});

test('retryable assembly failures are recorded and retry from assembly only', function () {
    $fixture = chapterAssemblyFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(new AiProviderException('provider_timeout', 'timeout', true))
        ->enqueue(assemblyResponse('重试后完整正文'));
    app()->instance(AiProvider::class, $fake);
    $job = new AssembleChapterJob($fixture['chapter']->getKey());

    expect(fn () => $job->handle(app(ChapterAssembler::class)))
        ->toThrow(AiProviderException::class, 'timeout');

    $job->handle(app(ChapterAssembler::class));

    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(2)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->latest('id')->first()->status)->toBe(RunStatus::Succeeded)
        ->and($fixture['scenes']->every(fn (Scene $scene): bool => $scene->fresh()->status === SceneStatus::Draft))->toBeTrue();
});

test('a stale assembly run is marked interrupted before recovery', function () {
    $fixture = chapterAssemblyFixture();
    $stale = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Running,
        'updated_at' => now()->subMinutes(3),
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(assemblyResponse()));

    app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());

    expect($stale->fresh()->status)->toBe(RunStatus::Failed)
        ->and($stale->fresh()->error_code)->toBe('worker_interrupted')
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(2);
});

test('paused novels cannot start assembly', function () {
    $fixture = chapterAssemblyFixture();
    $fixture['novel']->update(['status' => NovelStatus::Paused]);
    app()->instance(AiProvider::class, new FakeAiProvider);

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '小说已暂停');

    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(0);
});

test('state version changes during assembly prevent draft persistence', function () {
    $fixture = chapterAssemblyFixture();
    $provider = new class($fixture['novel']) implements AiProvider
    {
        public function __construct(private Novel $novel) {}

        public function generate(AiRequest $request): AiResponse
        {
            $version = StoryStateVersion::factory()->for($this->novel)->create(['version' => 1]);
            $this->novel->update(['canonical_state_version_id' => $version->getKey()]);

            return assemblyResponse();
        }
    };
    app()->instance(AiProvider::class, $provider);

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, 'Story State 已变化');

    expect(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->sole()->status)->toBe(RunStatus::Failed);
});

test('terminal assembly failure blocks the chapter but preserves scene drafts', function () {
    $fixture = chapterAssemblyFixture();
    $sceneArtifactIds = $fixture['scenes']->pluck('current_artifact_id')->all();

    (new AssembleChapterJob($fixture['chapter']->getKey()))->failed(new AiProviderException('provider_timeout', 'timeout', true));

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Blocked)
        ->and($fixture['chapter']->scenes()->pluck('current_artifact_id')->all())->toBe($sceneArtifactIds);
});
