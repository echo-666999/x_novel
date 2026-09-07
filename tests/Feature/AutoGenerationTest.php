<?php

use App\Actions\Generation\CheckNextAction;
use App\Actions\Generation\SetAutoGenerationAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\VolumeStatus;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('auto generation switch preserves unrelated novel settings', function () {
    $novel = Novel::factory()->create(['settings' => ['temperature' => 0.4]]);
    $action = app(SetAutoGenerationAction::class);

    $action->handle($novel, true);

    expect($novel->refresh()->settings)->toBe(['temperature' => 0.4, 'auto_generate' => true]);

    $action->handle($novel, false);

    expect($novel->refresh()->settings)->toBe(['temperature' => 0.4, 'auto_generate' => false]);
});

test('post commit check creates and queues only the immediate next chapter when auto generation is on', function () {
    Queue::fake();
    [$novel, $canonical] = autoGenerationFixture(true);
    $action = app(CheckNextAction::class);

    $first = $action->handle($novel, $canonical->getKey());
    $second = $action->handle($novel, $canonical->getKey());

    expect($first?->sequence)->toBe(5)
        ->and($second?->is($first))->toBeTrue()
        ->and($novel->chapters()->where('sequence', 5)->count())->toBe(1)
        ->and($novel->chapters()->where('sequence', '>', 5)->count())->toBe(0);

    Queue::assertPushed(PlanChapterJob::class, 1);
    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $first->getKey());
});

test('post commit check does nothing when auto generation is off or callback chapter is stale', function () {
    Queue::fake();
    [$offNovel, $offChapter] = autoGenerationFixture(false);
    [$onNovel, $latestChapter] = autoGenerationFixture(true);
    $staleChapter = Chapter::factory()->for($onNovel)->create([
        'volume_id' => $latestChapter->volume_id,
        'sequence' => 3,
        'status' => ChapterStatus::Canonical,
    ]);
    $action = app(CheckNextAction::class);

    expect($action->handle($offNovel, $offChapter->getKey()))->toBeNull()
        ->and($action->handle($onNovel, $staleChapter->getKey()))->toBeNull()
        ->and($offNovel->chapters()->where('sequence', 5)->doesntExist())->toBeTrue()
        ->and($onNovel->chapters()->where('sequence', 5)->doesntExist())->toBeTrue();

    Queue::assertNothingPushed();
});

/** @return array{Novel, Chapter} */
function autoGenerationFixture(bool $enabled): array
{
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => 4,
        'settings' => ['auto_generate' => $enabled],
    ]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    $chapter = Chapter::factory()->for($novel)->for($volume)->create([
        'sequence' => 4,
        'status' => ChapterStatus::Canonical,
    ]);

    return [$novel->refresh(), $chapter];
}
