<?php

use App\Actions\Chapters\AcceptOverlengthChapterAction;
use App\Actions\Generation\CheckNextAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Data\CanonicalCommitData;
use App\Data\StateValidationResult;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\FactStatus;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\MemoryStatus;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Jobs\CommitChapterJob;
use App\Jobs\ContinueAutoGenerationJob;
use App\Jobs\GenerateCanonicalChapterSummaryJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\RefreshNovelProjectionJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Review;
use App\Models\StoryArc;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\User;
use App\Models\Volume;
use App\Models\WorldEntity;
use App\Services\CanonicalCommitService;
use App\Services\EmergencyStopService;
use App\Services\LatestCanonicalChapterRollback;
use App\Services\MemoryInvalidator;
use App\Services\ProjectionRebuilder;
use App\Services\StateValidator;
use App\Services\StoryArcBeatContract;
use App\Services\StoryArcProgressProjector;
use App\Services\StoryStateRebuilder;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([UpdateMemoryJob::class, RefreshNovelProjectionJob::class]);
});

/** @return array<string, mixed> */
function canonicalCommitFixture(array $patchOverrides = [], ReviewDecision $decision = ReviewDecision::Pass): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $state = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 1, 'status' => ChapterStatus::Review]);
    ChapterPlan::factory()->for($chapter)->create(['target_words' => 20]);
    $draftRun = GenerationRun::factory()->create([
        'novel_id' => $novel->getKey(), 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::ChapterAssembly, 'status' => RunStatus::Succeeded,
    ]);
    $draftContent = '沈澜走进灯塔，终于看见了隐藏的星图。';
    $draft = GenerationArtifact::factory()->create([
        'generation_run_id' => $draftRun->getKey(), 'type' => ArtifactType::ChapterDraft,
        'content' => $draftContent, 'checksum' => hash('sha256', $draftContent),
    ]);
    $events = [[
        'event_type' => EventType::CharacterMoved->value,
        'subject_type' => null,
        'subject_id' => null,
        'payload' => ['to' => '灯塔'],
        'evidence' => [[
            'artifact_id' => $draft->getKey(), 'scene_id' => null, 'quote' => '沈澜走进灯塔',
            'start_offset' => 0, 'end_offset' => 7,
        ]],
        'story_time' => '第一日夜晚',
        'confidence' => 0.98,
    ]];
    $eventRun = GenerationRun::factory()->create([
        'novel_id' => $novel->getKey(), 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::EventExtraction, 'status' => RunStatus::Succeeded,
        'state_version' => 0,
    ]);
    $candidateData = ['status' => 'candidate', 'source_artifact_id' => $draft->getKey(), 'events' => $events];
    $candidate = GenerationArtifact::factory()->create([
        'generation_run_id' => $eventRun->getKey(), 'type' => ArtifactType::EventCandidate,
        'data' => $candidateData, 'content' => json_encode($events),
        'checksum' => hash('sha256', json_encode($candidateData)),
    ]);
    $patchData = [
        'expected_state_version' => 0,
        'operations' => [],
        'fact_changes' => [],
        'foreshadowing_changes' => [],
        'status' => 'candidate',
        'source_artifact_id' => $candidate->getKey(),
        'before_checksum' => $state->checksum,
        'after_checksum' => app(StoryStateService::class)->checksum($state->state),
        'changes' => [],
        ...$patchOverrides,
    ];
    $patch = GenerationArtifact::factory()->create([
        'generation_run_id' => $eventRun->getKey(), 'type' => ArtifactType::StatePatch,
        'version' => 1, 'data' => $patchData, 'content' => json_encode($patchData['operations']),
        'checksum' => hash('sha256', json_encode($patchData)),
    ]);
    $reviewRun = GenerationRun::factory()->create([
        'novel_id' => $novel->getKey(), 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded,
        'state_version' => 0,
    ]);
    $reviewData = ['decision' => $decision->value, 'source_artifact_id' => $draft->getKey(), 'findings' => []];
    $reviewArtifact = GenerationArtifact::factory()->create([
        'generation_run_id' => $reviewRun->getKey(), 'type' => ArtifactType::ReviewResult,
        'data' => $reviewData, 'content' => json_encode($reviewData), 'checksum' => hash('sha256', json_encode($reviewData)),
    ]);
    $review = Review::factory()->create([
        'generation_run_id' => $reviewRun->getKey(), 'artifact_id' => $reviewArtifact->getKey(), 'decision' => $decision,
    ]);
    $data = new CanonicalCommitData(
        $chapter->getKey(), $draft->getKey(), $review->getKey(), $candidate->getKey(), $patch->getKey(), 0, $draft->checksum,
    );

    return compact('novel', 'state', 'chapter', 'draft', 'candidate', 'patch', 'review', 'data');
}

