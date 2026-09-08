<?php

use App\Actions\Generation\GenerateNextChapterAction;
use App\Actions\Novels\CompleteNovelAction;
use App\Enums\NovelStatus;
use App\Exceptions\GenerationPreflightException;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\StoryStateVersion;
use App\Services\EndingAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('a novel with a passing ending audit can be completed and generation is stopped', function () {
    $novel = completeNovelFixture(settings: ['auto_generate' => true]);
    app(EndingAuditService::class)->audit($novel);

    $completed = app(CompleteNovelAction::class)->handle($novel);

    expect($completed->status)->toBe(NovelStatus::Completed)
        ->and(data_get($completed->settings, 'auto_generate'))->toBeFalse()
        ->and(fn () => app(GenerateNextChapterAction::class)->handle($completed))
        ->toThrow(GenerationPreflightException::class);
});

test('critical closure debt prevents completing the novel', function () {
    $novel = completeNovelFixture([
        'reader_promises' => [
            'origin' => ['title' => '揭示火种来源', 'status' => 'open', 'importance' => 'critical'],
        ],
    ]);
    app(EndingAuditService::class)->audit($novel);

    expect(fn () => app(CompleteNovelAction::class)->handle($novel))
        ->toThrow(ValidationException::class, '必须先完成并通过结局审计')
        ->and($novel->fresh()->status)->toBe(NovelStatus::Completing);
});

test('a novel cannot be completed before an ending audit has passed', function () {
    $novel = completeNovelFixture();

    expect(fn () => app(CompleteNovelAction::class)->handle($novel))
        ->toThrow(ValidationException::class, '必须先完成并通过结局审计')
        ->and($novel->fresh()->status)->toBe(NovelStatus::Completing);
});

test('a previous pass cannot complete the novel after critical debt changes', function () {
    $novel = completeNovelFixture();
    app(EndingAuditService::class)->audit($novel);
    $state = $novel->canonicalStateVersion->state;
    $state['reader_promises'] = [
        'origin' => ['title' => '揭示火种来源', 'status' => 'open', 'importance' => 'critical'],
    ];
    $currentState = StoryStateVersion::factory()->for($novel)->create([
        'version' => 1,
        'state' => $state,
        'checksum' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    ]);
    $novel->update(['canonical_state_version_id' => $currentState->getKey()]);

    expect(fn () => app(CompleteNovelAction::class)->handle($novel))
        ->toThrow(ValidationException::class, '结局审计未通过')
        ->and($novel->fresh()->status)->toBe(NovelStatus::Completing);
});

test('completing a novel is idempotent', function () {
    $novel = completeNovelFixture();
    app(EndingAuditService::class)->audit($novel);
    $action = app(CompleteNovelAction::class);

    $first = $action->handle($novel);
    $second = $action->handle($novel);

    expect($first->status)->toBe(NovelStatus::Completed)
        ->and($second->status)->toBe(NovelStatus::Completed)
        ->and($novel->fresh()->status)->toBe(NovelStatus::Completed);
});

test('a novel outside completing mode cannot be completed', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);

    expect(fn () => app(CompleteNovelAction::class)->handle($novel))
        ->toThrow(ValidationException::class, '只有收束中的小说可以完结')
        ->and($novel->fresh()->status)->toBe(NovelStatus::Generating);
});

/**
 * @param  array<string, mixed>  $stateOverrides
 * @param  array<string, mixed>  $settings
 */
function completeNovelFixture(array $stateOverrides = [], array $settings = []): Novel
{
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Completing,
        'settings' => $settings,
    ]);
    NovelBible::factory()->for($novel)->create();
    $state = StoryStateVersion::factory()->for($novel)->create([
        'state' => array_replace_recursive([
            'schema_version' => 1,
            'characters' => [],
            'relationships' => [],
            'locations' => [],
            'items' => [],
            'world' => ['crises' => []],
            'timeline' => [],
            'open_threads' => [],
            'foreshadowings' => [],
            'reader_promises' => [],
            'character_arcs' => [['title' => '主角成长弧', 'status' => 'completed']],
        ], $stateOverrides),
    ]);
    $novel->update(['canonical_state_version_id' => $state->getKey()]);

    return $novel->fresh();
}
