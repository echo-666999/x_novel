<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\ForeshadowingStatus;
use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Enums\StoryEventStatus;
use App\Models\Chapter;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\User;
use App\Services\ForeshadowingHistoryRepairService;
use App\Services\ProjectionRebuilder;
use App\Services\StoryStateRebuilder;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('dry run freezes evidence based changes without writing business data', function () {
    $fixture = historyRepairFixture();

    $preview = app(ForeshadowingHistoryRepairService::class)->preview($fixture['plan']);

    expect($preview['source_rebuild_matches_current'])->toBeTrue()
        ->and($preview['state_changes'])->toHaveCount(2)
        ->and(data_get($preview, 'after_foreshadowings.'.$fixture['foreshadowing']->getKey().'.status'))->toBe('abandoned')
        ->and(data_get($preview, 'after_foreshadowings.'.$fixture['foreshadowing']->getKey().'.reinforce_count'))->toBe(1)
        ->and($fixture['source']->fresh()->status)->toBe(StoryEventStatus::Active)
        ->and($fixture['novel']->fresh()->canonicalStateVersion->version)->toBe(2)
        ->and($fixture['memory']->fresh()->status)->toBe(MemoryStatus::Active);
});

test('execute invalidates and replaces events rebuilds state memory and projection exactly once', function () {
    $fixture = historyRepairFixture();
    $actor = User::factory()->create();
    $service = app(ForeshadowingHistoryRepairService::class);

    $result = $service->execute($fixture['plan'], $actor->getKey());
    $replacement = StoryEvent::query()->where('event_type', EventType::ForeshadowingPlanted)->sole();
    $audit = StoryEvent::query()->where('event_type', EventType::EventCorrected)->sole();
    $version = $fixture['novel']->fresh()->canonicalStateVersion;

    expect($result['status'])->toBe('applied')
        ->and($fixture['source']->fresh()->status)->toBe(StoryEventStatus::Invalidated)
        ->and($replacement->state_version)->toBe(1)
        ->and($replacement->evidence)->toBe($fixture['source']->evidence)
        ->and($audit->payload['repair_key'])->toBe($result['repair_key'])
        ->and($audit->payload['actor_id'])->toBe($actor->getKey())
        ->and($version->version)->toBe(3)
        ->and(data_get($version->state, 'foreshadowings.'.$fixture['foreshadowing']->getKey().'.status'))->toBe('abandoned')
        ->and(data_get($version->state, 'foreshadowings.'.$fixture['foreshadowing']->getKey().'.reinforce_count'))->toBe(1)
        ->and($fixture['foreshadowing']->fresh()->status)->toBe(ForeshadowingStatus::Abandoned)
        ->and($fixture['foreshadowing']->fresh()->reinforce_count)->toBe(1)
        ->and($fixture['foreshadowing']->fresh()->setup_chapter_id)->toBe($fixture['chapter']->getKey())
        ->and($fixture['memory']->fresh()->status)->toBe(MemoryStatus::Invalid)
        ->and(Memory::query()->where('source_id', $replacement->getKey())->where('status', MemoryStatus::Active)->count())->toBe(1);

    $counts = [StoryEvent::query()->count(), StoryStateVersion::query()->count(), Memory::query()->count()];
    $duplicate = $service->execute($fixture['plan'], $actor->getKey());

    expect($duplicate['status'])->toBe('already_applied')
        ->and([StoryEvent::query()->count(), StoryStateVersion::query()->count(), Memory::query()->count()])->toBe($counts);
});

test('execute requires an operator and stale or non canonical evidence plans do not write', function () {
    $fixture = historyRepairFixture();
    $service = app(ForeshadowingHistoryRepairService::class);

    expect(fn () => $service->execute($fixture['plan']))
        ->toThrow(ValidationException::class, '有效操作者');

    $stale = $fixture['plan'];
    $stale['expected_state_checksum'] = str_repeat('f', 64);
    expect(fn () => $service->execute($stale, User::factory()->create()->getKey()))
        ->toThrow(ValidationException::class, '冻结计划不一致');

    $badEvidence = $fixture['plan'];
    DB::table('story_events')->where('id', $fixture['source']->getKey())->update([
        'evidence' => json_encode([['quote' => '正文中不存在的证据。', 'artifact_id' => $fixture['source']->chapter->canonical_artifact_id]], JSON_THROW_ON_ERROR),
    ]);
    expect(fn () => $service->preview($badEvidence))
        ->toThrow(ValidationException::class, '事件 #');

    expect($fixture['novel']->fresh()->canonicalStateVersion->version)->toBe(2)
        ->and(StoryStateVersion::query()->count())->toBe(2);
});

test('a projection failure rolls back every history repair write', function () {
    $fixture = historyRepairFixture();
    $actor = User::factory()->create();
    $projection = Mockery::mock(ProjectionRebuilder::class)->makePartial();
    $projection->shouldReceive('inspect')->andReturnUsing(
        fn (Novel $novel) => app(ProjectionRebuilder::class)->inspect($novel),
    );
    $projection->shouldReceive('rebuild')->once()->andThrow(new RuntimeException('projection failed'));
    $service = new ForeshadowingHistoryRepairService(
        app(StoryStateRebuilder::class),
        app(StoryStateService::class),
        $projection,
    );

    expect(fn () => $service->execute($fixture['plan'], $actor->getKey()))
        ->toThrow(RuntimeException::class, 'projection failed');

    expect($fixture['source']->fresh()->status)->toBe(StoryEventStatus::Active)
        ->and($fixture['memory']->fresh()->status)->toBe(MemoryStatus::Active)
        ->and($fixture['novel']->fresh()->canonicalStateVersion->version)->toBe(2)
        ->and(StoryEvent::query()->count())->toBe(2)
        ->and(StoryStateVersion::query()->count())->toBe(2)
        ->and(Memory::query()->count())->toBe(1);
});

