<?php

use App\AI\AiSettingsResolver;
use App\AI\PromptVersionResolver;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Models\AIModelPrice;
use App\Models\AIModelRoute;
use App\Models\AIProviderConnection;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Services\GenerationRequestBudget;
use App\Services\GenerationSettingsService;
use App\Services\OutlineProgressResolver;
use App\Services\PlanAdmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function attachCanonicalArtifact(Novel $novel, Chapter $chapter): GenerationArtifact
{
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::Commit,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterDraft,
    ]);
    $chapter->update(['canonical_artifact_id' => $artifact->getKey()]);

    return $artifact;
}

function seedVerifiedOutlineModelProfiles(): void
{
    createAiProviderConnectionForTest();
    collect([
        AiStage::OutlineFoundation,
        AiStage::OutlineStructure,
        AiStage::OutlineArcBeats,
        AiStage::OutlineBeatDetail,
    ])->each(fn (AiStage $stage) => createAiModelRouteForTest($stage));
}

function seedVerifiedChapterModelProfiles(): void
{
    createAiProviderConnectionForTest();
    collect([
        AiStage::Planner,
        AiStage::Writer,
        AiStage::Extractor,
        AiStage::Reviewer,
        AiStage::Rewrite,
        AiStage::Summary,
    ])->each(fn (AiStage $stage) => createAiModelRouteForTest($stage));
}

function createAiProviderConnectionForTest(
    string $provider = 'openai',
    ?string $baseUrl = null,
    ?string $apiKey = null,
    bool $enabled = true,
): AIProviderConnection {
    $baseUrl ??= $provider === 'deepseek' ? 'https://deepseek.example' : 'https://openai.example/v1';

    return AIProviderConnection::query()->updateOrCreate(['provider' => $provider], [
        'name' => ucfirst($provider),
        'base_url' => $baseUrl,
        'api_key' => $apiKey ?? "{$provider}-test-key",
        'connect_timeout' => 10,
        'timeout' => 150,
        'is_enabled' => $enabled,
    ]);
}

function createAiModelRouteForTest(
    AiStage $stage,
    ?string $model = null,
    string $provider = 'openai',
    ?string $reasoningEffort = null,
): AIModelRoute {
    $model ??= "test-{$stage->value}-model";
    AIModelPrice::query()->updateOrCreate([
        'provider' => $provider,
        'model' => $model,
        'currency' => app(GenerationSettingsService::class)->currency(),
    ], [
        'billing_unit' => 1_000_000,
        'context_window_tokens' => 1_050_000,
        'max_output_tokens' => 128_000,
        'supports_structured_output' => true,
        'supports_reasoning_effort' => true,
        'input_price' => 0,
        'cached_input_price' => 0,
        'output_price' => 0,
        'is_enabled' => true,
    ]);

    return AIModelRoute::query()->updateOrCreate(['role' => $stage->value], [
        'provider' => $provider,
        'model' => $model,
        'reasoning_effort' => $reasoningEffort,
    ]);
}

function seedAllAiModelRoutesForTest(): void
{
    createAiProviderConnectionForTest();
    collect(AiStage::cases())->each(fn (AiStage $stage) => createAiModelRouteForTest($stage));
}

function configureEmbeddingForTest(string $model = 'embedding-fixed-v1', int $dimensions = 3): void
{
    createAiProviderConnectionForTest();
    createAiModelRouteForTest(AiStage::Embedding, $model);
    $service = app(GenerationSettingsService::class);
    $settings = $service->settings();
    $settings['embedding_dimensions'] = $dimensions;
    $service->save($settings, null);
}

function makeChapterPlanAdmissionReady(ChapterPlan $plan): ChapterPlan
{
    seedVerifiedChapterModelProfiles();
    $plan->loadMissing('chapter.novel');
    $chapter = $plan->chapter;
    $novel = $chapter->novel;
    $pov = Character::query()->where('novel_id', $novel->getKey())->first()
        ?? Character::factory()->for($novel)->create();
    $target = app(OutlineProgressResolver::class)->resolve($novel->fresh());
    $scenePlans = array_values($plan->scene_plans ?: [[
        'goal' => '完成当前场景目标',
        'conflict' => '处理当前场景冲突',
        'turn' => '触发当前场景转折',
        'outcome' => '取得当前场景结果',
        'outcome_allowed' => [],
        'outcome_forbidden' => [],
        'continuity_requirements' => [],
        'transition_from_previous' => null,
    ]]);

    // 运行阶段测试也必须建立真实可 Admission 的 Plan，不能再依赖旧的隐式全局路由回退。
    $plan->update([
        'status' => PlanStatus::Ready,
        'pov_character_id' => $pov->getKey(),
        'scene_plans' => $scenePlans,
        'arc_contributions' => [[
            'role' => 'primary',
            'arc_id' => $target->arcId,
            'beat_key' => $target->beat['key'],
            'beat_index' => $target->beat['sequence'],
            'milestone_key' => $target->milestone['key'],
            'milestone_sequence' => $target->milestone['sequence'],
            'target_scene_sequence' => 1,
            'acceptance_criteria' => $target->milestone['acceptance_criteria'][0],
        ]],
        'must_reveal' => $target->milestone['must_include'],
        'must_not_reveal' => array_values(array_unique([
            ...$target->beat['must_not_include'],
            ...$target->milestone['must_not_include'],
        ])),
    ]);

    return $plan->fresh();
}

function admitChapterPlanForTest(ChapterPlan $plan): ChapterPlan
{
    return app(PlanAdmissionService::class)->admit(makeChapterPlanAdmissionReady($plan));
}

function freezeChapterRouteContractsForTest(ChapterPlan $plan): ChapterPlan
{
    seedVerifiedChapterModelProfiles();
    $plan->loadMissing('chapter.novel');
    $routes = [];
    foreach ([AiStage::Writer, AiStage::Extractor, AiStage::Reviewer, AiStage::Rewrite, AiStage::Summary] as $stage) {
        $settings = app(AiSettingsResolver::class)->resolve($stage, $plan->chapter->novel);
        $profile = AIModelPrice::findEnabledForRoute($settings->provider, $settings->model);
        $routes[$stage->value] = [
            'provider' => $settings->provider,
            'model' => $settings->model,
            'reasoning_effort' => $settings->reasoningEffort,
            'prompt_version' => app(PromptVersionResolver::class)->resolve($stage),
            'model_capacity' => $profile?->capacitySnapshot(),
            'request_budgets' => app(GenerationRequestBudget::class)->configured($stage),
            // 单阶段测试同样冻结修复预算，避免夹具绕过生产环境的子阶段合同。
            'repair_request_budgets' => app(GenerationRequestBudget::class)->configuredRepairs($stage),
        ];
    }

    // 聚焦单阶段行为的测试只冻结 Route 合同，完整 Admission 语义由 PlanAdmissionServiceTest 单独覆盖。
    $plan->update([
        'admission_snapshot' => [
            'schema_version' => 2,
            'routes' => $routes,
            'capacity' => ['review' => ['context_token_budget' => app(GenerationSettingsService::class)->reviewContextTokenBudget()]],
        ],
        'admitted_at' => now(),
    ]);

    return $plan->fresh();
}
