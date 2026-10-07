<?php

use App\Actions\Novels\ApplyNovelOutlineRevisionAction;
use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Actions\Novels\ReviseNovelOutlineNodeAction;
use App\Data\NormalizedNovelOutline;
use App\Enums\NovelOutlineStatus;
use App\Enums\RunStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\Volume;
use Database\Factories\Support\NormalizedOutlineDefinition;
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

test('a single draft node revision creates a new version without changing other nodes', function (string $nodeType, string $nodeKey, array $changes, string $attribute, mixed $expected) {
    $novel = Novel::factory()->create();
    $base = app(CreateNormalizedNovelOutlineVersionAction::class)->handle(
        $novel,
        NormalizedOutlineDefinition::make(),
    );

    $revision = app(ReviseNovelOutlineNodeAction::class)->handle(
        novel: $novel,
        expectedOutlineId: $base->getKey(),
        expectedOutlineChecksum: $base->checksum,
        nodeType: $nodeType,
        nodeKey: $nodeKey,
        changes: $changes,
    );

    $node = match ($nodeType) {
        'volume' => $revision->volumes()->where('volume_key', $nodeKey)->sole(),
        'arc' => $revision->arcs()->where('arc_key', $nodeKey)->sole(),
        'beat' => $revision->beats()->where('beat_key', $nodeKey)->sole(),
        'milestone' => $revision->milestones()->where('milestone_key', $nodeKey)->sole(),
    };

    expect($revision->version)->toBe(2)
        ->and($revision->based_on_outline_id)->toBe($base->getKey())
        ->and($base->fresh()->status)->toBe(NovelOutlineStatus::Superseded)
        ->and($node->{$attribute})->toBe($expected)
        ->and($base->volumes()->firstOrFail()->title)->toBe('Factory Volume');
})->with([
    'volume name' => ['volume', 'factory-volume', ['title' => '新的分卷名称'], 'title', '新的分卷名称'],
    'arc content' => ['arc', 'factory-arc', [
        'title' => '新的故事线',
        'goal' => '新的故事线目标。',
        'stakes' => '新的风险。',
        'completion_conditions' => ['新的完成条件。'],
    ], 'goal', '新的故事线目标。'],
    'beat content' => ['beat', 'factory-beat', [
        'title' => '新的节点',
        'summary' => '新的节点摘要。',
        'chapter_budget' => ['min' => 2, 'max' => 3],
        'acceptance_criteria' => ['新的节点验收条件。'],
        'must_include' => ['必须出现的内容。'],
        'must_not_include' => [],
    ], 'summary', '新的节点摘要。'],
    'milestone content' => ['milestone', 'factory-milestone', [
        'title' => '新的里程碑',
        'objective' => '新的阶段目标。',
        'acceptance_criteria' => ['新的里程碑验收条件。'],
        'must_include' => [],
        'must_not_include' => ['禁止提前完成。'],
    ], 'objective', '新的阶段目标。'],
]);

test('a single current node revision still uses protected current outline rules', function () {
    [$novel, $volume, $arc] = outlineRevisionFixture();
    $current = $novel->currentOutline;

    $revision = app(ReviseNovelOutlineNodeAction::class)->handle(
        novel: $novel,
        expectedOutlineId: $current->getKey(),
        expectedOutlineChecksum: $current->checksum,
        nodeType: 'arc',
        nodeKey: 'factory-arc',
        changes: [
            'title' => '修订后的运行态故事线',
            'goal' => '修订后的目标。',
            'stakes' => '修订后的风险。',
            'completion_conditions' => ['修订完成。'],
        ],
    );

    expect($revision->status)->toBe(NovelOutlineStatus::Current)
        ->and($novel->fresh()->current_outline_id)->toBe($revision->getKey())
        ->and($volume->fresh()->sourceOutlineVolume->novel_outline_id)->toBe($revision->getKey())
        ->and($arc->fresh()->title)->toBe('修订后的运行态故事线');
});

test('a single node revision rejects fields outside that node editing contract', function () {
    $novel = Novel::factory()->create();
    $base = app(CreateNormalizedNovelOutlineVersionAction::class)->handle($novel, NormalizedOutlineDefinition::make());

    expect(fn () => app(ReviseNovelOutlineNodeAction::class)->handle(
        novel: $novel,
        expectedOutlineId: $base->getKey(),
        expectedOutlineChecksum: $base->checksum,
        nodeType: 'beat',
        nodeKey: 'factory-beat',
        changes: ['key' => 'rewritten-key'],
    ))->toThrow(ValidationException::class, '不允许修改的字段');
});
