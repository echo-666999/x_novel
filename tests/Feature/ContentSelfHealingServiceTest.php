<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\Enums\ArtifactType;
use App\Enums\ContentRepairType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Jobs\ExtractStoryEventsJob;
use App\Models\AIModelPrice;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Services\AutomaticGenerationRecoveryService;
use App\Services\AutoStopService;
use App\Services\ContentSelfHealingService;
use App\Services\GenerationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function selfHealingChapter(): Chapter
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create(['version' => 1]);

    return Chapter::factory()->for($novel)->create();
}

test('each repair type enforces its own attempt limit and persists the refusal', function () {
    config()->set('generation.content_self_healing.plan_validation.max_attempts', 1);
    $chapter = selfHealingChapter();
    $service = app(ContentSelfHealingService::class);
    $first = $service->start($chapter, ContentRepairType::PlanValidation, ['planning_run_id' => 10]);
    $service->fail($first, 'candidate_invalid', '第一次候选仍无效。');

    expect(fn () => $service->start($chapter, ContentRepairType::PlanValidation, ['planning_run_id' => 11]))
        ->toThrow(AiProviderException::class, '已达到 1 次上限');

    $runs = $chapter->generationRuns()->where('stage', GenerationStage::ContentRepair)->oldest('id')->get();
    expect($runs)->toHaveCount(2)
        ->and($runs[0]->status)->toBe(RunStatus::Failed)
        ->and($runs[1]->error_code)->toBe('content_repair_attempt_limit_exceeded')
        ->and($runs[1]->artifacts()->where('type', ArtifactType::Context)->count())->toBe(1);
});

test('repair request is rejected before provider use when worst case cost exceeds its cap', function () {
    config()->set('generation.content_self_healing.chapter_structure.max_cost', 0.000001);
    $chapter = selfHealingChapter();
    $currency = app(GenerationSettingsService::class)->currency();
    AIModelPrice::query()->create([
        'provider' => 'openai',
        'model' => 'repair-test-model',
        'currency' => $currency,
        'billing_unit' => 1_000_000,
        'context_window_tokens' => 100_000,
        'max_output_tokens' => 10_000,
        'supports_structured_output' => true,
        'supports_reasoning_effort' => true,
        'input_price' => 1,
        'cached_input_price' => 0.5,
        'output_price' => 10,
        'is_enabled' => true,
    ]);
    $service = app(ContentSelfHealingService::class);
    $run = $service->start($chapter, ContentRepairType::ChapterStructure, ['review_id' => 1]);

    expect(fn () => $service->assertCanRequest(new AiRequest(
        model: 'repair-test-model',
        provider: 'openai',
        prompt: str_repeat('正文', 100),
        maxTokens: 1_000,
        metadata: ['chapter_id' => $chapter->getKey()],
    )))->toThrow(AiProviderException::class, '费用预检失败');

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->error_code)->toBe('content_repair_cost_limit_exceeded');
});

test('repair elapsed time includes queue waiting and stops after the configured limit', function () {
    config()->set('generation.content_self_healing.rewrite_exhaustion.max_elapsed_seconds', 60);
    $chapter = selfHealingChapter();
    $service = app(ContentSelfHealingService::class);
    $run = $service->start($chapter, ContentRepairType::RewriteExhaustion, ['review_id' => 1]);
    $this->travel(61)->seconds();

    expect(fn () => $service->assertWithinLimits($run->fresh()))
        ->toThrow(AiProviderException::class, '已超过 60 秒上限');

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->error_code)->toBe('content_repair_time_limit_exceeded');
});

test('scheduled recovery closes an abandoned repair after its time limit', function () {
    config()->set('generation.content_self_healing.chapter_structure.max_elapsed_seconds', 60);
    $chapter = selfHealingChapter();
    $run = app(ContentSelfHealingService::class)->start(
        $chapter,
        ContentRepairType::ChapterStructure,
        ['review_id' => 1],
    );
    $this->travel(61)->seconds();

    $result = app(AutomaticGenerationRecoveryService::class)->recover();

    expect($result['content_repair_expired'])->toBe(1)
        ->and($run->fresh()->error_code)->toBe('content_repair_time_limit_exceeded')
        ->and(data_get($chapter->novel->fresh()->settings, 'auto_stop.code'))->toBe('content_repair_limit');
});

test('terminal downstream failure closes repair before a later manual retry', function () {
    $chapter = selfHealingChapter();
    $service = app(ContentSelfHealingService::class);
    $repair = $service->start($chapter, ContentRepairType::ChapterStructure, ['review_id' => 17]);

    (new ExtractStoryEventsJob($chapter->getKey()))->failed(
        new AiProviderException('event_validation_failed', '已满足或冲突的条件必须提供当前正文逐字证据。', false),
    );

    expect($repair->fresh()->status)->toBe(RunStatus::Failed)
        ->and($repair->fresh()->error_code)->toBe('content_repair_stage_failed')
        ->and(data_get($repair->fresh()->error_metadata, 'source_error_code'))->toBe('event_validation_failed')
        ->and($service->activeForChapter($chapter))->toBeNull()
        ->and(data_get($chapter->novel->fresh()->settings, 'auto_stop.code'))->toBe('domain_validation_failed');

    $this->travel(31)->minutes();

    expect(app(AutomaticGenerationRecoveryService::class)->recover()['content_repair_expired'])->toBe(0)
        ->and($repair->fresh()->error_code)->toBe('content_repair_stage_failed');
});

test('review attention closes active repair before the blocked chapter stops advancing', function () {
    $chapter = selfHealingChapter();
    $service = app(ContentSelfHealingService::class);
    $repair = $service->start($chapter, ContentRepairType::ChapterStructure, ['review_id' => 17]);

    app(AutoStopService::class)->stopForReview($chapter, ReviewDecision::NeedsAttention);

    expect($repair->fresh()->error_code)->toBe('content_repair_review_not_passed')
        ->and($repair->fresh()->status)->toBe(RunStatus::Failed)
        ->and($service->activeForChapter($chapter))->toBeNull()
        ->and(data_get($chapter->novel->fresh()->settings, 'auto_stop.code'))->toBe('needs_attention');
});
