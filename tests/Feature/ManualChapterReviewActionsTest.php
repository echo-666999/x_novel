<?php

use App\Actions\Chapters\AcceptOverlengthChapterAction;
use App\Actions\Chapters\ManuallyReviseChapterAction;
use App\Actions\Chapters\OverrideChapterReviewAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Jobs\ExtractStoryEventsJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function manualReviewFixture(array $findings = []): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Review]);
    ChapterPlan::factory()->for($chapter)->create(['target_words' => 3000]);
    $draftRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::Rewrite,
        'status' => RunStatus::Succeeded,
        'state_version' => 0,
    ]);
    $draft = GenerationArtifact::factory()->for($draftRun)->create([
        'type' => ArtifactType::RewriteDraft,
        'version' => 2,
        'content' => '需要人工处理的当前章节正文。',
        'checksum' => hash('sha256', '需要人工处理的当前章节正文。'),
    ]);
    $reviewRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
        'state_version' => 0,
        'attempt' => 3,
    ]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create([
        'type' => ArtifactType::ReviewResult,
        'version' => 3,
        'data' => ['source_artifact_id' => $draft->getKey()],
    ]);
    $review = Review::factory()->for($reviewRun)->create([
        'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::NeedsAttention,
        'findings' => $findings,
    ]);

    return compact('novel', 'chapter', 'draft', 'review');
}

test('manual chapter revision creates an immutable draft and resumes extraction', function () {
    Queue::fake();
    $fixture = manualReviewFixture();

    $artifact = app(ManuallyReviseChapterAction::class)->execute(
        $fixture['chapter'],
        '人工压缩后的章节正文。',
        '删除重复段落并控制字数。',
        7,
    );

    expect($artifact->type)->toBe(ArtifactType::RewriteDraft)
        ->and($artifact->version)->toBe(3)
        ->and($artifact->content)->toBe('人工压缩后的章节正文。')
        ->and(data_get($artifact->data, 'manual_edit'))->toBeTrue()
        ->and(data_get($artifact->data, 'manual_reason'))->toBe('删除重复段落并控制字数。')
        ->and($artifact->generationRun->model_policy)->toBe('manual')
        ->and($artifact->generationRun->prompt_version)->toBe('manual-edit-v1')
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Rewrite);
    Queue::assertPushed(ExtractStoryEventsJob::class, fn (ExtractStoryEventsJob $job): bool => $job->chapterId === $fixture['chapter']->getKey()
        && $job->regenerate
        && $job->continueRewrite);
});

test('manual chapter revision rejects unchanged content', function () {
    Queue::fake();
    $fixture = manualReviewFixture();

    expect(fn () => app(ManuallyReviseChapterAction::class)->execute(
        $fixture['chapter'],
        $fixture['draft']->content,
        '没有实际修改。',
    ))->toThrow(ValidationException::class, '正文没有变化');

    Queue::assertNothingPushed();
});

test('manual review override creates an audited pass review without changing history', function () {
    $fixture = manualReviewFixture([[
        'dimension' => 'pacing',
        'severity' => 'warning',
        'message' => '节奏偏慢。',
    ]]);

    $review = app(OverrideChapterReviewAction::class)->execute(
        $fixture['chapter'],
        '当前篇幅可以接受，保留该版本。',
        7,
    );

    expect($review->decision)->toBe(ReviewDecision::Pass)
        ->and($review->artifact->version)->toBe(4)
        ->and(data_get($review->artifact->data, 'manual_override'))->toBeTrue()
        ->and(data_get($review->artifact->data, 'manual_override_reason'))->toBe('当前篇幅可以接受，保留该版本。')
        ->and(data_get($review->artifact->data, 'source_review_id'))->toBe($fixture['review']->getKey())
        ->and(data_get($review->artifact->data, 'overridden_findings.0.message'))->toBe('节奏偏慢。')
        ->and($review->generationRun->model_policy)->toBe('manual')
        ->and($review->generationRun->prompt_version)->toBe('manual-override-v1')
        ->and($fixture['review']->fresh()->decision)->toBe(ReviewDecision::NeedsAttention)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review);
});

test('manual review override cannot bypass a hard conflict', function () {
    $fixture = manualReviewFixture([[
        'code' => 'LOCKED_FACT_CONFLICT',
        'severity' => 'hard',
        'message' => '与锁定事实冲突。',
    ]]);

    expect(fn () => app(OverrideChapterReviewAction::class)->execute(
        $fixture['chapter'],
        '尝试绕过硬冲突。',
    ))->toThrow(ValidationException::class, '存在硬冲突');

    expect(Review::query()->count())->toBe(1);
});

test('ordinary manual override cannot clear a chapter length finding', function () {
    $fixture = manualReviewFixture([[
        'code' => 'CHAPTER_LENGTH_TOO_LONG',
        'severity' => 'warning',
        'message' => '章节超过严格字数上限。',
    ]]);

    expect(fn () => app(OverrideChapterReviewAction::class)->execute(
        $fixture['chapter'],
        '仍然想用普通 Override。',
    ))->toThrow(ValidationException::class, '字数问题不能通过普通 Override 清除');

    expect(Review::query()->count())->toBe(1);
});

test('accepting an overlength chapter records a distinct exception and preserves the finding', function () {
    $finding = [
        'code' => 'CHAPTER_LENGTH_TOO_LONG',
        'severity' => 'warning',
        'message' => '章节超过严格字数上限。',
    ];
    $fixture = manualReviewFixture([$finding]);
    $fixture['chapter']->latestPlan->update(['target_words' => 100]);
    $content = str_repeat('超', 120);
    DB::table('generation_artifacts')->where('id', $fixture['draft']->getKey())->update([
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);

    $review = app(AcceptOverlengthChapterAction::class)->execute(
        $fixture['chapter'],
        '剧情节点不可拆分，明确接受本章超限。',
        7,
    );
    $sameReview = app(AcceptOverlengthChapterAction::class)->execute(
        $fixture['chapter'],
        '剧情节点不可拆分，明确接受本章超限。',
        7,
    );

    expect($review->decision)->toBe(ReviewDecision::Pass)
        ->and($sameReview->is($review))->toBeTrue()
        ->and($review->findings)->toContainEqual($finding)
        ->and(data_get($review->artifact->data, 'manual_length_exception'))->toBeTrue()
        ->and(data_get($review->artifact->data, 'manual_length_exception_reason'))->toBe('剧情节点不可拆分，明确接受本章超限。')
        ->and(data_get($review->artifact->data, 'actual_words'))->toBe(120)
        ->and(data_get($review->artifact->data, 'maximum_words'))->toBe(115)
        ->and(data_get($review->artifact->data, 'findings'))->toContainEqual($finding)
        ->and($review->generationRun->prompt_version)->toBe('manual-length-exception-v1')
        ->and($fixture['review']->fresh()->decision)->toBe(ReviewDecision::NeedsAttention)
        ->and(Review::query()->count())->toBe(2);
});