/** @param array<string, mixed> $fixture */
function addPlanningClosureToCanonicalFixture(array $fixture): StoryArc
{
    $arc = StoryArc::factory()->for($fixture['novel'])->create([
        'status' => StoryArcStatus::Active,
        'beats' => ['发现星图'],
        'completion_conditions' => [],
    ]);
    $beatKey = app(StoryArcBeatContract::class)->key('发现星图');
    $fixture['chapter']->latestPlan->update([
        'arc_contributions' => [[
            'arc_id' => $arc->getKey(),
            'beat_key' => $beatKey,
            'beat_index' => 1,
            'target_scene_sequence' => 1,
            'acceptance_criteria' => '正文明确发现隐藏星图。',
        ]],
        'world_entity_candidates' => [[
            'candidate_key' => 'wec-hidden-star-map',
            'type' => 'item',
            'name' => '隐藏星图',
            'description' => '藏在灯塔中的古老星图。',
            'deduplication_basis' => '现有世界实体中没有同名或同用途物品。',
            'possible_duplicate_entity_ids' => [],
            'introduction_reason' => '推动主线调查。',
            'target_scene_sequence' => 1,
        ]],
    ]);
    $candidateData = $fixture['candidate']->fresh()->data;
    $events = data_get($candidateData, 'events', []);
    $evidence = [[
        'artifact_id' => $fixture['draft']->getKey(),
        'scene_id' => null,
        'quote' => '隐藏的星图',
        'start_offset' => 10,
        'end_offset' => 15,
    ]];
    $events[] = [
        'event_type' => EventType::StoryArcBeatCompleted->value,
        'subject_type' => 'story_arc',
        'subject_id' => (string) $arc->getKey(),
        'payload' => ['beat_key' => $beatKey],
        'evidence' => $evidence,
        'story_time' => '第一日夜晚',
        'confidence' => 0.98,
    ];
    $events[] = [
        'event_type' => EventType::WorldEntityIntroduced->value,
        'subject_type' => 'world_entity',
        'subject_id' => 'wec-hidden-star-map',
        'payload' => ['candidate_key' => 'wec-hidden-star-map'],
        'evidence' => $evidence,
        'story_time' => '第一日夜晚',
        'confidence' => 0.98,
    ];
    DB::table('generation_artifacts')->where('id', $fixture['candidate']->getKey())->update([
        'data' => json_encode([...$candidateData, 'events' => $events]),
    ]);
    $currentReviewData = $fixture['review']->artifact()->firstOrFail()->data;
    $reviewData = [
        ...$currentReviewData,
        'arc_beat_audits' => [[
            'arc_id' => $arc->getKey(),
            'beat_key' => $beatKey,
            'status' => 'fulfilled',
            'evidence' => '隐藏的星图',
            'scene_id' => null,
        ]],
        'arc_completion_audits' => [[
            'arc_id' => $arc->getKey(),
            'status' => 'fulfilled',
            'evidence' => '隐藏的星图',
        ]],
        'world_entity_candidate_audits' => [[
            'candidate_key' => 'wec-hidden-star-map',
            'status' => 'introduced',
            'evidence' => '隐藏的星图',
            'scene_id' => null,
        ]],
        'unapproved_world_entities' => [],
    ];
    DB::table('generation_artifacts')->where('id', $fixture['review']->artifact->getKey())->update([
        'data' => json_encode($reviewData),
    ]);

    return $arc;
}

/** @param array<string, mixed> $fixture */
function addCharacterIntroductionToCanonicalFixture(array $fixture): void
{
    $fixture['chapter']->latestPlan->update(['character_candidates' => [[
        'candidate_key' => 'character-shen-lan',
        'name' => '沈澜',
        'role' => '向导',
        'motivation' => '查清灯塔失踪事件。',
        'profile' => ['age' => 28],
        'personality' => ['trait' => '谨慎'],
        'abilities' => ['skill' => '辨认星图'],
        'knowledge' => ['route' => '灯塔旧路'],
        'deduplication_basis' => '现有正式人物中没有同名或同身份人物。',
        'possible_duplicate_character_ids' => [],
        'introduction_reason' => '带领主角进入灯塔。',
        'target_scene_sequence' => 1,
    ]]]);
    $candidateData = $fixture['candidate']->fresh()->data;
    $events = data_get($candidateData, 'events', []);
    $events[] = [
        'event_type' => EventType::CharacterIntroduced->value,
        'subject_type' => 'character',
        'subject_id' => 'character-shen-lan',
        'payload' => ['candidate_key' => 'character-shen-lan'],
        'evidence' => [[
            'artifact_id' => $fixture['draft']->getKey(),
            'scene_id' => null,
            'quote' => '沈澜走进灯塔',
            'start_offset' => 0,
            'end_offset' => 7,
        ]],
        'story_time' => '第一日夜晚',
        'confidence' => 0.98,
    ];
    DB::table('generation_artifacts')->where('id', $fixture['candidate']->getKey())->update([
        'data' => json_encode([...$candidateData, 'events' => $events]),
    ]);
    $currentReviewData = $fixture['review']->artifact()->firstOrFail()->data;
    $reviewData = [
        ...$currentReviewData,
        'character_candidate_audits' => [[
            'candidate_key' => 'character-shen-lan',
            'status' => 'introduced',
            'evidence' => '沈澜走进灯塔',
            'scene_id' => null,
        ]],
        'unapproved_characters' => [],
    ];
    DB::table('generation_artifacts')->where('id', $fixture['review']->artifact->getKey())->update([
        'data' => json_encode($reviewData),
    ]);
}

