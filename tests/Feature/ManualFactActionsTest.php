<?php

use App\Actions\Story\CreateManualFactAction;
use App\Actions\Story\SetFactLockAction;
use App\Actions\Story\SupersedeFactAction;
use App\Enums\FactHardness;
use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Novel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a manual fact is created active and cannot reference another novel', function () {
    $novel = Novel::factory()->create();
    $character = Character::factory()->for($novel)->create();
    $otherCharacter = Character::factory()->create();
    $attributes = [
        'subject_type' => 'character',
        'subject_id' => $character->getKey(),
        'predicate' => 'can_swim',
        'value' => false,
        'hardness' => FactHardness::Hard,
        'confidence' => 1,
        'locked' => true,
    ];

    $fact = app(CreateManualFactAction::class)->execute($novel, $attributes);

    expect($fact->source_type)->toBe(FactSourceType::Manual)
        ->and($fact->source_event_id)->toBeNull()
        ->and($fact->status)->toBe(FactStatus::Active)
        ->and($fact->locked)->toBeTrue()
        ->and($fact->value)->toBeFalse();

    expect(fn () => app(CreateManualFactAction::class)->execute($novel, [
        ...$attributes,
        'subject_id' => $otherCharacter->getKey(),
    ]))->toThrow(ModelNotFoundException::class);
});

test('lock and unlock are idempotent and scoped to the novel', function () {
    $novel = Novel::factory()->create();
    $fact = Fact::factory()->for($novel)->create(['locked' => false]);
    $action = app(SetFactLockAction::class);

    $action->execute($novel, $fact, true);
    $action->execute($novel, $fact, true);
    expect($fact->refresh()->locked)->toBeTrue();

    $action->execute($novel, $fact, false);
    $action->execute($novel, $fact, false);
    expect($fact->refresh()->locked)->toBeFalse();

    expect(fn () => $action->execute(Novel::factory()->create(), $fact, true))
        ->toThrow(ModelNotFoundException::class);
});

test('superseding is idempotent and preserves the historical fact', function () {
    $novel = Novel::factory()->create();
    $fact = Fact::factory()->for($novel)->create([
        'predicate' => 'can_swim',
        'value' => false,
        'locked' => true,
    ]);
    $action = app(SupersedeFactAction::class);

    $action->execute($novel, $fact);
    $action->execute($novel, $fact);

    expect($fact->refresh()->status)->toBe(FactStatus::Superseded)
        ->and($fact->predicate)->toBe('can_swim')
        ->and($fact->value)->toBeFalse()
        ->and($fact->locked)->toBeTrue();
});
