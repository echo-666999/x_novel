<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ForeshadowingStatus;
use App\Jobs\RefreshNovelProjectionJob;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Services\ProjectionRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('projection refresh job rebuilds only its current canonical state version', function () {
    $novel = Novel::factory()->create();
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'status' => ForeshadowingStatus::Planted,
        'reinforce_count' => 0,
    ]);
    $version = app(InitializeNovelStateAction::class)->handle($novel);
    $foreshadowing->update([
        'status' => ForeshadowingStatus::PaidOff,
        'reinforce_count' => 9,
        'setup_chapter_id' => 99,
        'payoff_chapter_id' => 100,
    ]);

    $job = new RefreshNovelProjectionJob($novel->getKey(), $version->getKey());
    $job->handle(app(ProjectionRebuilder::class));

    expect($foreshadowing->fresh()->status)->toBe(ForeshadowingStatus::Planted)
        ->and($foreshadowing->fresh()->reinforce_count)->toBe(0)
        ->and($foreshadowing->fresh()->setup_chapter_id)->toBeNull()
        ->and($foreshadowing->fresh()->payoff_chapter_id)->toBeNull()
        ->and($job->uniqueId())->toBe("novel:{$novel->getKey()}:state:{$version->getKey()}");
});

test('a stale projection refresh job cannot overwrite a newer projection', function () {
    $novel = Novel::factory()->create();
    $foreshadowing = Foreshadowing::factory()->for($novel)->create(['status' => ForeshadowingStatus::Idea]);
    $version = app(InitializeNovelStateAction::class)->handle($novel);
    $newer = StoryStateVersion::factory()->for($novel)->create(['version' => $version->version + 1]);
    $novel->update(['canonical_state_version_id' => $newer->getKey()]);
    $foreshadowing->update(['status' => ForeshadowingStatus::PaidOff]);

    (new RefreshNovelProjectionJob($novel->getKey(), $version->getKey()))
        ->handle(app(ProjectionRebuilder::class));

    expect($foreshadowing->fresh()->status)->toBe(ForeshadowingStatus::PaidOff);
});

test('projection refresh failure leaves canonical pointers and versions intact for retry', function () {
    $novel = Novel::factory()->create();
    $version = app(InitializeNovelStateAction::class)->handle($novel);
    $rebuilder = Mockery::mock(ProjectionRebuilder::class);
    $rebuilder->shouldReceive('rebuild')->once()->andThrow(new RuntimeException('projection failed'));

    expect(fn () => (new RefreshNovelProjectionJob($novel->getKey(), $version->getKey()))->handle($rebuilder))
        ->toThrow(RuntimeException::class, 'projection failed');

    expect($novel->fresh()->canonical_state_version_id)->toBe($version->getKey())
        ->and($novel->storyStateVersions()->whereKey($version->getKey())->exists())->toBeTrue();
});