test('canonical commit atomically persists events state and pointers', function () {
    $fixture = canonicalCommitFixture();

    $version = app(CanonicalCommitService::class)->commit($fixture['data']);

    expect($version->version)->toBe(1)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBe($fixture['draft']->getKey())
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($version->getKey())
        ->and($fixture['novel']->fresh()->current_chapter_sequence)->toBe(1)
        ->and(StoryEvent::query()->count())->toBe(1)
        ->and(StoryEvent::query()->sole()->state_version)->toBe(1)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(2);
    Queue::assertPushedWithChain(UpdateMemoryJob::class, [
        GenerateCanonicalChapterSummaryJob::class,
        RefreshNovelProjectionJob::class,
        ContinueAutoGenerationJob::class,
    ]);
});

test('canonical commit closes accepted arc beats and world entity candidates exactly once and rollback reverses them', function () {
    $fixture = canonicalCommitFixture();
    $arc = addPlanningClosureToCanonicalFixture($fixture);
    addCharacterIntroductionToCanonicalFixture($fixture);
    $service = app(CanonicalCommitService::class);

    $version = $service->commit($fixture['data']);
    $service->commit($fixture['data']);
    $entity = WorldEntity::query()->where('novel_id', $fixture['novel']->getKey())->sole();
    $character = Character::query()->where('novel_id', $fixture['novel']->getKey())->sole();

    expect($arc->fresh()->progress)->toBe(1.0)
        ->and($arc->fresh()->status)->toBe(StoryArcStatus::Completed)
        ->and($entity->source_chapter_id)->toBe($fixture['chapter']->getKey())
        ->and($entity->source_candidate_key)->toBe('wec-hidden-star-map')
        ->and($character->source_chapter_id)->toBe($fixture['chapter']->getKey())
        ->and($character->source_candidate_key)->toBe('character-shen-lan')
        ->and($character->name)->toBe('沈澜')
        ->and(data_get($version->state, 'characters.'.$character->getKey().'.name'))->toBe('沈澜')
        ->and(data_get($version->state, 'world.'.$entity->getKey().'.name'))->toBe('隐藏星图')
        ->and(StoryEvent::query()->where('event_type', EventType::CharacterIntroduced)->value('subject_id'))->toBe((string) $character->getKey())
        ->and(StoryEvent::query()->where('event_type', EventType::StoryArcBeatCompleted)->count())->toBe(1)
        ->and(StoryEvent::query()->where('event_type', EventType::WorldEntityIntroduced)->value('subject_id'))->toBe((string) $entity->getKey())
        ->and(Character::query()->count())->toBe(1)
        ->and(WorldEntity::query()->count())->toBe(1);

    app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '验证规划和世界资料回滚');

    expect($arc->fresh()->progress)->toBe(0.0)
        ->and($arc->fresh()->status)->toBe(StoryArcStatus::Active)
        ->and(Character::query()->count())->toBe(0)
        ->and(WorldEntity::query()->count())->toBe(0);
});

test('canonical commit rejects an unconfirmed character candidate without creating it', function () {
    $fixture = canonicalCommitFixture();
    addCharacterIntroductionToCanonicalFixture($fixture);
    $reviewData = $fixture['review']->artifact->fresh()->data;
    $reviewData['character_candidate_audits'][0]['status'] = 'missing';
    $reviewData['character_candidate_audits'][0]['evidence'] = null;
    DB::table('generation_artifacts')->where('id', $fixture['review']->artifact->getKey())->update([
        'data' => json_encode($reviewData),
    ]);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, '未通过 Review');

    expect(Character::query()->count())->toBe(0)
        ->and(StoryEvent::query()->count())->toBe(0)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull();
});

