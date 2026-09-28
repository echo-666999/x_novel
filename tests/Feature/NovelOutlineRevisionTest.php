<?php

use App\Actions\Novels\ApplyNovelOutlineRevisionAction;
use App\Data\NormalizedNovelOutline;
use App\Enums\RunStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array{Novel, Volume, StoryArc, array<string, mixed>} */
function outlineRevisionFixture(): array
{
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    $arc = StoryArc::factory()->forVolume($volume)->create(['status' => StoryArcStatus::Active]);
    $novel->refresh()->load('currentOutline');
    $data = NormalizedNovelOutline::fromModel($novel->currentOutline)->toArray();

    return [$novel, $volume, $arc, $data];
}

test('a revision creates a new current version and repoints runtime projections', function () {
    [$novel, $volume, $arc, $data] = outlineRevisionFixture();
    $current = $novel->currentOutline;
    $data['summary'] = '修订后的关系化全书摘要。';

    $revision = app(ApplyNovelOutlineRevisionAction::class)->handle(
        $novel,
        $data,
        $current->getKey(),
        $current->checksum,
    );

    expect($revision->version)->toBe(2)
        ->and($revision->summary)->toBe('修订后的关系化全书摘要。')
        ->and($revision->based_on_outline_id)->toBe($current->getKey())
        ->and($novel->fresh()->current_outline_id)->toBe($revision->getKey())
        ->and($volume->fresh()->sourceOutlineVolume->novel_outline_id)->toBe($revision->getKey())
        ->and($arc->fresh()->sourceOutlineArc->novel_outline_id)->toBe($revision->getKey());
});

test('a revision rejects stale current checksum', function () {
    [$novel, , , $data] = outlineRevisionFixture();
    $current = $novel->currentOutline;
    $data['summary'] = '新的摘要。';

    expect(fn () => app(ApplyNovelOutlineRevisionAction::class)->handle(
        $novel,
        $data,
        $current->getKey(),
        hash('sha256', 'stale'),
    ))->toThrow(ValidationException::class, 'Current Outline 已变化');
});

test('a revision rejects queued or running generation work', function (RunStatus $status) {
    [$novel, , , $data] = outlineRevisionFixture();
    $current = $novel->currentOutline;
    $data['summary'] = '新的摘要。';
    GenerationRun::factory()->for($novel)->create(['status' => $status]);

    expect(fn () => app(ApplyNovelOutlineRevisionAction::class)->handle(
        $novel,
        $data,
        $current->getKey(),
        $current->checksum,
    ))->toThrow(ValidationException::class, 'Generation Run');
})->with([RunStatus::Queued, RunStatus::Running]);

test('a revision cannot rewrite a beat referenced by a chapter plan', function () {
    [$novel, $volume, , $data] = outlineRevisionFixture();
    $current = $novel->currentOutline;
    $chapter = Chapter::factory()->for($novel)->for($volume)->create();
    ChapterPlan::factory()->for($chapter)->create();
    $data['volumes'][0]['arcs'][0]['beats'][0]['summary'] = '试图改写已经冻结的 Beat。';

    expect(fn () => app(ApplyNovelOutlineRevisionAction::class)->handle(
        $novel,
        $data,
        $current->getKey(),
        $current->checksum,
    ))->toThrow(ValidationException::class, '不能修改、移动或删除');
});

test('replaying identical current data returns the current version', function () {
    [$novel, , , $data] = outlineRevisionFixture();
    $current = $novel->currentOutline;

    $result = app(ApplyNovelOutlineRevisionAction::class)->handle(
        $novel,
        $data,
        $current->getKey(),
        $current->checksum,
    );

    expect($result->is($current))->toBeTrue()
        ->and($novel->outlines()->count())->toBe(1);
});
