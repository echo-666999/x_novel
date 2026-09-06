<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('current returns the canonical state version', function () {
    $novel = Novel::factory()->create();
    $versionZero = app(InitializeNovelStateAction::class)->handle($novel);
    $versionOne = StoryStateVersion::factory()->for($novel)->create(['version' => 1]);
    $novel->update(['canonical_state_version_id' => $versionOne->getKey()]);

    expect(app(StoryStateService::class)->current($novel)->is($versionOne))->toBeTrue()
        ->and($versionZero->is($versionOne))->toBeFalse();
});

test('find version is scoped to the requested novel', function () {
    $novel = Novel::factory()->create();
    $version = StoryStateVersion::factory()->for($novel)->create(['version' => 3]);
    $otherNovel = Novel::factory()->create();
    StoryStateVersion::factory()->for($otherNovel)->create(['version' => 3]);
    $service = app(StoryStateService::class);

    expect($service->findVersion($novel, 3)->is($version))->toBeTrue()
        ->and($service->findVersion($novel, 99))->toBeNull()
        ->and($service->findVersion($otherNovel, 3)->novel->is($otherNovel))->toBeTrue();
});

test('preview patch recursively merges objects replaces lists and leaves input unchanged', function () {
    $state = [
        'characters' => [
            'hero' => [
                'location' => '旧港',
                'knowledge' => ['tide' => false],
                'items' => ['旧钥匙'],
            ],
        ],
        'timeline' => ['day' => 3],
    ];
    $patch = [
        'characters' => [
            'hero' => [
                'location' => '灯塔',
                'knowledge' => ['tide' => true, 'signal' => true],
                'items' => ['潮汐罗盘'],
            ],
        ],
    ];

    $preview = app(StoryStateService::class)->previewPatch($state, $patch);

    expect($preview)->toBe([
        'characters' => [
            'hero' => [
                'location' => '灯塔',
                'knowledge' => ['tide' => true, 'signal' => true],
                'items' => ['潮汐罗盘'],
            ],
        ],
        'timeline' => ['day' => 3],
    ])->and($state['characters']['hero']['location'])->toBe('旧港')
        ->and($state['characters']['hero']['items'])->toBe(['旧钥匙']);
});

test('checksum is stable for equivalent associative key order and preserves list order', function () {
    $service = app(StoryStateService::class);
    $first = [
        'world' => ['zeta' => 2, 'alpha' => 1],
        'characters' => ['hero' => ['status' => 'active', 'items' => ['a', 'b']]],
    ];
    $sameStateDifferentKeyOrder = [
        'characters' => ['hero' => ['items' => ['a', 'b'], 'status' => 'active']],
        'world' => ['alpha' => 1, 'zeta' => 2],
    ];
    $differentListOrder = [
        'characters' => ['hero' => ['items' => ['b', 'a'], 'status' => 'active']],
        'world' => ['alpha' => 1, 'zeta' => 2],
    ];

    expect($service->checksum($first))->toBe($service->checksum($sameStateDifferentKeyOrder))
        ->not->toBe($service->checksum($differentListOrder));
});

test('diff returns stable paths and distinguishes missing values from null', function () {
    $changes = app(StoryStateService::class)->diff(
        [
            'characters' => ['hero' => ['location' => null, 'removed' => true]],
            'timeline' => ['day' => 2],
        ],
        [
            'characters' => ['hero' => ['location' => '灯塔', 'added' => null]],
            'timeline' => ['day' => 3],
        ],
    );

    expect(array_column($changes, 'path'))->toBe([
        'characters.hero.added',
        'characters.hero.location',
        'characters.hero.removed',
        'timeline.day',
    ])->and($changes[0])->toMatchArray([
        'before' => null,
        'after' => null,
        'before_missing' => true,
        'after_missing' => false,
        'type' => 'added',
    ])->and($changes[1]['type'])->toBe('changed')
        ->and($changes[2]['type'])->toBe('removed')
        ->and($changes[3]['before'])->toBe(2)
        ->and($changes[3]['after'])->toBe(3);
});
