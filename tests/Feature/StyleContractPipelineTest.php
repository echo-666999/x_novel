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

test('assembly review and rewrite keep the style contract frozen by the chapter plan', function () {
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

    foreach ([1 => '甲乙', 2 => '丙丁'] as $sequence => $content) {
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
        'findings' => [[
            'code' => 'STYLE_MISMATCH',
            'dimension' => 'style',
            'severity' => 'warning',
            'scene_id' => null,
            'scope' => 'chapter',
            'auto_fixable' => true,
            'requires_human_decision' => false,
            'message' => '动作力度不足，未达到主文风要求。',
            'evidence' => '组装后的章节正文',
        ]],
    ];
    $fake = (new FakeAiProvider)
        ->enqueue(new AiResponse('组装后的章节正文', null, 10, 10, 0, 10, 'assembly', 'test'))
        ->enqueue(new AiResponse(json_encode($reviewPayload), $reviewPayload, 10, 10, 0, 10, 'review', 'test'))
        ->enqueue(new AiResponse('修订后的章节正文', null, 10, 10, 0, 10, 'rewrite', 'test'));
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

    expect($runs)->toHaveCount(3)
        ->and($runs->pluck('bible_version')->all())->toBe([1, 1, 1])
        ->and($runs->pluck('context_snapshot')->map(fn (array $snapshot): mixed => data_get($snapshot, 'style_contract_checksum'))->unique()->all())->toBe([$expectedChecksum])
        ->and($runs->pluck('context_snapshot')->map(fn (array $snapshot): mixed => data_get($snapshot, 'l4.primary_style.name'))->unique()->all())->toBe(['热血激昂'])
        ->and($fake->requests())->toHaveCount(3)
        ->and(collect($fake->requests())->every(fn ($request): bool => str_contains($request->prompt, '热血激昂') && str_contains($request->prompt, '第一人称')))->toBeTrue()
        ->and(collect($fake->requests())->every(fn ($request): bool => ! str_contains($request->prompt, '冷峻克制')))->toBeTrue();
});