test('beat completion requires matching plan review event and evidence', function (string $missingLink) {
    $fixture = canonicalCommitFixture();
    addPlanningClosureToCanonicalFixture($fixture);

    if ($missingLink === 'plan') {
        $contributions = $fixture['chapter']->latestPlan->arc_contributions;
        $contributions[0]['beat_key'] = 'another-beat';
        $fixture['chapter']->latestPlan->update(['arc_contributions' => $contributions]);
    }

    if ($missingLink === 'review') {
        $reviewData = $fixture['review']->artifact()->firstOrFail()->data;
        $reviewData['arc_beat_audits'] = [];
        DB::table('generation_artifacts')->where('id', $fixture['review']->artifact_id)->update([
            'data' => json_encode($reviewData),
        ]);
    }

    if ($missingLink === 'event') {
        $candidateData = $fixture['candidate']->fresh()->data;
        $candidateData['events'] = collect($candidateData['events'])
            ->reject(fn (array $event): bool => $event['event_type'] === EventType::StoryArcBeatCompleted->value)
            ->values()->all();
        DB::table('generation_artifacts')->where('id', $fixture['candidate']->getKey())->update([
            'data' => json_encode($candidateData),
        ]);
    }

    if ($missingLink === 'evidence') {
        $reviewData = $fixture['review']->artifact()->firstOrFail()->data;
        $reviewData['arc_beat_audits'][0]['evidence'] = '沈澜走进灯塔';
        DB::table('generation_artifacts')->where('id', $fixture['review']->artifact_id)->update([
            'data' => json_encode($reviewData),
        ]);
    }

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class);

    expect(StoryEvent::query()->count())->toBe(0)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(1);
})->with(['plan', 'review', 'event', 'evidence']);

test('projection refresh after commit derives every foreshadowing field from canonical inputs', function () {
    $fixture = canonicalCommitFixture();
    $foreshadowing = Foreshadowing::factory()->for($fixture['novel'])->create([
        'status' => ForeshadowingStatus::Idea,
        'reinforce_count' => 0,
    ]);
    $initial = $fixture['state']->state;
    $initial['foreshadowings'][(string) $foreshadowing->getKey()] = [
        'status' => ForeshadowingStatus::Idea->value,
        'reinforce_count' => 0,
    ];
    $initialChecksum = app(StoryStateService::class)->checksum($initial);
    DB::table('story_state_versions')->where('id', $fixture['state']->getKey())->update([
        'state' => json_encode($initial, JSON_THROW_ON_ERROR),
        'checksum' => $initialChecksum,
    ]);
    $event = fn (EventType $type, string $quote): array => [
        'event_type' => $type->value,
        'subject_type' => 'foreshadowing',
        'subject_id' => (string) $foreshadowing->getKey(),
        'payload' => [],
        'evidence' => [[
            'artifact_id' => $fixture['draft']->getKey(),
            'scene_id' => null,
            'quote' => $quote,
            'start_offset' => null,
            'end_offset' => null,
        ]],
        'story_time' => null,
        'confidence' => .95,
    ];
    $events = [
        $event(EventType::ForeshadowingPlanted, '星图首次显现'),
        $event(EventType::ForeshadowingReinforced, '星图再次发光'),
        $event(EventType::ForeshadowingPaidOff, '星图打开灯塔'),
    ];
    $candidateData = [...$fixture['candidate']->data, 'events' => $events];
    DB::table('generation_artifacts')->where('id', $fixture['candidate']->getKey())->update([
        'data' => json_encode($candidateData, JSON_THROW_ON_ERROR),
    ]);
    $after = $initial;
    $after['foreshadowings'][(string) $foreshadowing->getKey()] = [
        'status' => ForeshadowingStatus::PaidOff->value,
        'reinforce_count' => 1,
    ];
    $patchData = [
        ...$fixture['patch']->data,
        'before_checksum' => $initialChecksum,
        'after_checksum' => app(StoryStateService::class)->checksum($after),
        'operations' => [
            ['op' => 'set', 'path' => "foreshadowings.{$foreshadowing->getKey()}.status", 'value' => ForeshadowingStatus::Planted->value, 'source_event_index' => 0],
            ['op' => 'set', 'path' => "foreshadowings.{$foreshadowing->getKey()}.status", 'value' => ForeshadowingStatus::Reinforced->value, 'source_event_index' => 1],
            ['op' => 'increment', 'path' => "foreshadowings.{$foreshadowing->getKey()}.reinforce_count", 'value' => 1, 'source_event_index' => 1],
            ['op' => 'set', 'path' => "foreshadowings.{$foreshadowing->getKey()}.status", 'value' => ForeshadowingStatus::PaidOff->value, 'source_event_index' => 2],
        ],
    ];
    DB::table('generation_artifacts')->where('id', $fixture['patch']->getKey())->update([
        'data' => json_encode($patchData, JSON_THROW_ON_ERROR),
    ]);
    $validator = Mockery::mock(StateValidator::class);
    $validator->shouldReceive('validate')->once()->andReturn(new StateValidationResult([]));
    app()->instance(StateValidator::class, $validator);

    $version = app(CanonicalCommitService::class)->commit($fixture['data']);
    (new RefreshNovelProjectionJob($fixture['novel']->getKey(), $version->getKey()))
        ->handle(app(ProjectionRebuilder::class));

    $projection = $foreshadowing->fresh();
    expect($projection->status)->toBe(ForeshadowingStatus::PaidOff)
        ->and($projection->reinforce_count)->toBe(1)
        ->and($projection->setup_chapter_id)->toBe($fixture['chapter']->getKey())
        ->and($projection->payoff_chapter_id)->toBe($fixture['chapter']->getKey());
});

