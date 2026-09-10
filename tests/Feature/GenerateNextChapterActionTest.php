<?php

use App\Actions\Generation\GenerateNextChapterAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Exceptions\BudgetExceededException;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Exceptions\GenerationPreflightException;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\UsageRecord;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function generationReadyNovel(array $attributes = []): Novel
{
    $novel = Novel::factory()->create(array_merge([
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => null,
    ], $attributes));

    NovelBible::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    return $novel->refresh();
}

test('it creates the next planned chapter in the current volume', function () {
    $novel = generationReadyNovel(['current_chapter_sequence' => 4]);

    $chapter = app(GenerateNextChapterAction::class)->handle($novel);

    expect($chapter->sequence)->toBe(5)
        ->and($chapter->status)->toBe(ChapterStatus::Planned)
        ->and($chapter->volume->status)->toBe(VolumeStatus::Active)
        ->and($chapter->title)->toBe('第 5 章');
});

test('repeated calls resume the same next chapter without creating a duplicate', function () {
    $novel = generationReadyNovel(['current_chapter_sequence' => 2]);
    $action = app(GenerateNextChapterAction::class);

    $first = $action->handle($novel);
    $second = $action->handle($novel);

    expect($second->is($first))->toBeTrue()
        ->and($novel->chapters()->where('sequence', 3)->count())->toBe(1);
});

test('it resumes an existing non canonical next chapter', function () {
    $novel = generationReadyNovel(['current_chapter_sequence' => 7]);
    $existing = Chapter::factory()->for($novel)->create([
        'volume_id' => $novel->volumes()->firstOrFail()->getKey(),
        'sequence' => 8,
        'status' => ChapterStatus::Review,
    ]);

    $chapter = app(GenerateNextChapterAction::class)->handle($novel);

    expect($chapter->is($existing))->toBeTrue()
        ->and($novel->chapters()->where('sequence', 8)->count())->toBe(1);
});

test('preflight rejects an uninitialized story state', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    NovelBible::factory()->for($novel)->create();
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    app(GenerateNextChapterAction::class)->handle($novel);
})->throws(GenerationPreflightException::class, 'Story State 尚未初始化。');

test('preflight rejects a missing current bible before creating a chapter', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    try {
        app(GenerateNextChapterAction::class)->handle($novel);
        test()->fail('Expected missing Current Bible to fail preflight.');
    } catch (GenerationPreflightException $exception) {
        expect($exception->reason)->toBe('current_bible_incomplete')
            ->and($exception->getMessage())->toContain('缺少 Current Bible')
            ->and($exception->getMessage())->toContain('请先在“小说圣经”中创建完整的 Current Bible Version')
            ->and($novel->chapters()->count())->toBe(0);
    }
});

test('preflight rejects an incomplete bible profile instead of using old editorial', function () {
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'settings' => ['editorial' => ['primary_style' => 'accessible_brisk']],
    ]);
    NovelBible::factory()->for($novel)->create(['style_profile' => null]);
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    try {
        app(GenerateNextChapterAction::class)->handle($novel);
        test()->fail('Expected incomplete Bible Style Profile to fail preflight.');
    } catch (GenerationPreflightException $exception) {
        expect($exception->reason)->toBe('current_bible_incomplete')
            ->and($exception->getMessage())->toContain('必须提供完整的文风设置')
            ->and($novel->chapters()->count())->toBe(0);
    }
});

test('preflight rejects a paused novel', function () {
    $novel = generationReadyNovel(['status' => NovelStatus::Paused]);

    app(GenerateNextChapterAction::class)->handle($novel);
})->throws(GenerationPreflightException::class, '小说已暂停');

test('preflight rejects a missing current volume', function () {
    $novel = generationReadyNovel();
    $novel->volumes()->update(['status' => VolumeStatus::Planned]);

    app(GenerateNextChapterAction::class)->handle($novel);
})->throws(GenerationPreflightException::class, '没有进行中的卷');

test('preflight rejects a blocked review', function () {
    $novel = generationReadyNovel();
    Chapter::factory()->for($novel)->create([
        'sequence' => 1,
        'status' => ChapterStatus::Blocked,
    ]);

    app(GenerateNextChapterAction::class)->handle($novel);
})->throws(GenerationPreflightException::class, '存在被审校阻塞的章节');

test('preflight rejects another active chapter workflow', function () {
    $novel = generationReadyNovel(['current_chapter_sequence' => 3]);
    Chapter::factory()->for($novel)->create([
        'sequence' => 5,
        'status' => ChapterStatus::Generating,
    ]);

    app(GenerateNextChapterAction::class)->handle($novel);
})->throws(GenerationPreflightException::class, '已有另一个活跃章节工作流');

test('preflight rejects another active generation run', function () {
    $novel = generationReadyNovel();
    GenerationRun::factory()->for($novel)->create([
        'chapter_id' => null,
        'scope_type' => 'novel',
        'scope_id' => $novel->getKey(),
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Queued,
    ]);

    app(GenerateNextChapterAction::class)->handle($novel);
})->throws(GenerationPreflightException::class, '已有另一个活跃章节工作流');

test('preflight rejects a reached hard budget before creating a chapter', function () {
    config()->set('ai.budget.daily_hard_limit', 1);
    config()->set('ai.budget.novel_total_limit', null);
    $novel = generationReadyNovel();
    UsageRecord::factory()->create(['estimated_cost' => 1]);

    try {
        app(GenerateNextChapterAction::class)->handle($novel);
        $this->fail('Expected BudgetExceededException was not thrown.');
    } catch (BudgetExceededException $exception) {
        expect($exception->errorCode)->toBe('budget_daily_hard_limit')
            ->and($novel->chapters()->count())->toBe(0);
    }
});