test('the plan refuses to invalidate an event that is still referenced by a fact', function () {
    $fixture = historyRepairFixture();
    Fact::factory()->for($fixture['novel'])->create(['source_event_id' => $fixture['source']->getKey()]);

    expect(fn () => app(ForeshadowingHistoryRepairService::class)->preview($fixture['plan']))
        ->toThrow(ValidationException::class, '仍被 Fact 引用');

    expect($fixture['source']->fresh()->status)->toBe(StoryEventStatus::Active)
        ->and($fixture['novel']->fresh()->canonicalStateVersion->version)->toBe(2);
});

test('the command defaults to dry run and requires an explicit execute with an actor', function () {
    $fixture = historyRepairFixture();
    $actor = User::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'foreshadowing-repair-');
    file_put_contents($path, json_encode($fixture['plan'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

    try {
        $this->artisan('foreshadowing:repair-history', ['plan' => $path])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();
        expect($fixture['novel']->fresh()->canonicalStateVersion->version)->toBe(2);

        $this->artisan('foreshadowing:repair-history', [
            'plan' => $path,
            '--execute' => true,
            '--actor' => $actor->getKey(),
        ])->expectsOutputToContain('修复完成')->assertSuccessful();

        $counts = [StoryEvent::query()->count(), StoryStateVersion::query()->count()];
        $this->artisan('foreshadowing:repair-history', [
            'plan' => $path,
            '--execute' => true,
            '--actor' => $actor->getKey(),
        ])->expectsOutputToContain('未产生重复写入')->assertSuccessful();

        expect([StoryEvent::query()->count(), StoryStateVersion::query()->count()])->toBe($counts);
    } finally {
        @unlink($path);
    }
});

/** @return array<string, mixed> */
function historyRepairFixture(): array
{
    $novel = Novel::factory()->create(['title' => '测试小说', 'current_chapter_sequence' => 2]);
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'status' => ForeshadowingStatus::Idea,
        'reinforce_count' => 0,
    ]);
    $baseline = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 1,
        'status' => ChapterStatus::Canonical,
    ]);
    $run = GenerationRun::factory()->for($novel)->create(['chapter_id' => $chapter->getKey()]);
    $content = '他第一次看见染血星图。后来星图再次发光。';
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $chapter->update(['canonical_artifact_id' => $artifact->getKey()]);
    $source = StoryEvent::factory()->for($novel)->for($chapter)->create([
        'event_type' => EventType::ForeshadowingReinforced,
        'subject_type' => 'foreshadowing',
        'subject_id' => (string) $foreshadowing->getKey(),
        'payload' => ['development' => '首次出现染血星图'],
        'evidence' => [['quote' => '他第一次看见染血星图。', 'artifact_id' => $artifact->getKey()]],
        'state_version' => 1,
    ]);
    $later = StoryEvent::factory()->for($novel)->for($chapter)->create([
        'event_type' => EventType::ForeshadowingReinforced,
        'subject_type' => 'foreshadowing',
        'subject_id' => (string) $foreshadowing->getKey(),
        'payload' => ['development' => '星图再次发光'],
        'evidence' => [['quote' => '后来星图再次发光。', 'artifact_id' => $artifact->getKey()]],
        'state_version' => 2,
    ]);
    $state = app(StoryStateRebuilder::class)->replay($baseline->state, collect([$source, $later]));
    $current = StoryStateVersion::factory()->for($novel)->for($chapter)->create([
        'version' => 2,
        'state' => $state,
        'checksum' => app(StoryStateService::class)->checksum($state),
    ]);
    $novel->update(['canonical_state_version_id' => $current->getKey()]);
    $memory = Memory::factory()->for($novel)->create([
        'type' => MemoryType::Foreshadowing,
        'source_id' => $source->getKey(),
        'summary' => '他第一次看见染血星图。',
    ]);
    $plan = [
        'schema_version' => 1,
        'novel_id' => $novel->getKey(),
        'novel_title' => $novel->title,
        'expected_state_version' => 2,
        'expected_state_checksum' => $current->checksum,
        'baseline_state_version' => 0,
        'event_repairs' => [[
            'event_id' => $source->getKey(),
            'action' => 'replace',
            'expected_event_type' => EventType::ForeshadowingReinforced->value,
            'replacement_event_type' => EventType::ForeshadowingPlanted->value,
            'reason' => '首次出现应当是铺设。',
        ]],
        'state_corrections' => [[
            'path' => "foreshadowings.{$foreshadowing->getKey()}.status",
            'value' => ForeshadowingStatus::Abandoned->value,
            'reason' => '开放式承诺停止作为伏笔。',
        ]],
        'unresolved_items' => [],
    ];

    return compact('novel', 'foreshadowing', 'baseline', 'chapter', 'source', 'later', 'current', 'memory', 'plan');
}
