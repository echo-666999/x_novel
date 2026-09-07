<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Actions\Story\ManualCanonicalCorrectionAction;
use App\Enums\EventType;
use App\Enums\StoryEventStatus;
use App\Filament\Resources\Novels\Pages\ViewNovelStoryState;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\User;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function manualCorrectionFixture(): array
{
    $novel = Novel::factory()->create();
    $initial = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create();
    $state = [
        ...$initial->state,
        'characters' => ['42' => ['location' => '长安']],
    ];
    $current = StoryStateVersion::factory()->for($novel)->for($chapter)->create([
        'version' => 1,
        'state' => $state,
        'checksum' => app(StoryStateService::class)->checksum($state),
    ]);
    $novel->update(['canonical_state_version_id' => $current->getKey()]);

    return compact('novel', 'initial', 'chapter', 'current');
}

test('manual correction appends an event and creates a new immutable state version', function () {
    $fixture = manualCorrectionFixture();

    $version = app(ManualCanonicalCorrectionAction::class)->execute(
        $fixture['novel'], 1, 'characters.42.location', '洛阳', '此前地点录入错误。',
    );

    $event = StoryEvent::query()->sole();
    expect($version->version)->toBe(2)
        ->and($version->state['characters']['42']['location'])->toBe('洛阳')
        ->and($fixture['current']->fresh()->state['characters']['42']['location'])->toBe('长安')
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($version->getKey())
        ->and($event->event_type)->toBe(EventType::ManualCorrection)
        ->and($event->status)->toBe(StoryEventStatus::Active)
        ->and($event->state_version)->toBe(2)
        ->and($event->payload['reason'])->toBe('此前地点录入错误。')
        ->and($event->payload['before'])->toBe('长安')
        ->and($event->payload['after'])->toBe('洛阳');
});

test('manual correction requires a reason and a declared state path', function (string $path, string $reason) {
    $fixture = manualCorrectionFixture();

    expect(fn () => app(ManualCanonicalCorrectionAction::class)->execute(
        $fixture['novel'], 1, $path, '洛阳', $reason,
    ))->toThrow(ValidationException::class);

    expect(StoryEvent::query()->count())->toBe(0)
        ->and($fixture['novel']->storyStateVersions()->count())->toBe(2);
})->with([
    ['characters.42.location', ''],
    ['unknown.42.location', '修正非法路径。'],
]);

test('manual correction rejects a stale expected version without partial writes', function () {
    $fixture = manualCorrectionFixture();
    $action = app(ManualCanonicalCorrectionAction::class);
    $action->execute($fixture['novel'], 1, 'characters.42.location', '洛阳', '首次修正。');

    expect(fn () => $action->execute(
        $fixture['novel'], 1, 'characters.42.location', '开封', '重复请求。',
    ))->toThrow(ValidationException::class, 'Expected State Version');

    expect(StoryEvent::query()->count())->toBe(1)
        ->and($fixture['novel']->storyStateVersions()->count())->toBe(3)
        ->and($fixture['novel']->fresh()->canonicalStateVersion->state['characters']['42']['location'])->toBe('洛阳');
});

test('story state inspector exposes the reason required manual correction action', function () {
    $this->actingAs(User::factory()->create());
    $fixture = manualCorrectionFixture();

    Livewire::test(ViewNovelStoryState::class, ['record' => $fixture['novel']->getRouteKey()])
        ->assertActionExists('manualCorrection')
        ->callAction('manualCorrection', data: [
            'expected_state_version' => 1,
            'path' => 'characters.42.location',
            'value' => '"洛阳"',
            'reason' => '从正式设定核对后修正。',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Canonical Story State 已修正')
        ->assertSet('selectedVersion', 2);

    expect($fixture['novel']->fresh()->canonicalStateVersion->version)->toBe(2);
});
