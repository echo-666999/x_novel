<?php

use App\Actions\Chapters\ReconcileOutlineCompletionAction;
use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\StoryEventStatus;
use App\Jobs\ReviewChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\Scene;
use App\Models\StoryEvent;
use App\Services\ChapterReviewer;
use App\Services\CompactReviewPayload;
use App\Services\OutlineCompletionService;
use App\Services\OutlineHandoffContract;
use App\Services\StatePatchBuilder;
use App\Services\StoryEventEvidenceQuoteResolver;
use Database\Factories\Support\NormalizedOutlineDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
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

test('a pass review that overclaims handoff readiness gets a conservative immutable review', function () {
    $fixture = outlineCompletionFixture(withNextBeat: true);
    $service = app(OutlineCompletionService::class);
    $extraction = $service->validateExtraction(
        outlineCompletionPayload($fixture, 'not_met', 'fulfilled', 'not_ready'),
        $fixture['chapter'],
        $fixture['draft'],
    );
    $eventRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
        'state_version' => 0,
    ]);
    $candidateData = [
        'status' => 'candidate',
        'source_artifact_id' => $fixture['draft']->getKey(),
        'outline_completion' => $extraction,
        'events' => [],
    ];
    $candidate = GenerationArtifact::factory()->for($eventRun)->create([
        'type' => ArtifactType::EventCandidate,
        'data' => $candidateData,
        'content' => '[]',
        'checksum' => hash('sha256', json_encode($candidateData)),
    ]);
    $patch = app(StatePatchBuilder::class)->build($fixture['chapter']->getKey());
    $reviewPayload = $service->validateReview([
        'chapter_plan_completion' => ['status' => 'fulfilled', 'evidence' => '第一条件已经满足', 'scene_id' => null],
        ...outlineCompletionPayload($fixture, 'not_met', 'fulfilled', 'ready'),
    ], $fixture['chapter'], $fixture['draft']);
    $reviewData = [...$reviewPayload, 'source_artifact_id' => $fixture['draft']->getKey(), 'decision' => 'PASS', 'findings' => []];
    $reviewRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
        'state_version' => 0,
    ]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create([
        'type' => ArtifactType::ReviewResult,
        'data' => $reviewData,
        'content' => json_encode($reviewData),
        'checksum' => hash('sha256', json_encode($reviewData)),
    ]);
    $sourceReview = Review::factory()->for($reviewRun, 'generationRun')->create([
        'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::Pass,
    ]);

    $action = app(ReconcileOutlineCompletionAction::class);
    expect($action->preview($fixture['chapter']->fresh()))->toBe(['handoff_readiness']);
    $reviewer = Mockery::mock(ChapterReviewer::class);
    $reviewer->shouldReceive('review')->once()->andReturn($sourceReview);
    $advance = Mockery::mock(AdvanceChapterPipelineAction::class);
    $advance->shouldReceive('handle')->once()->with($fixture['chapter']->getKey());
    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle($reviewer, $advance);
    $alignedReview = Review::query()->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $fixture['chapter']->getKey()))
        ->latest('id')->firstOrFail();
    [$alignedCandidate, $alignedPatch] = $action->handle($fixture['chapter']);

    expect($alignedCandidate->getKey())->toBe($candidate->getKey())
        ->and($alignedPatch->getKey())->toBe($patch->getKey())
        ->and($alignedReview->getKey())->not->toBe($sourceReview->getKey())
        ->and(data_get($sourceReview->artifact->fresh()->data, 'handoff_readiness.status'))->toBe('ready')
        ->and(data_get($alignedReview->artifact->data, 'handoff_readiness.status'))->toBe('not_ready')
        ->and($alignedReview->decision)->toBe(ReviewDecision::Pass)
        ->and($action->preview($fixture['chapter']->fresh()))->toBe([])
        ->and($action->handle($fixture['chapter'])[2]->getKey())->toBe($alignedReview->getKey());

    $service->validateCommit($fixture['chapter'], $alignedReview->artifact->data, $alignedCandidate->data, []);
});

test('compact completion evidence restores paragraph whitespace from the authoritative draft', function () {
    $fixture = outlineCompletionFixture();
    $content = "第一条件已经满足。\n\n第二条件也完成。";
    $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $fixture['draft'] = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $fixture['scene']->update(['current_artifact_id' => $fixture['draft']->getKey()]);
    $service = app(OutlineCompletionService::class);
    $contract = $service->contract($fixture['chapter']);
    $audit = fn (array $items): array => collect($items)->map(fn (): array => [
        'status' => 'fulfilled',
        'evidence' => '第一条件已经满足。第二条件也完成。',
    ])->all();

    $expanded = $service->expandCompactExtraction([
        'milestone_completion' => $audit($contract['milestone_criteria']),
        'beat_exit' => $audit($contract['beat_exit_criteria']),
        'handoff_readiness' => [],
    ], $fixture['chapter'], $fixture['draft']->fresh());
    $validated = $service->validateExtraction($expanded, $fixture['chapter'], $fixture['draft']->fresh());

    expect(data_get($validated, 'milestone_completion.criteria.0.evidence'))->toBe($content)
        ->and(data_get($validated, 'milestone_completion.criteria.0.scene_id'))->toBe($fixture['scene']->getKey());
});

