<?php

use App\Actions\Novels\CleanupEditorialSettingsAction;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('cleanup deletes only editorial settings and preserves historical context snapshots', function () {
    $settings = [
        'editorial' => ['primary_style' => 'passionate'],
        'ai' => ['models' => ['writer' => 'novel-writer']],
        'budget' => ['chapter_max_cost' => 2.5],
        'auto_generate' => true,
        'automation' => ['review' => true],
    ];
    $snapshot = [
        'bible_version' => 1,
        'narrative_style' => ['primary_style' => 'legacy-style'],
    ];
    $novel = Novel::factory()->create(compact('settings'));
    NovelBible::factory()->for($novel)->create();
    $run = GenerationRun::factory()->for($novel)->create([
        'scope_id' => $novel->getKey(),
        'context_snapshot' => $snapshot,
    ]);

    $cleaned = app(CleanupEditorialSettingsAction::class)->execute();

    expect($cleaned)->toBe(1)
        ->and($novel->fresh()->settings)->toBe([
            'ai' => $settings['ai'],
            'budget' => $settings['budget'],
            'auto_generate' => true,
            'automation' => $settings['automation'],
        ])
        ->and($run->fresh()->context_snapshot)->toBe($snapshot);
});

test('cleanup is idempotent', function () {
    $novel = Novel::factory()->create([
        'settings' => [
            'editorial' => ['primary_style' => 'passionate'],
            'auto_generate' => false,
        ],
    ]);
    NovelBible::factory()->for($novel)->create();
    $action = app(CleanupEditorialSettingsAction::class);

    expect($action->execute())->toBe(1)
        ->and($action->execute())->toBe(0)
        ->and($novel->fresh()->settings)->toBe(['auto_generate' => false]);
});

test('cleanup refuses all writes when any novel has not completed bible migration', function () {
    $migrated = Novel::factory()->create([
        'settings' => [
            'editorial' => ['primary_style' => 'passionate'],
            'auto_generate' => true,
        ],
    ]);
    NovelBible::factory()->for($migrated)->create();

    $unmigrated = Novel::factory()->create([
        'settings' => ['editorial' => ['primary_style' => 'concise_cold']],
    ]);
    NovelBible::factory()->for($unmigrated)->create(['style_profile' => null]);

    try {
        app(CleanupEditorialSettingsAction::class)->execute();
        test()->fail('Expected cleanup to reject an incomplete Current Bible.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('migration')
            ->and($exception->errors()['migration'][0])->toContain("#{$unmigrated->getKey()}")
            ->toContain('文风设置不完整或无效');
    }

    expect($migrated->fresh()->settings)->toHaveKey('editorial')
        ->and($migrated->settings['auto_generate'])->toBeTrue()
        ->and($unmigrated->fresh()->settings)->toHaveKey('editorial');
});

test('cleanup refuses a novel without a current bible', function () {
    $novel = Novel::factory()->create([
        'settings' => ['editorial' => ['primary_style' => 'passionate']],
    ]);

    expect(fn () => app(CleanupEditorialSettingsAction::class)->execute())
        ->toThrow(ValidationException::class, "#{$novel->getKey()}《{$novel->title}》缺少 Current Bible");

    expect($novel->fresh()->settings)->toHaveKey('editorial');
});
