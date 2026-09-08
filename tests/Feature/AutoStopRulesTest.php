<?php

use App\Actions\Generation\CheckNextAction;
use App\Actions\Generation\PauseGenerationAction;
use App\Actions\Generation\SetAutoGenerationAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Exceptions\AiProviderException;
use App\AI\Exceptions\BudgetExceededException;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\Novel;
use App\Services\AutoStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('review stop rules record hard conflict needs attention and block reasons', function (ReviewDecision $decision, bool $hardConflict, string $code) {
    [$novel, $chapter] = autoStopChapter();

    app(AutoStopService::class)->stopForReview($chapter, $decision, $hardConflict);

    expect(data_get($novel->fresh()->settings, 'auto_generate'))->toBeFalse()
        ->and(data_get($novel->fresh()->settings, 'auto_stop.code'))->toBe($code)
        ->and(data_get($novel->fresh()->settings, 'auto_stop.recommended_action'))->not->toBeEmpty();
})->with([
    'hard conflict' => [ReviewDecision::Block, true, 'hard_conflict'],
    'needs attention' => [ReviewDecision::NeedsAttention, false, 'needs_attention'],
    'block' => [ReviewDecision::Block, false, 'review_blocked'],
]);

test('pipeline failures stop auto generation with a recoverable reason', function (AiProviderException $exception, string $code) {
    [$novel, $chapter] = autoStopChapter();

    app(AutoStopService::class)->stopForFailure($chapter->getKey(), $exception);

    expect(data_get($novel->fresh()->settings, 'auto_generate'))->toBeFalse()
        ->and(data_get($novel->fresh()->settings, 'auto_stop.code'))->toBe($code);
})->with([
    'provider retry exhausted' => [new AiProviderException('provider_timeout', 'timeout', true), 'provider_retry_exhausted'],
    'rewrite exhausted' => [new AiProviderException('rewrite_exhausted', 'exhausted', false), 'rewrite_exhausted'],
    'budget limit' => [new BudgetExceededException('chapter', 1, 1), 'budget_limit'],
    'state version conflict' => [new AiProviderException('state_version_conflict', 'stale', false), 'state_version_conflict'],
    'volume gate' => [new AiProviderException('volume_gate_failed', 'closed', false), 'volume_gate'],
    'ending audit block' => [new AiProviderException('ending_audit_block', 'debt', false), 'ending_audit_block'],
]);

test('a final provider retry failure from a queue job disables auto generation', function () {
    [$novel, $chapter] = autoStopChapter();

    (new PlanChapterJob($chapter->getKey()))->failed(new AiProviderException('provider_timeout', 'timeout', true));

    expect(data_get($novel->fresh()->settings, 'auto_stop.code'))->toBe('provider_retry_exhausted');
});

test('post commit volume gate failure disables auto generation and queues no next chapter', function () {
    Queue::fake();
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => 1,
        'settings' => ['auto_generate' => true],
    ]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 1, 'status' => ChapterStatus::Canonical]);

    expect(app(CheckNextAction::class)->handle($novel, $chapter->getKey()))->toBeNull()
        ->and(data_get($novel->fresh()->settings, 'auto_generate'))->toBeFalse()
        ->and(data_get($novel->fresh()->settings, 'auto_stop.code'))->toBe('volume_gate');
    Queue::assertNothingPushed();
});

test('pause records a stop reason without disabling the resumable auto setting', function () {
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'settings' => ['auto_generate' => true],
    ]);

    app(PauseGenerationAction::class)->handle($novel);

    expect(data_get($novel->fresh()->settings, 'auto_generate'))->toBeTrue()
        ->and(data_get($novel->fresh()->settings, 'auto_stop.code'))->toBe('user_pause');
});

test('enabling automatic generation clears the previous stop reason', function () {
    $novel = Novel::factory()->create([
        'settings' => [
            'auto_generate' => false,
            'auto_stop' => ['code' => 'budget_limit', 'reason' => '预算已用完。'],
        ],
    ]);

    app(SetAutoGenerationAction::class)->handle($novel, true);

    expect(data_get($novel->fresh()->settings, 'auto_generate'))->toBeTrue()
        ->and(data_get($novel->fresh()->settings, 'auto_stop'))->toBeNull();
});

/** @return array{Novel, Chapter} */
function autoStopChapter(): array
{
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'settings' => ['auto_generate' => true, 'temperature' => .4],
    ]);
    $chapter = Chapter::factory()->for($novel)->create();

    return [$novel, $chapter];
}
