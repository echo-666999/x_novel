<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Providers\FakeAiProvider;
use App\Data\StateValidationResult;
use App\Enums\ArtifactType;
use App\Enums\BibleStatus;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Services\ChapterAssembler;
use App\Services\ChapterReviewer;
use App\Services\ChapterRewriter;
use App\Services\NarrativeStyleProfile;
use App\Services\StateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('deterministic assembly avoids style prompting while review and rewrite keep the frozen style contract', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $firstBible = NovelBible::factory()->for($novel)->create([
        'tone' => '热血',
        'pov' => '第一人称',
        'tense' => '过去时',
        'style_profile' => [
            ...NovelBible::factory()->make()->style_profile,
            'primary_style' => 'passionate',
            'secondary_styles' => ['light_humorous'],
        ],
    ]);
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 20,
        'status' => ChapterStatus::Generating,
    ]);
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'target_words' => 8,
        'scene_plans' => [],
    ]);

    foreach ([1 => '甲乙丙丁', 2 => '戊己庚辛'] as $sequence => $content) {
        $scene = Scene::factory()->for($chapter)->create([
            'sequence' => $sequence,
            'status' => SceneStatus::Draft,
        ]);
        $run = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
            'stage' => GenerationStage::SceneGeneration,
            'status' => RunStatus::Succeeded,
            'bible_version' => 1,
            'context_snapshot' => ['chapter_plan_id' => $plan->getKey()],
        ]);
        $artifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::SceneDraft,
            'content' => $content,
            'data' => [
                'self_check' => collect(['goal', 'conflict', 'turn', 'outcome'])
                    ->mapWithKeys(fn (string $key): array => [$key => ['status' => 'fulfilled', 'evidence' => $content]])
                    ->all(),
                'foreshadowing_coverage' => [],
            ],
            'checksum' => hash('sha256', $content),
        ]);
        $scene->update(['current_artifact_id' => $artifact->getKey()]);
    }

    $firstBible->update(['status' => BibleStatus::Superseded]);
    NovelBible::factory()->for($novel)->create([
        'version' => 2,
        'tone' => '冷峻',
        'style_profile' => array_replace($firstBible->style_profile, [
            'primary_style' => 'austere',
            'secondary_styles' => [],
        ]),
        'status' => BibleStatus::Current,
    ]);

    $reviewPayload = [
        'recommended_decision' => 'REWRITE',
        'scores' => [
            'continuity' => 90,
            'plan' => 90,
            'character' => 90,
            'progress' => 90,
            'repetition' => 90,
            'pacing' => 90,
            'style' => 90,
        ],
        'dimension_audits' => [
            'continuity' => ['status' => 'pass', 'summary' => '全量检查未发现需要报告的问题。'],
            'plan' => ['status' => 'pass', 'summary' => '全量检查未发现需要报告的问题。'],
            'character' => ['status' => 'pass', 'summary' => '全量检查未发现需要报告的问题。'],
            'progress' => ['status' => 'pass', 'summary' => '全量检查未发现需要报告的问题。'],
            'repetition' => ['status' => 'pass', 'summary' => '全量检查未发现需要报告的问题。'],
            'pacing' => ['status' => 'pass', 'summary' => '全量检查未发现需要报告的问题。'],
            'style' => ['status' => 'issues_found', 'summary' => '已一次列出文风维度发现的全部问题。'],
        ],
        'foreshadowing_audits' => [],
        'chapter_plan_completion' => ['status' => 'fulfilled', 'evidence' => '甲乙丙丁', 'scene_id' => null],
        'milestone_completion' => ['status' => 'not_met', 'criteria' => [[
            'criterion' => '完整父链存在。', 'status' => 'not_met', 'evidence' => null, 'scene_id' => null,
        ]]],
        'beat_exit' => ['status' => 'not_met', 'criteria' => [[
            'criterion' => '计划引用已保存。', 'status' => 'not_met', 'evidence' => null, 'scene_id' => null,
        ]]],
        'handoff_readiness' => ['status' => 'not_applicable', 'checks' => []],
        'arc_beat_audits' => [],
        'arc_completion_audits' => [],
        'character_candidate_audits' => [],
        'world_entity_candidate_audits' => [],
        'unapproved_characters' => [],
        'unapproved_world_entities' => [],
        'findings' => [[
            'code' => 'STYLE_MISMATCH',
            'dimension' => 'style',
            'severity' => 'error',
            'scene_id' => $chapter->scenes()->orderBy('sequence')->value('id'),
            'scope' => 'scene',
            'auto_fixable' => true,
            'requires_human_decision' => false,
            'message' => '动作力度不足，未达到主文风要求。',
            'evidence' => '甲乙丙丁',
        ]],
    ];
    $assembledContent = "甲乙丙丁\n\n戊己庚辛";
    $rewriteContent = '修订正文';
    $rewritePayload = [
        'content' => $rewriteContent,
        'self_check' => collect(['goal', 'conflict', 'turn', 'outcome'])
            ->mapWithKeys(fn (string $key): array => [$key => ['status' => 'fulfilled', 'evidence' => $rewriteContent]])
            ->all(),
    ];
    $fake = (new FakeAiProvider)
        ->enqueue(new AiResponse(json_encode($reviewPayload), $reviewPayload, 10, 10, 0, 10, 'review', 'test'))
        ->enqueue(new AiResponse(json_encode($rewritePayload), $rewritePayload, 10, 10, 0, 10, 'rewrite', 'test'));
    app()->instance(AiProvider::class, $fake);
    $validator = Mockery::mock(StateValidator::class);
    $validator->shouldReceive('validate')->once()->andReturn(new StateValidationResult([]));
    app()->instance(StateValidator::class, $validator);

    app(ChapterAssembler::class)->assemble($chapter->getKey());
    app(ChapterReviewer::class)->review($chapter->getKey());
    app(ChapterRewriter::class)->rewrite($chapter->getKey());

    $runs = $chapter->generationRuns()
        ->whereIn('stage', [GenerationStage::ChapterAssembly, GenerationStage::Review, GenerationStage::Rewrite])
        ->oldest('id')
        ->get();
    $expectedChecksum = app(NarrativeStyleProfile::class)->contractForBible($firstBible)['checksum'];
    $providerRuns = $runs->whereIn('stage', [GenerationStage::Review, GenerationStage::Rewrite]);
    $foreshadowingChecksums = $providerRuns->pluck('context_snapshot')
        ->map(fn (array $snapshot): mixed => data_get($snapshot, 'foreshadowing_contract_checksum'));

    expect($runs)->toHaveCount(3)
        ->and($runs->pluck('bible_version')->all())->toBe([1, 1, 1])
        ->and(data_get($runs->first()->context_snapshot, 'assembly_algorithm_version'))->toBe(ChapterAssembler::ALGORITHM_VERSION)
        ->and(data_get($runs->first()->context_snapshot, 'style_contract_checksum'))->toBeNull()
        ->and($providerRuns->pluck('context_snapshot')->map(fn (array $snapshot): mixed => data_get($snapshot, 'style_contract_checksum'))->unique()->all())->toBe([$expectedChecksum])
        ->and($foreshadowingChecksums->filter()->count())->toBe(2)
        ->and($foreshadowingChecksums->unique()->count())->toBe(1)
        ->and($providerRuns->pluck('context_snapshot')->every(fn (array $snapshot): bool => data_get($snapshot, 'foreshadowing_contract.chapter_plan_id') === $plan->getKey()))->toBeTrue()
        ->and($providerRuns->pluck('context_snapshot')->map(fn (array $snapshot): mixed => data_get($snapshot, 'l4.primary_style.name'))->unique()->all())->toBe(['热血激昂'])
        ->and($fake->requests())->toHaveCount(2)
        ->and(collect($fake->requests())->every(fn ($request): bool => str_contains($request->prompt, '热血激昂') && str_contains($request->prompt, '第一人称')))->toBeTrue()
        ->and(collect($fake->requests())->every(fn ($request): bool => ! str_contains($request->prompt, '冷峻克制')))->toBeTrue();
});
