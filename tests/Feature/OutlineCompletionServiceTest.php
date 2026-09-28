<?php

use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\StoryEventStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\StoryEvent;
use App\Services\OutlineCompletionService;
use App\Services\OutlineHandoffContract;
use Database\Factories\Support\NormalizedOutlineDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function outlineCompletionFixture(bool $withNextBeat = false, bool $withSecondMilestone = false): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $definition = NormalizedOutlineDefinition::make();
    $beat = &$definition['volumes'][0]['arcs'][0]['beats'][0];

    if ($withSecondMilestone) {
        $beat['milestones'][] = [
            'key' => 'factory-milestone-2',
            'sequence' => 2,
            'title' => 'Factory Milestone 2',
            'objective' => '完成第二个里程碑。',
            'acceptance_criteria' => ['第二条件完成。'],
            'must_include' => [],
            'must_not_include' => [],
        ];
    }

    if ($withNextBeat) {
        $beat['handoff'] = [
            'next_beat_key' => 'factory-beat-2',
            'transition_mode' => 'continuous',
            'exit_result' => '通道已经开启。',
            'next_trigger' => '下一阶段开始。',
            'carried_states' => [],
            'open_threads' => [],
            'required_transition' => ['保持行动连续。'],
            'forbidden_jump' => ['不得跳过交接。'],
        ];
        $definition['volumes'][0]['arcs'][0]['beats'][] = [
            'key' => 'factory-beat-2',
            'sequence' => 2,
            'mainline_sequence' => 2,
            'title' => 'Factory Beat 2',
            'summary' => '承接上一阶段。',
            'chapter_budget' => ['min' => 1, 'max' => 2],
            'acceptance_criteria' => ['下一阶段目标完成。'],
            'must_include' => [],
            'must_not_include' => [],
            'character_candidates' => [],
            'world_entity_candidates' => [],
            'milestones' => [[
                'key' => 'factory-milestone-next',
                'sequence' => 1,
                'title' => 'Factory Next Milestone',
                'objective' => '推进下一阶段。',
                'acceptance_criteria' => ['下一阶段条件完成。'],
                'must_include' => [],
                'must_not_include' => [],
            ]],
            'handoff' => [
                'next_beat_key' => null,
                'transition_mode' => null,
                'exit_result' => null,
                'next_trigger' => null,
                'carried_states' => [],
                'open_threads' => [],
                'required_transition' => [],
                'forbidden_jump' => [],
            ],
        ];
    }

    app(CreateNormalizedNovelOutlineVersionAction::class)->handle($novel, $definition);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 1, 'status' => ChapterStatus::Review]);
    $plan = ChapterPlan::factory()->for($chapter)->create(['target_words' => 30]);
    if ($withSecondMilestone) {
        $secondMilestone = $plan->primaryOutlineBeat->milestones()->where('sequence', 2)->firstOrFail();
        $plan->update(['primary_outline_milestone_id' => $secondMilestone->getKey()]);
    }

    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $content = '第一条件已经满足，第二条件也完成，通道已经开启，下一阶段开始并保持行动连续。';
    $draft = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $scene = Scene::factory()->for($chapter)->create([
        'sequence' => 1,
        'current_artifact_id' => $draft->getKey(),
    ]);

    return compact('novel', 'chapter', 'plan', 'draft', 'scene');
}

/** @return array<string, mixed> */
function outlineCompletionPayload(array $fixture, string $milestoneStatus, string $beatStatus, string $handoffStatus): array
{
    $contract = app(OutlineCompletionService::class)->contract($fixture['chapter']);
    $quote = '第一条件已经满足';
    $criterionAudits = fn (array $criteria, string $status): array => collect($criteria)->map(fn (string $criterion): array => [
        'criterion' => $criterion,
        'status' => $status,
        'evidence' => $status === 'not_met' ? null : $quote,
        'scene_id' => $status === 'not_met' ? null : $fixture['scene']->getKey(),
    ])->all();
    $checks = collect($contract['handoff_checks'])->map(fn (array $check): array => [
        ...$check,
        'status' => $handoffStatus === 'not_ready' ? 'not_met' : 'fulfilled',
        'evidence' => $handoffStatus === 'not_ready' ? null : $quote,
        'scene_id' => $handoffStatus === 'not_ready' ? null : $fixture['scene']->getKey(),
    ])->all();

    return [
        'milestone_completion' => [
            'status' => $milestoneStatus,
            'criteria' => $criterionAudits($contract['milestone_criteria'], $milestoneStatus),
        ],
        'beat_exit' => [
            'status' => $beatStatus,
            'criteria' => $criterionAudits($contract['beat_exit_criteria'], $beatStatus),
        ],
        'handoff_readiness' => ['status' => $handoffStatus, 'checks' => $checks],
    ];
}

test('chapter completion remains independent when the current milestone is incomplete', function () {
    $fixture = outlineCompletionFixture();
    $service = app(OutlineCompletionService::class);
    $review = $service->validateReview([
        'chapter_plan_completion' => ['status' => 'fulfilled', 'evidence' => '第一条件已经满足', 'scene_id' => null],
        ...outlineCompletionPayload($fixture, 'not_met', 'not_met', 'not_applicable'),
    ], $fixture['chapter'], $fixture['draft']);
    $extraction = $service->validateExtraction(
        outlineCompletionPayload($fixture, 'not_met', 'not_met', 'not_applicable'),
        $fixture['chapter'],
        $fixture['draft'],
    );

    expect(data_get($review, 'chapter_plan_completion.status'))->toBe('fulfilled')
        ->and(data_get($review, 'milestone_completion.status'))->toBe('not_met')
        ->and($service->findings($review))->toBe([])
        ->and($service->eventCandidates($extraction, $fixture['chapter'], $fixture['draft']))->toBe([]);
});

