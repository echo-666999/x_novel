<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Data\StoryEventCandidate;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Jobs\ExtractStoryEventsJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Services\StoryEventExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function eventExtractionFixture(): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $state = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Generating]);
    ChapterPlan::factory()->for($chapter)->create();
    $character = Character::factory()->for($novel)->create(['name' => '林舟']);
    $assemblyRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $content = '林舟终于抵达洛阳城下。城门在身后关闭。';
    $draft = GenerationArtifact::factory()->for($assemblyRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);

    return compact('novel', 'state', 'chapter', 'character', 'draft');
}

function eventExtractionResponse(array $fixture, array $overrides = []): AiResponse
{
    $event = [
        'event_type' => EventType::CharacterMoved->value,
        'subject_type' => 'character',
        'subject_id' => (string) $fixture['character']->getKey(),
        'payload' => ['from' => '长安', 'to' => '洛阳'],
        'evidence' => [[
            'artifact_id' => $fixture['draft']->getKey(),
            'scene_id' => null,
            'quote' => '林舟终于抵达洛阳城下。',
            'start_offset' => 0,
            'end_offset' => 12,
        ]],
        'story_time' => '第三日黄昏',
        'confidence' => 0.96,
        ...$overrides,
    ];

    return new AiResponse(
        content: json_encode(['events' => [$event]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: ['events' => [$event]],
        inputTokens: 200,
        outputTokens: 100,
        cachedTokens: 0,
        latencyMs: 300,
        providerRequestId: 'event-request',
        model: 'extractor-test',
    );
}

test('story event candidate validates the documented event shape', function () {
    $fixture = eventExtractionFixture();
    $event = eventExtractionResponse($fixture)->structuredData['events'][0];
    $candidate = StoryEventCandidate::fromArray($event);

    expect($candidate->eventType)->toBe(EventType::CharacterMoved)
        ->and($candidate->subjectId)->toBe((string) $fixture['character']->getKey())
        ->and($candidate->confidence)->toBe(0.96)
        ->and($candidate->evidence[0]['artifact_id'])->toBe($fixture['draft']->getKey());
});

test('story event candidate schema supports strict output and restores a JSON payload', function () {
    $fixture = eventExtractionFixture();
    $event = eventExtractionResponse($fixture)->structuredData['events'][0];
    $event['payload'] = '{"from":"长安","to":"洛阳"}';

    $candidate = StoryEventCandidate::fromArray($event);

    expect(data_get(StoryEventCandidate::schema(), 'properties.payload.type'))->toBe('string')
        ->and(data_get(StoryEventCandidate::schema(), 'properties.subject_type.enum'))->toContain('world_entity', null)
        ->and(data_get(StoryEventCandidate::schema(), 'properties.subject_type.enum'))->not->toContain('concept')
        ->and($candidate->payload)->toBe(['from' => '长安', 'to' => '洛阳']);
});

test('story event candidate rejects world entity subtypes with an actionable message', function () {
    $fixture = eventExtractionFixture();
    $event = eventExtractionResponse($fixture)->structuredData['events'][0];
    $event['event_type'] = EventType::WorldRuleRevealed->value;
    $event['subject_type'] = 'concept';

    expect(fn () => StoryEventCandidate::fromArray($event))
        ->toThrow(ValidationException::class, 'subject_type [concept] 不在允许范围内')
        ->and(fn () => StoryEventCandidate::fromArray($event))
        ->toThrow(ValidationException::class, '必须使用 world_entity');
});

test('story event candidate rejects an event and subject type mismatch', function () {
    $fixture = eventExtractionFixture();
    $event = eventExtractionResponse($fixture)->structuredData['events'][0];
    $event['event_type'] = EventType::ForeshadowingReinforced->value;

    expect(fn () => StoryEventCandidate::fromArray($event))
        ->toThrow(ValidationException::class, 'event_type [foreshadowing_reinforced] 要求 subject_type 为 [foreshadowing]，实际为 [character]');
});

test('extractor creates a candidate artifact without changing canonical story state', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());
    $run = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole();

    expect($artifact->type)->toBe(ArtifactType::EventCandidate)
        ->and($artifact->data['status'])->toBe('candidate')
        ->and($artifact->data['source_artifact_id'])->toBe($fixture['draft']->getKey())
        ->and($artifact->data['events'][0]['event_type'])->toBe(EventType::CharacterMoved->value)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->idempotency_key)->toStartWith('events:'.$fixture['draft']->checksum.':'.$fixture['state']->version.':event-extractor-v4')
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.events.items.additionalProperties'))->toBeFalse()
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.events.items.properties.payload.type'))->toBe('string')
        ->and($fake->requests()[0]->systemPrompt)->toContain('内部 type（例如 concept、rule、location、faction）不能作为 subject_type')
        ->and($fake->requests()[0]->systemPrompt)->toContain('foreshadowing_* 事件必须引用对应的 foreshadowing ID')
        ->and($fake->requests()[0]->systemPrompt)->toContain('没有有效主体时必须省略该事件')
        ->and(data_get($run->context_snapshot, 'event_subject_type_rules.promise_made'))->toBe(['relationship'])
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($fixture['state']->getKey())
        ->and($fixture['novel']->storyStateVersions()->count())->toBe(1);
});