test('latest canonical chapter rollback restores pointers and invalidates derived data', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture(['fact_changes' => [[
        'action' => 'create', 'subject_type' => 'novel', 'subject_id' => 1,
        'predicate' => 'has_star_map', 'value' => true, 'source_event_index' => 0,
    ]]]);
    $committedState = app(CanonicalCommitService::class)->commit($fixture['data']);
    $event = StoryEvent::query()->sole();
    $memory = Memory::factory()->for($fixture['novel'])->create([
        'source_type' => 'story_event', 'source_id' => $event->getKey(), 'valid_from_chapter' => 1,
    ]);

    $result = app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '修正结尾事件');
    $rebuild = app(StoryStateRebuilder::class)->rebuild($fixture['novel']->fresh());

    expect($result)->toMatchArray(['from_state_version' => 1, 'to_state_version' => 0, 'events' => 1, 'memories' => 1])
        ->and($rebuild->matches())->toBeTrue()
        ->and($rebuild->baselineVersion)->toBe(0)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Void)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($fixture['state']->getKey())
        ->and($fixture['novel']->fresh()->current_chapter_sequence)->toBeNull()
        ->and($event->fresh()->status->value)->toBe('invalidated')
        ->and($event->fresh()->invalidated_at)->not->toBeNull()
        ->and($memory->fresh()->status)->toBe(MemoryStatus::Invalid)
        ->and(Fact::query()->sole()->status)->toBe(FactStatus::Invalidated)
        ->and(StoryStateVersion::query()->find($committedState->getKey()))->not->toBeNull();
    Queue::assertPushed(RefreshNovelProjectionJob::class, fn (RefreshNovelProjectionJob $job): bool => $job->novelId === $fixture['novel']->getKey() && $job->stateVersionId === $fixture['state']->getKey());
});

test('rollback restores facts superseded by the latest canonical chapter', function () {
    $fixture = canonicalCommitFixture();
    $fact = Fact::factory()->for($fixture['novel'])->create(['status' => FactStatus::Active]);
    $patchData = $fixture['patch']->data;
    $patchData['fact_changes'] = [['action' => 'supersede', 'fact_id' => $fact->getKey(), 'source_event_index' => 0]];
    DB::table('generation_artifacts')->where('id', $fixture['patch']->getKey())->update([
        'data' => json_encode($patchData, JSON_THROW_ON_ERROR),
    ]);

    app(CanonicalCommitService::class)->commit($fixture['data']);
    expect($fact->fresh()->status)->toBe(FactStatus::Superseded);

    app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '恢复被替代的事实');

    expect($fact->fresh()->status)->toBe(FactStatus::Active);
});

test('rollback restores foreshadowing projection from the previous state', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    $foreshadowing = Foreshadowing::factory()->for($fixture['novel'])->create([
        'status' => ForeshadowingStatus::PaidOff,
        'reinforce_count' => 3,
        'setup_chapter_id' => $fixture['chapter']->getKey(),
        'payoff_chapter_id' => $fixture['chapter']->getKey(),
    ]);
    app(CanonicalCommitService::class)->commit($fixture['data']);
    $previous = $fixture['state']->state;
    $previous['foreshadowings'][(string) $foreshadowing->getKey()] = ['status' => 'reinforced', 'reinforce_count' => 2];
    DB::table('story_state_versions')->where('id', $fixture['state']->getKey())->update([
        'state' => json_encode($previous, JSON_THROW_ON_ERROR),
    ]);
    $event = StoryEvent::query()->sole();
    DB::table('story_events')->where('id', $event->getKey())->update([
        'event_type' => EventType::ForeshadowingPaidOff->value,
        'subject_type' => 'foreshadowing',
        'subject_id' => (string) $foreshadowing->getKey(),
    ]);

    app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '恢复伏笔状态');
    (new RefreshNovelProjectionJob($fixture['novel']->getKey(), $fixture['state']->getKey()))
        ->handle(app(ProjectionRebuilder::class));

    expect($foreshadowing->fresh()->status)->toBe(ForeshadowingStatus::Reinforced)
        ->and($foreshadowing->fresh()->reinforce_count)->toBe(2)
        ->and($foreshadowing->fresh()->setup_chapter_id)->toBeNull()
        ->and($foreshadowing->fresh()->payoff_chapter_id)->toBeNull();
});

test('a non latest canonical chapter cannot be rolled back', function () {
    $fixture = canonicalCommitFixture();
    app(CanonicalCommitService::class)->commit($fixture['data']);
    $latest = Chapter::factory()->for($fixture['novel'])->create(['sequence' => 2, 'status' => ChapterStatus::Canonical]);
    $latestState = StoryStateVersion::factory()->for($fixture['novel'])->for($latest)->create(['version' => 2]);
    $fixture['novel']->update(['current_chapter_sequence' => 2, 'canonical_state_version_id' => $latestState->getKey()]);

    expect(fn () => app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '不应允许'))
        ->toThrow(ValidationException::class, '只能回滚当前最新的正式章节');

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical);
});

