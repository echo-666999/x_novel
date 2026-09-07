<?php

use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\MemoryType;
use App\Enums\RunStatus;
use App\Enums\StoryEventStatus;
use App\Jobs\GenerateEmbeddingJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Services\MemoryUpdater;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function memoryUpdaterFixture(): array
{
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 12,
        'status' => ChapterStatus::Canonical,
    ]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $content = '沈澜抵达灯塔，并发现守塔人的密令。';
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $chapter->update(['canonical_artifact_id' => $artifact->getKey()]);
    $state = ['schema_version' => 1, 'characters' => [], 'relationships' => [], 'locations' => [], 'items' => [], 'world' => [], 'timeline' => [], 'open_threads' => [], 'foreshadowings' => [], 'reader_promises' => []];
    $stateVersion = StoryStateVersion::factory()->for($novel)->for($chapter)->create([
        'version' => 4,
        'state' => $state,
        'checksum' => app(StoryStateService::class)->checksum($state),
    ]);
    $novel->update(['canonical_state_version_id' => $stateVersion->getKey()]);
    $event = StoryEvent::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
        'event_type' => EventType::CharacterLearned,
        'subject_type' => 'character',
        'subject_id' => '7',
        'evidence' => [['artifact_id' => $artifact->getKey(), 'scene_id' => null, 'quote' => '沈澜发现了守塔人的密令。']],
        'state_version' => 4,
    ]);

    return compact('novel', 'chapter', 'run', 'artifact', 'stateVersion', 'event');
}

test('memory updater creates traceable memories only from active canonical events', function () {
    Queue::fake();
    $fixture = memoryUpdaterFixture();
    StoryEvent::factory()->create([
        'novel_id' => $fixture['novel']->getKey(),
        'chapter_id' => $fixture['chapter']->getKey(),
        'status' => StoryEventStatus::Invalidated,
        'invalidated_at' => now(),
        'state_version' => 4,
    ]);

    $memories = app(MemoryUpdater::class)->update($fixture['chapter']->getKey());
    $memory = $memories->sole();

    expect($memory->type)->toBe(MemoryType::CharacterMilestone)
        ->and($memory->source_type)->toBe('story_event')
        ->and($memory->source_id)->toBe($fixture['event']->getKey())
        ->and($memory->summary)->toBe('沈澜发现了守塔人的密令。')
        ->and($memory->entities)->toBe(['characters' => ['7']])
        ->and($memory->salience)->toBe('0.800')
        ->and($memory->valid_from_chapter)->toBe(12)
        ->and($memory->embedding)->toBeNull()
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical);

    Queue::assertPushed(GenerateEmbeddingJob::class, fn (GenerateEmbeddingJob $job): bool => $job->memoryId === $memory->getKey());
});

test('duplicate memory job delivery reuses memories and the successful run', function () {
    $fixture = memoryUpdaterFixture();
    $job = new UpdateMemoryJob($fixture['chapter']->getKey());

    $job->handle(app(MemoryUpdater::class));
    $job->handle(app(MemoryUpdater::class));

    expect(Memory::query()->count())->toBe(1)
        ->and(GenerationRun::query()->where('stage', GenerationStage::MemorySummary)->count())->toBe(1)
        ->and(GenerationRun::query()->where('stage', GenerationStage::MemorySummary)->sole()->status)->toBe(RunStatus::Succeeded);
});

test('draft chapters cannot create formal memories', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Review]);

    expect(fn () => app(MemoryUpdater::class)->update($chapter->getKey()))
        ->toThrow(ValidationException::class, 'Canonical Artifact');

    expect(Memory::query()->count())->toBe(0)
        ->and(GenerationRun::query()->where('stage', GenerationStage::MemorySummary)->count())->toBe(0);
});

test('memory updater rejects a canonical artifact from another chapter', function () {
    $fixture = memoryUpdaterFixture();
    $other = Chapter::factory()->for($fixture['novel'])->create();
    $otherRun = GenerationRun::factory()->for($fixture['novel'])->for($other)->create();
    $otherArtifact = GenerationArtifact::factory()->for($otherRun)->create(['type' => ArtifactType::ChapterDraft]);
    $fixture['chapter']->update(['canonical_artifact_id' => $otherArtifact->getKey()]);

    expect(fn () => app(MemoryUpdater::class)->update($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, 'Canonical Artifact');

    expect(Memory::query()->count())->toBe(0);
});
