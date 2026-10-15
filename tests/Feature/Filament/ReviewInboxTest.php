<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Filament\Pages\Review as ReviewPage;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function inboxReview(Novel $novel, Chapter $chapter, ReviewDecision $decision, string $finding, int $score = 70): Review
{
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::ReviewResult]);

    return Review::query()->create([
        'generation_run_id' => $run->getKey(),
        'artifact_id' => $artifact->getKey(),
        'decision' => $decision,
        'score' => $score,
        'continuity_score' => $score,
        'plan_score' => $score,
        'character_score' => $score,
        'progress_score' => $score,
        'repetition_score' => $score,
        'pacing_score' => $score,
        'style_score' => $score,
        'findings' => [['severity' => 'hard', 'code' => 'LOCKED_FACT_CONFLICT', 'message' => $finding]],
    ]);
}

test('review inbox defaults to the latest needs attention and block reviews', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $blockedChapter = Chapter::factory()->for($novel)->create(['sequence' => 8, 'title' => '城门旧约']);
    $passedChapter = Chapter::factory()->for($novel)->create(['sequence' => 9, 'title' => '风暴之后']);
    $old = inboxReview($novel, $blockedChapter, ReviewDecision::NeedsAttention, '旧问题');
    $blocked = inboxReview($novel, $blockedChapter, ReviewDecision::Block, '与锁定事实冲突');
    $passed = inboxReview($novel, $passedChapter, ReviewDecision::Pass, '无阻塞问题', 92);

    Livewire::test(ReviewPage::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$blocked])
        ->assertCanNotSeeTableRecords([$old, $passed])
        ->assertSee('雾海长明')
        ->assertSee('第 8 章 · 城门旧约')
        ->assertSee('阻塞')
        ->assertSee('与锁定事实冲突')
        ->assertSee('70.00 / 100');
});

test('review inbox can switch to all decisions and links to chapter review', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 3]);
    $review = inboxReview($novel, $chapter, ReviewDecision::Pass, '已通过', 90);

    Livewire::test(ReviewPage::class)
        ->filterTable('inbox', 'all')
        ->assertCanSeeTableRecords([$review])
        ->assertSee('无待处理问题')
        ->assertSee(NovelResource::getUrl('chapter', [
            'record' => $novel->getKey(),
            'chapter' => $chapter->getKey(),
            'tab' => 'review',
        ]), false)
        ->assertTableActionExists('inspect', fn ($action): bool => $action->isModalSlideOver());
});

test('review finding inspector explains how to repair each finding', function () {
    $novel = Novel::factory()->create(['title' => '照夜长河']);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 1, 'title' => '雨夜初醒']);
    $review = inboxReview($novel, $chapter, ReviewDecision::NeedsAttention, '正文与当前 Milestone 验收条件发生冲突。');
    $review->update([
        'findings' => [[
            'severity' => 'error',
            'dimension' => 'progress',
            'scope' => 'chapter',
            'code' => 'MILESTONE_CONTRADICTED',
            'message' => '正文与当前 Milestone 验收条件发生冲突。',
            'evidence' => '刘据的腰里忽然一阵抽紧。',
        ]],
    ]);

    Livewire::test(ReviewPage::class)
        ->assertTableActionExists('inspect', fn ($action): bool => $action->isModalSlideOver());

    $method = new ReflectionMethod(ReviewPage::class, 'findingRecommendationRows');
    $rows = $method->invoke(app(ReviewPage::class), $review->fresh());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['problem_layer'])->toBe('Outline / Milestone')
        ->and($rows[0]['recommended_action'])->toBe('修订未来 Outline 或从当前 Outline 重新规划本章')
        ->and($rows[0]['target_fields'])->toContain('Milestone 验收条件（里程碑完成标准）')
        ->and($rows[0]['affected_stages'])->toContain('Chapter Plan（章节计划）', 'Review（叙事审校）')
        ->and($rows[0]['recovery_entry'])->toContain('从 Outline 重建章节');
});

test('review inbox has an actionable empty state', function () {
    Livewire::test(ReviewPage::class)
        ->assertOk()
        ->assertSee('审校收件箱为空')
        ->assertSee('没有需要人工处理或已阻塞的章节');
});