test('recanonicalization after rollback uses a monotonically increasing state version', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    $service = app(CanonicalCommitService::class);
    $service->commit($fixture['data']);
    app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '重新提交这一章');

    $nextState = $service->commit($fixture['data']);

    expect($nextState->version)->toBe(2)
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($nextState->getKey())
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->pluck('version')->all())->toBe([0, 1, 2])
        ->and(StoryEvent::query()->where('status', 'active')->count())->toBe(1)
        ->and(StoryEvent::query()->where('status', 'invalidated')->count())->toBe(1);
});

test('rollback skips correction versions from the same chapter when restoring state', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    $committed = app(CanonicalCommitService::class)->commit($fixture['data']);
    $correction = StoryStateVersion::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'version' => 2,
        'state' => $committed->state,
        'checksum' => $committed->checksum,
    ]);
    $fixture['novel']->update(['canonical_state_version_id' => $correction->getKey()]);

    $result = app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '撤销该章及其更正');

    expect($result['from_state_version'])->toBe(2)
        ->and($result['to_state_version'])->toBe(0)
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($fixture['state']->getKey());
});

test('latest canonical chapter rollback requires a reason', function () {
    $fixture = canonicalCommitFixture();
    app(CanonicalCommitService::class)->commit($fixture['data']);

    expect(fn () => app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], ' '))
        ->toThrow(ValidationException::class, '必须填写回滚原因');
});

test('rollback failure leaves canonical data unchanged', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    app(CanonicalCommitService::class)->commit($fixture['data']);
    $event = StoryEvent::query()->sole();
    $failingInvalidator = new class extends MemoryInvalidator
    {
        public function invalidateForChapter(int $chapterId): int
        {
            throw new RuntimeException('模拟 Memory 失效失败');
        }
    };

    expect(fn () => (new LatestCanonicalChapterRollback(
        $failingInvalidator,
        app(StoryArcProgressProjector::class),
    ))->rollback($fixture['chapter'], '验证事务回滚'))
        ->toThrow(RuntimeException::class, '模拟 Memory 失效失败');

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBe($fixture['draft']->getKey())
        ->and($fixture['novel']->fresh()->current_chapter_sequence)->toBe(1)
        ->and($event->fresh()->status->value)->toBe('active')
        ->and($event->fresh()->invalidated_at)->toBeNull();
});

test('latest canonical chapter can be rolled back from its workbench', function () {
    $this->actingAs(User::factory()->create());
    $fixture = canonicalCommitFixture();
    app(CanonicalCommitService::class)->commit($fixture['data']);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->assertActionVisible('rollbackLatestCanonical')
        ->callAction('rollbackLatestCanonical', data: ['reason' => '调整结尾节奏'])
        ->assertHasNoActionErrors()
        ->assertNotified('最新正式章节已回滚')
        ->assertActionHidden('rollbackLatestCanonical');

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Void);
});

test('canonical commit applies fact changes with story event provenance', function () {
    $fixture = canonicalCommitFixture(['fact_changes' => [[
        'action' => 'create', 'subject_type' => 'novel', 'subject_id' => 1, 'predicate' => 'has_star_map',
        'value' => true, 'source_event_index' => 0,
    ]]]);

    app(CanonicalCommitService::class)->commit($fixture['data']);

    $fact = Fact::query()->sole();

    expect($fact->source_event_id)->toBe(StoryEvent::query()->sole()->getKey())
        ->and($fact->predicate)->toBe('has_star_map')
        ->and($fact->value)->toBeTrue();
});

test('an identical duplicate canonical commit has exactly once effects', function () {
    $fixture = canonicalCommitFixture();
    $service = app(CanonicalCommitService::class);

    $first = $service->commit($fixture['data']);
    $duplicate = $service->commit($fixture['data']);

    expect($duplicate->is($first))->toBeTrue()
        ->and(StoryEvent::query()->count())->toBe(1)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(2);
});

test('canonical commit rejects a wrong expected state version', function () {
    $fixture = canonicalCommitFixture();
    $data = new CanonicalCommitData(
        $fixture['chapter']->getKey(), $fixture['draft']->getKey(), $fixture['review']->getKey(),
        $fixture['candidate']->getKey(), $fixture['patch']->getKey(), 9, $fixture['draft']->checksum,
    );

    expect(fn () => app(CanonicalCommitService::class)->commit($data))
        ->toThrow(ValidationException::class, 'Expected State Version');

    expect(StoryEvent::query()->count())->toBe(0)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull();
});