test('compact completion evidence restores escaped paragraph breaks and omitted dialogue into one current scene quote', function () {
    $fixture = outlineCompletionFixture(withNextBeat: true);
    $content = "卫子夫说明这是查验病因的暂行办法。\n\n随侍问。\n\n卫子夫说年长宫人只是暂代，不正式定下。";
    $fixture['draft'] = GenerationArtifact::factory()->for($fixture['draft']->generationRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'version' => 2,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $fixture['scene']->update(['current_artifact_id' => $fixture['draft']->getKey()]);
    $service = app(OutlineCompletionService::class);
    $contract = $service->contract($fixture['chapter']);
    $missing = fn (array $items): array => collect($items)->map(fn (): array => [
        'status' => 'not_met', 'evidence' => null,
    ])->all();
    $handoff = $missing($contract['handoff_checks']);
    $handoff[0] = [
        'status' => 'fulfilled',
        'evidence' => '卫子夫说明这是查验病因的暂行办法。\\n\\n卫子夫说年长宫人只是暂代，不正式定下。',
    ];

    $expanded = $service->expandCompactExtraction([
        'milestone_completion' => $missing($contract['milestone_criteria']),
        'beat_exit' => $missing($contract['beat_exit_criteria']),
        'handoff_readiness' => $handoff,
    ], $fixture['chapter'], $fixture['draft']->fresh());
    $validated = $service->validateExtraction($expanded, $fixture['chapter'], $fixture['draft']->fresh());

    expect(data_get($validated, 'handoff_readiness.checks.0.evidence'))->toBe($content)
        ->and(data_get($validated, 'handoff_readiness.checks.0.scene_id'))->toBe($fixture['scene']->getKey());
});

test('compact completion evidence restores a short omitted dialogue attribution from one scene', function () {
    $fixture = outlineCompletionFixture();
    $content = "“这一笔，今晨进乳时辰、当班人、柴薪数，都记上了？”卫子夫问。\n\n小黄门在殿角答：“记上了。殿下未吐，腿未蜷，只动了几下。旧乳具、旧桶、旧瓢仍封着，封泥未裂。”";
    $fixture['draft'] = GenerationArtifact::factory()->for($fixture['draft']->generationRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'version' => 2,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $fixture['scene']->update(['current_artifact_id' => $fixture['draft']->getKey()]);
    $service = app(OutlineCompletionService::class);
    $contract = $service->contract($fixture['chapter']);
    $missing = fn (array $items): array => collect($items)->map(fn (): array => [
        'status' => 'not_met', 'evidence' => null,
    ])->all();
    $milestone = $missing($contract['milestone_criteria']);
    $milestone[0] = [
        'status' => 'fulfilled',
        'evidence' => '“这一笔，今晨进乳时辰、当班人、柴薪数，都记上了？” 小黄门在殿角答：“记上了。殿下未吐，腿未蜷，只动了几下。旧乳具、旧桶、旧瓢仍封着，封泥未裂。”',
    ];

    $expanded = $service->expandCompactExtraction([
        'milestone_completion' => $milestone,
        'beat_exit' => $missing($contract['beat_exit_criteria']),
        'handoff_readiness' => [],
    ], $fixture['chapter'], $fixture['draft']->fresh());
    $validated = $service->validateExtraction($expanded, $fixture['chapter'], $fixture['draft']->fresh());

    expect(data_get($validated, 'milestone_completion.criteria.0.evidence'))
        ->toBe(mb_substr($content, 1, mb_strlen($content) - 2))
        ->and(data_get($validated, 'milestone_completion.criteria.0.scene_id'))->toBe($fixture['scene']->getKey());
});

test('omitted dialogue attribution is not recovered from distant or ambiguous passages', function () {
    $resolver = app(StoryEventEvidenceQuoteResolver::class);
    $first = '“这一笔，今晨进乳时辰、当班人、柴薪数，都记上了？”';
    $second = '小黄门在殿角答：“记上了。殿下未吐，腿未蜷，只动了几下。”';
    $quote = $first.' '.$second;
    $distant = $first.str_repeat('中间还有其他重要情节。', 8).$second;
    $ambiguous = $first.'卫子夫问。'.$second.'\n\n'.$first.'卫子夫再问。'.$second;

    expect($resolver->resolve($distant, $quote))->not->toBe($distant)
        ->and($resolver->resolve($ambiguous, $quote))->not->toContain('卫子夫问。');
});

test('compact completion evidence spanning two scenes remains invalid', function () {
    $fixture = outlineCompletionFixture();
    $first = '卫子夫下令将用具分开。';
    $second = '年长宫人继续照料刘据。';
    $content = $first."\n\n".$second;
    $firstArtifact = GenerationArtifact::factory()->for($fixture['draft']->generationRun)->create([
        'type' => ArtifactType::SceneDraft,
        'content' => $first,
    ]);
    $secondArtifact = GenerationArtifact::factory()->for($fixture['draft']->generationRun)->create([
        'type' => ArtifactType::SceneDraft,
        'version' => 2,
        'content' => $second,
    ]);
    $draft = GenerationArtifact::factory()->for($fixture['draft']->generationRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'version' => 2,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $fixture['scene']->update(['current_artifact_id' => $firstArtifact->getKey()]);
    Scene::factory()->for($fixture['chapter'])->create([
        'sequence' => 2,
        'current_artifact_id' => $secondArtifact->getKey(),
    ]);
    $service = app(OutlineCompletionService::class);
    $contract = $service->contract($fixture['chapter']);
    $missing = fn (array $items): array => collect($items)->map(fn (): array => [
        'status' => 'not_met', 'evidence' => null,
    ])->all();
    $milestone = $missing($contract['milestone_criteria']);
    $milestone[0] = [
        'status' => 'fulfilled',
        'evidence' => $first.'\\n\\n'.$second,
    ];

    $expanded = $service->expandCompactExtraction([
        'milestone_completion' => $milestone,
        'beat_exit' => $missing($contract['beat_exit_criteria']),
        'handoff_readiness' => [],
    ], $fixture['chapter'], $draft);

    expect(data_get($expanded, 'milestone_completion.criteria.0.scene_id'))->toBeNull()
        ->and(fn () => $service->validateExtraction($expanded, $fixture['chapter'], $draft))
        ->toThrow(ValidationException::class, '必须绑定当前章节 Scene');
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

test('the outgoing beat defers next beat transitions without losing its completion event', function () {
    $fixture = outlineCompletionFixture(withNextBeat: true);
    $service = app(OutlineCompletionService::class);
    $payload = outlineCompletionPayload($fixture, 'fulfilled', 'fulfilled', 'ready');
    foreach ($payload['handoff_readiness']['checks'] as &$check) {
        if (str_starts_with($check['key'], 'required_transition:')) {
            $check['status'] = 'not_applicable';
            $check['evidence'] = null;
            $check['scene_id'] = null;
        }
    }
    unset($check);

    $completion = $service->validateExtraction($payload, $fixture['chapter'], $fixture['draft']);

    expect(collect($service->eventCandidates($completion, $fixture['chapter'], $fixture['draft']))
        ->pluck('eventType')->all())->toBe([
            EventType::StoryArcBeatMilestoneCompleted,
            EventType::StoryArcBeatCompleted,
        ]);

    $compact = [
        'milestone_completion' => collect($payload['milestone_completion']['criteria'])->map(fn (array $audit): array => Arr::only($audit, ['status', 'evidence']))->all(),
        'beat_exit' => collect($payload['beat_exit']['criteria'])->map(fn (array $audit): array => Arr::only($audit, ['status', 'evidence']))->all(),
        'handoff_readiness' => collect($payload['handoff_readiness']['checks'])->map(fn (array $audit): array => Arr::only($audit, ['status', 'evidence']))->all(),
    ];
    expect(data_get($service->expandCompactExtraction($compact, $fixture['chapter'], $fixture['draft']), 'handoff_readiness.status'))
        ->toBe('ready');
});

test('compact reviewer uses the same outgoing handoff rule as extraction and validation', function () {
    $fixture = outlineCompletionFixture(withNextBeat: true);
    $service = app(OutlineCompletionService::class);
    $contract = $service->contract($fixture['chapter']);
    $quote = '第一条件已经满足';
    $audit = fn (string $status): array => [
        'status' => $status,
        'evidence' => $status === 'fulfilled' ? $quote : null,
    ];
    $compact = [
        'scores' => array_fill_keys(['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'], 90),
        'chapter_plan_completion' => $audit('fulfilled'),
        'milestone_completion' => array_map(fn (): array => $audit('not_met'), $contract['milestone_criteria']),
        'beat_exit' => array_map(fn (): array => $audit('not_met'), $contract['beat_exit_criteria']),
        'handoff_readiness' => array_map(
            fn (array $check): array => $audit(str_starts_with($check['key'], 'required_transition:') ? 'not_applicable' : 'fulfilled'),
            $contract['handoff_checks'],
        ),
        'foreshadowing_audits' => [],
        'arc_beat_audits' => collect($fixture['plan']->arc_contributions ?? [])->map(fn (): array => $audit('not_met'))->all(),
        'arc_completion_audits' => collect($fixture['plan']->arc_contributions ?? [])->pluck('arc_id')->unique()->map(fn (): array => $audit('not_met'))->all(),
        'character_candidate_audits' => collect($fixture['plan']->character_candidates ?? [])->map(fn (): array => ['status' => 'missing', 'evidence' => null])->all(),
        'world_entity_candidate_audits' => collect($fixture['plan']->world_entity_candidates ?? [])->map(fn (): array => ['status' => 'missing', 'evidence' => null])->all(),
        'unapproved_world_entities' => [],
        'unapproved_characters' => [],
        'findings' => [],
    ];
    $expanded = CompactReviewPayload::expand($compact, $fixture['chapter'], $fixture['draft'], $contract, []);

    expect(data_get($expanded, 'handoff_readiness.status'))->toBe('ready')
        ->and(data_get($service->validateReview($expanded, $fixture['chapter'], $fixture['draft']), 'handoff_readiness.status'))
        ->toBe('ready');
});

test('a contradicted transition or missing outgoing trigger keeps the handoff closed', function (string $key, string $status) {
    $fixture = outlineCompletionFixture(withNextBeat: true);
    $payload = outlineCompletionPayload($fixture, 'fulfilled', 'fulfilled', 'not_ready');
    foreach ($payload['handoff_readiness']['checks'] as &$check) {
        $check['status'] = $check['key'] === $key ? $status : 'fulfilled';
        $check['evidence'] = in_array($check['status'], ['fulfilled', 'contradicted'], true)
            ? '第一条件已经满足'
            : null;
        $check['scene_id'] = $check['evidence'] === null ? null : $fixture['scene']->getKey();
    }
    unset($check);

    $completion = app(OutlineCompletionService::class)->validateExtraction($payload, $fixture['chapter'], $fixture['draft']);
    expect(data_get($completion, 'handoff_readiness.status'))->toBe('not_ready')
        ->and(collect(app(OutlineCompletionService::class)->eventCandidates($completion, $fixture['chapter'], $fixture['draft']))
            ->pluck('eventType')->all())->toBe([EventType::StoryArcBeatMilestoneCompleted]);
})->with([
    ['required_transition:0', 'contradicted'],
    ['next_trigger', 'not_met'],
]);

test('a previously completed final milestone produces only a beat exit event', function () {
    $fixture = outlineCompletionFixture(withNextBeat: true);
    $service = app(OutlineCompletionService::class);
    $plan = $fixture['plan'];
    $arc = $fixture['novel']->storyArcs()->where('source_outline_arc_id', $plan->primary_outline_arc_id)->firstOrFail();
    StoryEvent::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'event_type' => EventType::StoryArcBeatMilestoneCompleted,
        'subject_type' => 'story_arc',
        'subject_id' => (string) $arc->getKey(),
        'status' => StoryEventStatus::Active,
        'novel_outline_id' => $plan->novel_outline_id,
        'novel_outline_arc_id' => $plan->primary_outline_arc_id,
        'novel_outline_beat_id' => $plan->primary_outline_beat_id,
        'novel_outline_milestone_id' => $plan->primary_outline_milestone_id,
    ]);
    $completion = $service->validateExtraction(
        outlineCompletionPayload($fixture, 'not_met', 'fulfilled', 'ready'),
        $fixture['chapter'],
        $fixture['draft'],
    );
    $events = $service->eventCandidates($completion, $fixture['chapter'], $fixture['draft']);
    $review = $service->validateReview([
        'chapter_plan_completion' => ['status' => 'fulfilled', 'evidence' => '第一条件已经满足', 'scene_id' => null],
        ...outlineCompletionPayload($fixture, 'not_met', 'fulfilled', 'ready'),
    ], $fixture['chapter'], $fixture['draft']);
    $review['source_artifact_id'] = $fixture['draft']->getKey();

    expect(collect($events)->pluck('eventType')->all())->toBe([EventType::StoryArcBeatCompleted])
        ->and($service->validateCommit($fixture['chapter'], $review, ['outline_completion' => $completion], $events))
        ->toBeArray();

    $contradicted = $service->validateExtraction(
        outlineCompletionPayload($fixture, 'contradicted', 'fulfilled', 'ready'),
        $fixture['chapter'],
        $fixture['draft'],
    );
    expect($service->eventCandidates($contradicted, $fixture['chapter'], $fixture['draft']))->toBe([]);
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