test('a completed milestone does not complete its beat without every beat exit condition', function () {
    $fixture = outlineCompletionFixture();
    $service = app(OutlineCompletionService::class);
    $completion = $service->validateExtraction(
        outlineCompletionPayload($fixture, 'fulfilled', 'not_met', 'not_applicable'),
        $fixture['chapter'],
        $fixture['draft'],
    );

    expect(collect($service->eventCandidates($completion, $fixture['chapter'], $fixture['draft']))->pluck('eventType')->all())
        ->toBe([EventType::StoryArcBeatMilestoneCompleted]);
});

test('a final milestone cannot complete its beat while the frozen handoff is missing', function () {
    $fixture = outlineCompletionFixture(withNextBeat: true);
    $service = app(OutlineCompletionService::class);
    $completion = $service->validateExtraction(
        outlineCompletionPayload($fixture, 'fulfilled', 'fulfilled', 'not_ready'),
        $fixture['chapter'],
        $fixture['draft'],
    );

    expect(collect($service->eventCandidates($completion, $fixture['chapter'], $fixture['draft']))->pluck('eventType')->all())
        ->toBe([EventType::StoryArcBeatMilestoneCompleted]);
});

test('all milestone beat and handoff conditions create authoritative completion candidates', function () {
    $fixture = outlineCompletionFixture(withNextBeat: true);
    $service = app(OutlineCompletionService::class);
    $completion = $service->validateExtraction(
        outlineCompletionPayload($fixture, 'fulfilled', 'fulfilled', 'ready'),
        $fixture['chapter'],
        $fixture['draft'],
    );
    $events = $service->eventCandidates($completion, $fixture['chapter'], $fixture['draft']);
    $contract = $service->contract($fixture['chapter']);

    expect(collect($events)->pluck('eventType')->all())->toBe([
        EventType::StoryArcBeatMilestoneCompleted,
        EventType::StoryArcBeatCompleted,
    ])->and($events[1]->subjectId)->toBe((string) data_get($contract, 'identity.story_arc_id'))
        ->and(data_get($events[1]->payload, 'novel_outline_beat_id'))->toBe(data_get($contract, 'identity.novel_outline_beat_id'))
        ->and(data_get($events[1]->payload, 'completion_contract_checksum'))->toBe($contract['checksum'])
        ->and(data_get($events[1]->payload, 'handoff_contract'))->toBe(
            app(OutlineHandoffContract::class)->forBeat($fixture['plan']->primaryOutlineBeat),
        )
        ->and($events[1]->evidence)->not->toBeEmpty();
});

test('beat completion combines historical canonical milestones with the current final milestone', function () {
    $fixture = outlineCompletionFixture(withSecondMilestone: true);
    $service = app(OutlineCompletionService::class);
    $withoutHistory = $service->validateExtraction(
        outlineCompletionPayload($fixture, 'fulfilled', 'fulfilled', 'not_applicable'),
        $fixture['chapter'],
        $fixture['draft'],
    );
    expect(collect($service->eventCandidates($withoutHistory, $fixture['chapter'], $fixture['draft']))->pluck('eventType')->all())
        ->toBe([EventType::StoryArcBeatMilestoneCompleted]);

    $plan = $fixture['chapter']->latestPlan;
    $firstMilestone = $plan->primaryOutlineBeat->milestones()->where('sequence', 1)->firstOrFail();
    $arc = $fixture['novel']->storyArcs()->where('source_outline_arc_id', $plan->primary_outline_arc_id)->firstOrFail();
    StoryEvent::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'event_type' => EventType::StoryArcBeatMilestoneCompleted,
        'subject_type' => 'story_arc',
        'subject_id' => (string) $arc->getKey(),
        'status' => StoryEventStatus::Active,
        'state_version' => 0,
        'novel_outline_id' => $plan->novel_outline_id,
        'novel_outline_arc_id' => $plan->primary_outline_arc_id,
        'novel_outline_beat_id' => $plan->primary_outline_beat_id,
        'novel_outline_milestone_id' => $firstMilestone->getKey(),
    ]);
    $withHistory = $service->validateExtraction(
        outlineCompletionPayload($fixture, 'fulfilled', 'fulfilled', 'not_applicable'),
        $fixture['chapter'],
        $fixture['draft'],
    );

    expect(collect($service->eventCandidates($withHistory, $fixture['chapter'], $fixture['draft']))->pluck('eventType')->all())
        ->toBe([EventType::StoryArcBeatMilestoneCompleted, EventType::StoryArcBeatCompleted]);
});

test('completion audits reject wrong order duplicates and evidence from another chapter scene', function (string $case) {
    $fixture = outlineCompletionFixture(withNextBeat: true);
    $payload = outlineCompletionPayload($fixture, 'fulfilled', 'fulfilled', 'ready');
    if ($case === 'order') {
        $payload['handoff_readiness']['checks'] = array_reverse($payload['handoff_readiness']['checks']);
    } elseif ($case === 'duplicate') {
        $payload['handoff_readiness']['checks'][1] = $payload['handoff_readiness']['checks'][0];
    } else {
        $otherChapter = Chapter::factory()->for($fixture['novel'])->create();
        $otherScene = Scene::factory()->for($otherChapter)->create([
            'current_artifact_id' => $fixture['draft']->getKey(),
        ]);
        $payload['milestone_completion']['criteria'][0]['scene_id'] = $otherScene->getKey();
    }

    expect(fn () => app(OutlineCompletionService::class)->validateExtraction(
        $payload,
        $fixture['chapter'],
        $fixture['draft'],
    ))->toThrow(ValidationException::class);
})->with(['order', 'duplicate', 'cross_scene']);