test('canonical commit requires a pass review', function () {
    $fixture = canonicalCommitFixture(decision: ReviewDecision::Rewrite);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, 'PASS Review');
});

test('canonical commit rejects a draft below the strict chapter minimum', function () {
    $fixture = canonicalCommitFixture();
    $fixture['chapter']->latestPlan->update(['target_words' => 100]);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, '少于严格下限 85 字');

    expect($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and(StoryEvent::query()->count())->toBe(0);
});

test('canonical commit rejects an overlength draft without an explicit recorded exception', function () {
    $fixture = canonicalCommitFixture();
    $content = $fixture['draft']->content.str_repeat('补', 6);
    $checksum = hash('sha256', $content);
    DB::table('generation_artifacts')->where('id', $fixture['draft']->getKey())->update([
        'content' => $content,
        'checksum' => $checksum,
    ]);
    $data = new CanonicalCommitData(
        $fixture['chapter']->getKey(), $fixture['draft']->getKey(), $fixture['review']->getKey(),
        $fixture['candidate']->getKey(), $fixture['patch']->getKey(), 0, $checksum,
    );

    expect(fn () => app(CanonicalCommitService::class)->commit($data))
        ->toThrow(ValidationException::class, '超过严格上限 23 字');

    expect($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and(StoryEvent::query()->count())->toBe(0);
});

test('canonical commit accepts an overlength draft only with its matching recorded exception', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    $content = $fixture['draft']->content.str_repeat('补', 6);
    $checksum = hash('sha256', $content);
    DB::table('generation_artifacts')->where('id', $fixture['draft']->getKey())->update([
        'content' => $content,
        'checksum' => $checksum,
    ]);
    $finding = [
        'code' => 'CHAPTER_LENGTH_TOO_LONG',
        'severity' => 'warning',
        'message' => '章节超过严格字数上限。',
    ];
    $fixture['review']->update([
        'decision' => ReviewDecision::NeedsAttention,
        'findings' => [$finding],
    ]);
    $accepted = app(AcceptOverlengthChapterAction::class)->execute(
        $fixture['chapter'],
        '该剧情节点不可拆分。',
        7,
    );
    $data = new CanonicalCommitData(
        $fixture['chapter']->getKey(), $fixture['draft']->getKey(), $accepted->getKey(),
        $fixture['candidate']->getKey(), $fixture['patch']->getKey(), 0, $checksum,
    );

    app(CanonicalCommitService::class)->commit($data);

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical)
        ->and($fixture['chapter']->fresh()->word_count)->toBe(24)
        ->and($accepted->fresh()->findings)->toContainEqual($finding)
        ->and(data_get($accepted->artifact->data, 'manual_length_exception'))->toBeTrue()
        ->and(StoryEvent::query()->count())->toBe(1);
});

test('a paused novel cannot create a new canonical commit', function () {
    $fixture = canonicalCommitFixture();
    $fixture['novel']->update(['status' => NovelStatus::Paused]);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, '只有生成中或收束中的小说可以提交正式章节');

    expect($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and(StoryEvent::query()->count())->toBe(0);
});

test('emergency stop blocks canonical commit without leaving partial state', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    app(EmergencyStopService::class)->setActive(true);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, 'Canonical Commit 已被阻止');

    expect($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and(StoryEvent::query()->count())->toBe(0)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('a transaction exception after event writes leaves no partial canonical state', function () {
    $fixture = canonicalCommitFixture(['fact_changes' => [[
        'action' => 'create', 'subject_type' => 'novel', 'subject_id' => 1, 'predicate' => 'has_map',
        'value' => true, 'source_event_index' => 99,
    ]]]);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, '不存在的 Story Event');

    expect(StoryEvent::query()->count())->toBe(0)
        ->and(Fact::query()->count())->toBe(0)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(1)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($fixture['state']->getKey());
});

test('a transaction exception after creating a planned world entity rolls back the whole planning closure', function () {
    $fixture = canonicalCommitFixture();
    $arc = addPlanningClosureToCanonicalFixture($fixture);
    addCharacterIntroductionToCanonicalFixture($fixture);
    $patchData = $fixture['patch']->data;
    $patchData['fact_changes'] = [[
        'action' => 'create',
        'subject_type' => 'novel',
        'subject_id' => $fixture['novel']->getKey(),
        'predicate' => 'has_hidden_star_map',
        'value' => true,
        'source_event_index' => 99,
    ]];
    DB::table('generation_artifacts')->where('id', $fixture['patch']->getKey())->update([
        'data' => json_encode($patchData),
    ]);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, '不存在的 Story Event');

    expect(Character::query()->count())->toBe(0)
        ->and(WorldEntity::query()->count())->toBe(0)
        ->and(StoryEvent::query()->count())->toBe(0)
        ->and(Fact::query()->count())->toBe(0)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(1)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($fixture['state']->getKey())
        ->and($arc->fresh()->progress)->toBe(0.0)
        ->and($arc->fresh()->status)->toBe(StoryArcStatus::Active);
});

