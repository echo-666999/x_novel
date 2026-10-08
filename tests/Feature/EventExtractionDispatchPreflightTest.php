<?php

use App\AI\Exceptions\AiProviderException;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Jobs\ExtractStoryEventsJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\User;
use App\Services\GenerationOutputCapacityGuard;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/** @return array{novel: Novel, chapter: Chapter, plan: ChapterPlan} */
function eventExtractionDispatchPreflightFixture(): array
{
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $plan = freezeChapterRouteContractsForTest(ChapterPlan::factory()->for($chapter)->create());
    $assemblyRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $draft = GenerationArtifact::factory()->for($assemblyRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => '林舟抵达洛阳。',
    ]);
    $eventRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
    ]);
    GenerationArtifact::factory()->for($eventRun)->create([
        'type' => ArtifactType::EventCandidate,
        'data' => [
            'status' => 'candidate',
            'source_artifact_id' => $draft->getKey(),
            'events' => [],
        ],
    ]);

    return compact('novel', 'chapter', 'plan');
}

test('reextract preflight blocks legacy admission before dispatching a job', function () {
    Queue::fake();
    $fixture = eventExtractionDispatchPreflightFixture();
    $snapshot = $fixture['plan']->admission_snapshot;
    $snapshot['schema_version'] = 1;
    $fixture['plan']->update(['admission_snapshot' => $snapshot]);

    expect(fn () => app(GenerationOutputCapacityGuard::class)
        ->assertEventExtractionDispatchable($fixture['chapter']->fresh()))
        ->toThrow(AiProviderException::class, '[ADMISSION_CONTRACT_VERSION_UNSUPPORTED]');

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->callAction(TestAction::make('extractStoryEvents')->schemaComponent('story-event-candidates', 'content'))
        ->assertNotified('无法重新提取事件')
        ->assertNotNotified('故事事件提取已加入队列');

    Queue::assertNotPushed(ExtractStoryEventsJob::class);
});

test('reextract preflight blocks missing extractor capacity before dispatching a job', function () {
    Queue::fake();
    $fixture = eventExtractionDispatchPreflightFixture();
    $snapshot = $fixture['plan']->admission_snapshot;
    data_forget($snapshot, 'routes.extractor.model_capacity');
    $fixture['plan']->update(['admission_snapshot' => $snapshot]);

    expect(fn () => app(GenerationOutputCapacityGuard::class)
        ->assertEventExtractionDispatchable($fixture['chapter']->fresh()))
        ->toThrow(AiProviderException::class, '[MODEL_CAPACITY_NOT_FROZEN]');

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->callAction(TestAction::make('extractStoryEvents')->schemaComponent('story-event-candidates', 'content'))
        ->assertNotified('无法重新提取事件')
        ->assertNotNotified('故事事件提取已加入队列');

    Queue::assertNotPushed(ExtractStoryEventsJob::class);
});

test('reextract preflight blocks invalid extractor budgets before dispatching a job', function () {
    Queue::fake();
    $fixture = eventExtractionDispatchPreflightFixture();
    $snapshot = $fixture['plan']->admission_snapshot;
    data_set($snapshot, 'routes.extractor.request_budgets.final.max_completion_tokens', 1);
    $fixture['plan']->update(['admission_snapshot' => $snapshot]);

    expect(fn () => app(GenerationOutputCapacityGuard::class)
        ->assertEventExtractionDispatchable($fixture['chapter']->fresh()))
        ->toThrow(ValidationException::class, '[REQUEST_BUDGET_INVALID]');

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->callAction(TestAction::make('extractStoryEvents')->schemaComponent('story-event-candidates', 'content'))
        ->assertNotified('无法重新提取事件')
        ->assertNotNotified('故事事件提取已加入队列');

    Queue::assertNotPushed(ExtractStoryEventsJob::class);
});
