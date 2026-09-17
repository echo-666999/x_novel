<?php

use App\Actions\Chapters\RecoverChapterForBibleChangeAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\BibleStatus;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\WorldEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Queue::fake();
});

/** @return array<string, mixed> */
function bibleChapterRecoveryFixture(): array
{
    $novel = Novel::factory()->create(['title' => '脱敏恢复夹具', 'current_chapter_sequence' => 11]);
    $bible = NovelBible::factory()->for($novel)->create([
        'version' => 5,
        'tone' => '热血',
        'pov' => '第三人称全知',
        'tense' => '过去时',
        'status' => BibleStatus::Current,
    ]);
    $state = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 12,
        'status' => ChapterStatus::Blocked,
    ]);
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'version' => 1,
        'status' => PlanStatus::Ready,
    ]);
    $scene = Scene::factory()->for($chapter)->create([
        'sequence' => 1,
        'status' => SceneStatus::Draft,
    ]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scene_id' => $scene->getKey(),
        'scope_type' => 'scene',
        'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
        'state_version' => $state->version,
        'bible_version' => 5,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::SceneDraft]);
    $scene->update(['current_artifact_id' => $artifact->getKey()]);
    $failedRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Failed,
        'attempt' => 2,
        'state_version' => $state->version,
        'bible_version' => 5,
        'error_code' => 'review_validation_failed',
    ]);
    $usage = UsageRecord::factory()->create([
        'generation_run_id' => $failedRun->getKey(),
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
    ]);
    $actor = User::factory()->create();
    WorldEntity::factory()->for($novel)->create(['type' => 'location']);

    return compact('novel', 'bible', 'state', 'chapter', 'plan', 'scene', 'run', 'artifact', 'failedRun', 'usage', 'actor');
}

test('recovery preview freezes the exact bible diff and earliest affected chapter stage without writing', function () {
    $fixture = bibleChapterRecoveryFixture();

    $plan = app(RecoverChapterForBibleChangeAction::class)->preview(
        $fixture['novel'],
        12,
        5,
        0,
        '第一人称',
    );

    expect($plan['plan_hash'])->toHaveLength(64)
        ->and($plan['recovery_start'])->toBe('chapter_planning')
        ->and(array_keys($plan['bible_content_diff']))->toBe(['pov'])
        ->and(data_get($plan, 'bible_content_diff.pov.before'))->toBe('第三人称全知')
        ->and(data_get($plan, 'bible_content_diff.pov.after'))->toBe('第一人称')
        ->and(data_get($plan, 'state_baseline_check.matches'))->toBeTrue()
        ->and($plan['preserved_failed_run_ids'])->toBe([$fixture['failedRun']->getKey()])
        ->and(data_get($plan, 'world_history_candidates.will_write'))->toBeFalse()
        ->and(data_get($plan, 'world_history_candidates.canonical_counts_by_type.location'))->toBe(1)
        ->and(data_get($plan, 'arc_history_candidates.will_write'))->toBeFalse()
        ->and($fixture['novel']->bibles()->count())->toBe(1)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Blocked)
        ->and($fixture['scene']->fresh()->current_artifact_id)->toBe($fixture['artifact']->getKey());
});

test('explicit recovery creates one immutable bible version resets lineage and preserves failed audit data', function () {
    $fixture = bibleChapterRecoveryFixture();
    $service = app(RecoverChapterForBibleChangeAction::class);
    $plan = $service->preview($fixture['novel'], 12, 5, 0, '第一人称');

    $result = $service->execute(
        $fixture['novel'],
        12,
        5,
        0,
        '第一人称',
        $plan['plan_hash'],
        $fixture['actor']->getKey(),
    );

    $newBible = $fixture['novel']->fresh()->currentBible()->firstOrFail();
    expect($result['status'])->toBe('applied')
        ->and($result['dispatched'])->toBeTrue()
        ->and($newBible->version)->toBe(6)
        ->and($newBible->pov)->toBe('第一人称')
        ->and($newBible->tone)->toBe($fixture['bible']->tone)
        ->and($newBible->tense)->toBe($fixture['bible']->tense)
        ->and($newBible->style_profile)->toBe($fixture['bible']->style_profile)
        ->and($fixture['bible']->fresh()->status)->toBe(BibleStatus::Superseded)
        ->and($fixture['plan']->fresh()->status)->toBe(PlanStatus::Superseded)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Planned)
        ->and($fixture['scene']->fresh()->status)->toBe(SceneStatus::Planned)
        ->and($fixture['scene']->fresh()->current_artifact_id)->toBeNull()
        ->and($fixture['failedRun']->fresh()->status)->toBe(RunStatus::Failed)
        ->and($fixture['failedRun']->fresh()->error_code)->toBe('review_validation_failed')
        ->and(UsageRecord::query()->whereKey($fixture['usage']->getKey())->exists())->toBeTrue()
        ->and(GenerationArtifact::query()->whereKey($fixture['artifact']->getKey())->exists())->toBeTrue();

    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $fixture['chapter']->getKey() && $job->regenerate);

    Queue::fake();
    $duplicate = $service->execute(
        $fixture['novel'],
        12,
        5,
        0,
        '第一人称',
        $plan['plan_hash'],
        $fixture['actor']->getKey(),
    );

    expect($duplicate['status'])->toBe('already_applied')
        ->and($fixture['novel']->bibles()->count())->toBe(2);
    Queue::assertNothingPushed();
});

test('explicit recovery rejects stale or unreviewed input before mutation', function () {
    $fixture = bibleChapterRecoveryFixture();
    $service = app(RecoverChapterForBibleChangeAction::class);
    $plan = $service->preview($fixture['novel'], 12, 5, 0, '第一人称');

    expect(fn () => $service->execute(
        $fixture['novel'], 12, 5, 0, '第一人称', str_repeat('0', 64), $fixture['actor']->getKey(),
    ))->toThrow(ValidationException::class, '恢复计划已变化');

    expect(fn () => $service->preview($fixture['novel'], 12, 4, 0, '第一人称'))
        ->toThrow(ValidationException::class, 'Current Bible Version');

    expect($fixture['novel']->bibles()->count())->toBe(1)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Blocked)
        ->and($fixture['scene']->fresh()->current_artifact_id)->toBe($fixture['artifact']->getKey());
});

test('recovery command is dry run by default and requires reviewed execution inputs', function () {
    $fixture = bibleChapterRecoveryFixture();

    $this->artisan('novel:recover-bible-chapter', [
        'novel' => $fixture['novel']->getKey(),
        'chapter' => 12,
        '--expected-bible' => 5,
        '--expected-state' => 0,
    ])->expectsOutputToContain('"recovery_start": "chapter_planning"')
        ->expectsOutputToContain('dry-run 未写入数据')
        ->assertSuccessful();

    $this->artisan('novel:recover-bible-chapter', [
        'novel' => $fixture['novel']->getKey(),
        'chapter' => 12,
        '--expected-bible' => 5,
        '--expected-state' => 0,
        '--execute' => true,
    ])->expectsOutput('--execute 必须同时提供 dry-run 的 --plan-hash 和有效 --actor。')
        ->assertFailed();

    expect($fixture['novel']->bibles()->count())->toBe(1);
});
