<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\User;
use App\Services\DraftRewriteDiff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('text diff identifies unchanged removed and added sentences', function () {
    $lines = app(DraftRewriteDiff::class)->lines(
        '林舟走进城门。风雨仍未停。',
        '林舟走进城门。晨光穿过云层。',
    );

    expect($lines)->toBe([
        ['type' => 'unchanged', 'text' => '林舟走进城门。'],
        ['type' => 'added', 'text' => '晨光穿过云层。'],
        ['type' => 'removed', 'text' => '风雨仍未停。'],
    ]);
});

test('diff marks findings resolved or unresolved from the subsequent review', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $sourceRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded]);
    $sourceReviewArtifact = GenerationArtifact::factory()->for($sourceRun)->create(['type' => ArtifactType::ReviewResult]);
    $sourceReview = Review::factory()->create([
        'generation_run_id' => $sourceRun->getKey(), 'artifact_id' => $sourceReviewArtifact->getKey(),
        'decision' => ReviewDecision::Rewrite,
        'findings' => [
            ['dimension' => 'pacing', 'message' => '结尾拖沓', 'evidence' => '末段'],
            ['code' => 'LOCKED_FACT_CONFLICT', 'message' => '事实冲突'],
        ],
    ]);
    $draftRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['stage' => GenerationStage::ChapterAssembly, 'status' => RunStatus::Succeeded]);
    $before = GenerationArtifact::factory()->for($draftRun)->create(['type' => ArtifactType::ChapterDraft, 'content' => '旧正文。']);
    $rewriteRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['stage' => GenerationStage::Rewrite, 'status' => RunStatus::Succeeded]);
    $rewrite = GenerationArtifact::factory()->for($rewriteRun)->create([
        'type' => ArtifactType::RewriteDraft, 'content' => '新正文。',
        'data' => ['source_artifact_id' => $before->getKey(), 'source_review_id' => $sourceReview->getKey()],
    ]);
    $afterRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded]);
    $afterArtifact = GenerationArtifact::factory()->for($afterRun)->create(['type' => ArtifactType::ReviewResult]);
    $afterReview = Review::factory()->create([
        'generation_run_id' => $afterRun->getKey(), 'artifact_id' => $afterArtifact->getKey(),
        'decision' => ReviewDecision::NeedsAttention,
        'findings' => [['code' => 'LOCKED_FACT_CONFLICT', 'message' => '事实冲突']],
    ]);

    $diff = app(DraftRewriteDiff::class)->build($rewrite, $afterReview);

    expect($diff['findings'][0]['resolution'])->toBe('resolved')
        ->and($diff['findings'][1]['resolution'])->toBe('unresolved')
        ->and($diff['before']->is($before))->toBeTrue()
        ->and($diff['after']->is($rewrite))->toBeTrue();
});

test('finding resolution stays pending until rewrite is reviewed', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $reviewRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['stage' => GenerationStage::Review]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create(['type' => ArtifactType::ReviewResult]);
    $review = Review::factory()->create([
        'generation_run_id' => $reviewRun->getKey(), 'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::Rewrite, 'findings' => [['dimension' => 'style', 'message' => '措辞重复']],
    ]);
    $draftRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['stage' => GenerationStage::ChapterAssembly]);
    $before = GenerationArtifact::factory()->for($draftRun)->create(['type' => ArtifactType::ChapterDraft]);
    $rewriteRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['stage' => GenerationStage::Rewrite]);
    $rewrite = GenerationArtifact::factory()->for($rewriteRun)->create([
        'type' => ArtifactType::RewriteDraft,
        'data' => ['source_artifact_id' => $before->getKey(), 'source_review_id' => $review->getKey()],
    ]);

    expect(app(DraftRewriteDiff::class)->build($rewrite)['findings'][0]['resolution'])->toBe('pending');
});

test('chapter review renders before after diff and finding status', function () {
    $this->actingAs(User::factory()->create());
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $reviewRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['stage' => GenerationStage::Review]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create(['type' => ArtifactType::ReviewResult]);
    $review = Review::factory()->create([
        'generation_run_id' => $reviewRun->getKey(), 'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::Rewrite,
        'findings' => [['dimension' => 'pacing', 'message' => '节奏拖沓', 'evidence' => '结尾重复']],
    ]);
    $draftRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['stage' => GenerationStage::ChapterAssembly]);
    $before = GenerationArtifact::factory()->for($draftRun)->create(['type' => ArtifactType::ChapterDraft, 'content' => '旧结尾。']);
    $rewriteRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['stage' => GenerationStage::Rewrite]);
    GenerationArtifact::factory()->for($rewriteRun)->create([
        'type' => ArtifactType::RewriteDraft, 'content' => '新结尾。',
        'data' => ['source_artifact_id' => $before->getKey(), 'source_review_id' => $review->getKey(), 'scope' => 'chapter'],
    ]);

    Livewire::test(ViewNovelChapter::class, ['record' => $novel->getRouteKey(), 'chapter' => $chapter->getKey()])
        ->assertOk()
        ->assertSee('重写与复审历程')
        ->assertSee('Before')
        ->assertSee('After')
        ->assertSee('旧结尾。')
        ->assertSee('新结尾。')
        ->assertSee('待复审');
});