test('extractor binds evidence to the authoritative chapter draft instead of trusting a model artifact id', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture, [
        'evidence' => [[
            'artifact_id' => 999999,
            'scene_id' => null,
            'quote' => '林舟终于抵达洛阳城下。',
            'start_offset' => 0,
            'end_offset' => 12,
        ]],
    ]));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect(data_get($artifact->data, 'events.0.evidence.0.artifact_id'))->toBe($fixture['draft']->getKey());
});

test('extractor resolves harmless evidence formatting differences to an exact draft quote', function (string $quote) {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture, [
        'evidence' => [[
            'artifact_id' => $fixture['draft']->getKey(),
            'scene_id' => null,
            'quote' => $quote,
            'start_offset' => null,
            'end_offset' => null,
        ]],
    ]));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect(data_get($artifact->data, 'events.0.evidence.0.quote'))->toBe('林舟终于抵达洛阳城下。城门在身后关闭。');
})->with([
    'outer quotation marks' => '“林舟终于抵达洛阳城下。城门在身后关闭。”',
    'ellipsis excerpt' => '林舟终于抵达洛阳城下。……城门在身后关闭。',
]);

test('duplicate extraction reuses the successful candidate artifact', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture));
    app()->instance(AiProvider::class, $fake);
    $extractor = app(StoryEventExtractor::class);

    $first = $extractor->extract($fixture['chapter']->getKey());
    $second = $extractor->extract($fixture['chapter']->getKey());

    expect($second?->is($first))->toBeTrue()
        ->and($fake->requests())->toHaveCount(1)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->count())->toBe(1);
});

test('retrying extraction recovers a blocked chapter before starting a new run', function () {
    $fixture = eventExtractionFixture();
    $fixture['chapter']->update(['status' => ChapterStatus::Blocked]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture)));

    app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey(), true);

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole()->status)->toBe(RunStatus::Succeeded);
});

test('invalid event evidence is rejected before artifact persistence', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture, [
        'evidence' => [[
            'artifact_id' => $fixture['draft']->getKey(),
            'scene_id' => null,
            'quote' => '正文中不存在的句子',
            'start_offset' => null,
            'end_offset' => null,
        ]],
    ]));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, 'Evidence quote 必须逐字来自当前 Chapter Draft');

    expect(GenerationArtifact::query()->where('type', ArtifactType::EventCandidate)->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole()->status)->toBe(RunStatus::Failed);
});

test('extractor records the candidate index and invalid value for validation failures', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture, [
        'event_type' => EventType::WorldRuleRevealed->value,
        'subject_type' => 'concept',
    ]));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, '第 1 个事件字段 subject_type：subject_type [concept] 不在允许范围内');

    $run = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error_code)->toBe('event_validation_failed')
        ->and($run->error_message)->toContain('第 1 个事件字段 subject_type')
        ->and($run->error_message)->toContain('subject_type [concept]')
        ->and(GenerationArtifact::query()->where('type', ArtifactType::EventCandidate)->count())->toBe(0);
});

test('retryable provider failure is recorded and retried from event extraction', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(new AiProviderException('provider_timeout', 'timeout', true))
        ->enqueue(eventExtractionResponse($fixture));
    app()->instance(AiProvider::class, $fake);
    $job = new ExtractStoryEventsJob($fixture['chapter']->getKey());

    expect(fn () => $job->handle(app(StoryEventExtractor::class)))
        ->toThrow(AiProviderException::class, 'timeout');

    $job->handle(app(StoryEventExtractor::class));

    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->count())->toBe(2)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->latest('id')->first()->status)->toBe(RunStatus::Succeeded);
});

test('stale event extraction run is marked interrupted before recovery', function () {
    $fixture = eventExtractionFixture();
    $stale = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Running,
        'updated_at' => now()->subSeconds((int) config('generation.stalled_run_after_seconds') + 1),
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture)));

    app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect($stale->fresh()->status)->toBe(RunStatus::Failed)
        ->and($stale->fresh()->error_code)->toBe('worker_interrupted')
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->count())->toBe(2);
});

test('paused novel cannot start event extraction', function () {
    $fixture = eventExtractionFixture();
    $fixture['novel']->update(['status' => NovelStatus::Paused]);
    app()->instance(AiProvider::class, new FakeAiProvider);

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '小说已暂停');

    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->count())->toBe(0);
});

test('state version conflict prevents candidate artifact persistence', function () {
    $fixture = eventExtractionFixture();
    $response = eventExtractionResponse($fixture);
    app()->instance(AiProvider::class, new class($fixture['novel'], $response) implements AiProvider
    {
        public function __construct(private Novel $novel, private AiResponse $response) {}

        public function generate(AiRequest $request): AiResponse
        {
            $version = StoryStateVersion::factory()->for($this->novel)->create(['version' => 1]);
            $this->novel->update(['canonical_state_version_id' => $version->getKey()]);

            return $this->response;
        }
    });

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, 'Story State 已变化');

    expect(GenerationArtifact::query()->where('type', ArtifactType::EventCandidate)->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole()->status)->toBe(RunStatus::Failed);
});