test('rollback refuses to delete a chapter introduced character referenced by another canonical chapter', function () {
    $fixture = canonicalCommitFixture();
    addCharacterIntroductionToCanonicalFixture($fixture);
    app(CanonicalCommitService::class)->commit($fixture['data']);
    $character = Character::query()->sole();
    $laterChapter = Chapter::factory()->for($fixture['novel'])->create([
        'sequence' => 2,
        'status' => ChapterStatus::Canonical,
    ]);
    StoryEvent::factory()->for($fixture['novel'])->for($laterChapter)->create([
        'event_type' => EventType::CharacterMoved,
        'subject_type' => 'character',
        'subject_id' => (string) $character->getKey(),
        'state_version' => 2,
    ]);

    expect(fn () => app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '验证后续人物引用保护'))
        ->toThrow(ValidationException::class, '已被其他正式章节引用');

    expect(Character::query()->whereKey($character)->exists())->toBeTrue()
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical);
});

test('an already canonical chapter rejects a conflicting artifact', function () {
    $fixture = canonicalCommitFixture();
    app(CanonicalCommitService::class)->commit($fixture['data']);
    $other = GenerationArtifact::factory()->create(['type' => ArtifactType::ChapterDraft]);
    $conflict = new CanonicalCommitData(
        $fixture['chapter']->getKey(), $other->getKey(), $fixture['review']->getKey(),
        $fixture['candidate']->getKey(), $fixture['patch']->getKey(), 0, $other->checksum,
    );

    expect(fn () => app(CanonicalCommitService::class)->commit($conflict))
        ->toThrow(ValidationException::class, '另一个 Artifact');
});

test('a pass review exposes a canonical commit preview and action', function () {
    $this->actingAs(User::factory()->create());
    $fixture = canonicalCommitFixture();

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->assertSee('当前流水线状态')
        ->assertSee('Review PASS')
        ->assertSee('自动流水线按规则停止')
        ->assertSee('提交正式章节')
        ->assertActionVisible('commitCanonical')
        ->assertActionEnabled('commitCanonical')
        ->callAction('commitCanonical')
        ->assertNotified('章节已提交为正式版本');

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical);
});

test('a legacy pass review with an unaccepted arc completion cannot expose an enabled commit action', function () {
    $this->actingAs(User::factory()->create());
    $fixture = canonicalCommitFixture();
    addPlanningClosureToCanonicalFixture($fixture);
    $reviewData = $fixture['review']->artifact->fresh()->data;
    $reviewData['arc_beat_audits'][0]['status'] = 'missing';
    $reviewData['arc_beat_audits'][0]['evidence'] = null;
    DB::table('generation_artifacts')->where('id', $fixture['review']->artifact->getKey())->update([
        'data' => json_encode($reviewData),
    ]);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->assertActionVisible('commitCanonical')
        ->assertActionDisabled('commitCanonical');
});

test('commit chapter job uses the canonical service and duplicate delivery has exactly once effects', function () {
    $fixture = canonicalCommitFixture();
    $job = new CommitChapterJob($fixture['chapter']->getKey(), $fixture['review']->getKey());

    $job->handle(app(CanonicalCommitService::class));
    $job->handle(app(CanonicalCommitService::class));

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical)
        ->and(StoryEvent::query()->count())->toBe(1)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(2);
});

test('canonical commit dispatches memory update after the formal transaction', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();

    app(CanonicalCommitService::class)->commit($fixture['data']);

    Queue::assertPushed(UpdateMemoryJob::class, fn (UpdateMemoryJob $job): bool => $job->chapterId === $fixture['chapter']->getKey()
        && $job->queue === 'default');
});

test('canonical commit starts the next chapter only after required post commit work succeeds', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    NovelBible::factory()->for($fixture['novel'])->create();
    $fixture['novel']->update(['settings' => ['auto_generate' => true]]);
    Volume::factory()->for($fixture['novel'])->create(['status' => VolumeStatus::Active]);

    $service = app(CanonicalCommitService::class);
    $version = $service->commit($fixture['data']);
    $service->commit($fixture['data']);

    expect($fixture['novel']->chapters()->where('sequence', 2)->doesntExist())->toBeTrue();

    $fixture['chapter']->update(['summary' => '正式章节摘要。']);
    (new ContinueAutoGenerationJob(
        $fixture['chapter']->getKey(),
        $fixture['draft']->getKey(),
        $version->getKey(),
    ))->handle(app(CheckNextAction::class));

    $nextChapter = $fixture['novel']->chapters()->where('sequence', 2)->sole();

    expect($nextChapter->status)->toBe(ChapterStatus::Planned)
        ->and($fixture['novel']->chapters()->where('sequence', '>', 2)->doesntExist())->toBeTrue();

    Queue::assertPushed(PlanChapterJob::class, 1);
    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $nextChapter->getKey());
});
