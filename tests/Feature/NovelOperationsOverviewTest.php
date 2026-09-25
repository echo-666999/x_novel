<?php

use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Models\Chapter;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\UsageRecord;
use App\Models\Volume;
use App\Services\DashboardOperationsOverview;
use App\Services\NovelOperationsOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('novel operations overview derives progress and quality from persisted novel data', function () {
    config()->set('ai.cost.currency', 'CNY');
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => 2,
        'target_words' => 100_000,
    ]);
    $otherNovel = Novel::factory()->create();
    Volume::factory()->for($novel)->create(['sequence' => 2, 'title' => '潮汐卷', 'status' => VolumeStatus::Active]);
    $first = Chapter::factory()->for($novel)->create(['sequence' => 1, 'status' => ChapterStatus::Canonical, 'word_count' => 1_500]);
    $second = Chapter::factory()->for($novel)->create(['sequence' => 2, 'status' => ChapterStatus::Canonical, 'word_count' => 2_000]);
    Chapter::factory()->for($novel)->create(['sequence' => 3, 'status' => ChapterStatus::Review, 'word_count' => 9_999]);
    Chapter::factory()->for($otherNovel)->create(['status' => ChapterStatus::Canonical, 'word_count' => 8_888]);

    $firstReviewRun = GenerationRun::factory()->for($novel)->create(['chapter_id' => $first, 'scope_type' => 'chapter', 'scope_id' => $first, 'stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded]);
    Review::factory()->for($firstReviewRun)->create(['decision' => ReviewDecision::Pass]);
    $secondReviewRun = GenerationRun::factory()->for($novel)->create(['chapter_id' => $second, 'scope_type' => 'chapter', 'scope_id' => $second, 'stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded]);
    Review::factory()->for($secondReviewRun)->create(['decision' => ReviewDecision::Rewrite]);
    $secondPassRun = GenerationRun::factory()->for($novel)->create(['chapter_id' => $second, 'scope_type' => 'chapter', 'scope_id' => $second, 'stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded]);
    Review::factory()->for($secondPassRun)->create(['decision' => ReviewDecision::Pass]);
    $rewriteRun = GenerationRun::factory()->for($novel)->create(['chapter_id' => $second, 'scope_type' => 'chapter', 'scope_id' => $second, 'stage' => GenerationStage::Rewrite, 'status' => RunStatus::Succeeded]);
    GenerationArtifact::factory()->for($rewriteRun)->create(['type' => ArtifactType::RewriteDraft]);
    UsageRecord::factory()->create(['novel_id' => $novel, 'estimated_cost' => 0.25, 'created_at' => now()]);
    UsageRecord::factory()->create(['novel_id' => $otherNovel, 'estimated_cost' => 5, 'created_at' => now()]);
    UsageRecord::factory()->create(['novel_id' => $novel, 'estimated_cost' => 7, 'created_at' => now()->subDay()]);
    Foreshadowing::factory()->for($novel)->create(['status' => ForeshadowingStatus::Planted, 'due_from_chapter' => 1, 'due_to_chapter' => 2]);

    $service = app(NovelOperationsOverview::class);

    expect($service->progress($novel))
        ->toMatchArray([
            'target_words' => 100_000,
            'current_words' => 3_500,
            'current_volume' => '第 2 卷 · 潮汐卷',
            'current_chapter' => '第 2 章',
        ])
        ->and($service->quality($novel))->toMatchArray([
            'today_cost' => 'CNY 0.2500',
            'review_pass_rate' => '50.0% (1/2)',
            'rewrite_rate' => '50.0% (1/2)',
            'due_foreshadowings' => 1,
        ]);
});

test('novel pipeline recommends retry recovery review and resume from the latest persisted state', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 4, 'status' => ChapterStatus::Generating]);
    $run = GenerationRun::factory()->for($novel)->create([
        'chapter_id' => $chapter,
        'scope_type' => 'chapter',
        'scope_id' => $chapter,
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
        'error_message' => 'timeout',
        'error_retryable' => true,
    ]);

    $service = app(NovelOperationsOverview::class);
    expect($service->pipeline($novel))->toMatchArray([
        'run_id' => $run->getKey(),
        'failure_category' => 'external_temporary',
        'next_action_key' => 'retry',
        'next_action' => '重试失败阶段',
    ]);

    $run->update(['error_code' => 'worker_lost', 'error_retryable' => false]);
    expect($service->pipeline($novel)['next_action_key'])->toBe('recover');

    $novel->update(['status' => NovelStatus::Paused]);
    expect($service->pipeline($novel)['next_action_key'])->toBe('resume');
});

test('novel quality aggregation keeps a stable query count as chapter count grows', function () {
    $novel = Novel::factory()->create();
    Chapter::factory()->for($novel)->create(['sequence' => 1, 'status' => ChapterStatus::Canonical]);
    $service = app(NovelOperationsOverview::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $service->quality($novel);
    $smallCount = count(DB::getQueryLog());

    Chapter::factory()->count(40)->for($novel)->sequence(fn ($sequence) => ['sequence' => $sequence->index + 2, 'status' => ChapterStatus::Canonical])->create();
    DB::flushQueryLog();
    $service->quality($novel);
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($largeCount)->toBe($smallCount);
});

test('latest review aggregates honor the configured database table prefix', function () {
    $connection = DB::connection();
    $originalPrefix = $connection->getTablePrefix();

    try {
        $connection->setTablePrefix('x_');
        $dashboardMethod = new ReflectionMethod(DashboardOperationsOverview::class, 'latestReviewIds');
        $novelMethod = new ReflectionMethod(NovelOperationsOverview::class, 'latestReviewIdExpression');

        $dashboardSql = $dashboardMethod->invoke(app(DashboardOperationsOverview::class))->toSql();
        $novelExpression = $novelMethod->invoke(app(NovelOperationsOverview::class));
    } finally {
        $connection->setTablePrefix($originalPrefix);
    }

    expect($dashboardSql)
        ->toContain('MAX("x_reviews"."id")')
        ->toContain('"x_reviews"')
        ->toContain('"x_generation_runs"')
        ->and($novelExpression)->toBe('MAX("x_reviews"."id")');
});
