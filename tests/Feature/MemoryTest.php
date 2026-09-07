<?php

use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Models\Memory;
use App\Models\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('memory stores queryable provenance validity and embedding metadata', function () {
    $novel = Novel::factory()->create();
    $memory = Memory::factory()->for($novel)->create([
        'type' => MemoryType::CharacterMilestone,
        'source_type' => 'story_event',
        'source_id' => 42,
        'summary' => '沈澜第一次独立守住灯塔。',
        'entities' => ['characters' => [7], 'locations' => [9]],
        'salience' => 0.925,
        'embedding_model' => 'text-embedding-test',
        'valid_from_chapter' => 12,
        'valid_to_chapter' => 20,
    ]);

    expect($memory->fresh())
        ->type->toBe(MemoryType::CharacterMilestone)
        ->status->toBe(MemoryStatus::Active)
        ->entities->toBe(['characters' => [7], 'locations' => [9]])
        ->salience->toBe('0.925')
        ->valid_from_chapter->toBe(12)
        ->valid_to_chapter->toBe(20)
        ->and($memory->sourceLabel())->toBe('故事事件 #42')
        ->and($novel->memories()->sole()->is($memory))->toBeTrue();
});

test('active scope excludes invalid memories', function () {
    $novel = Novel::factory()->create();
    $active = Memory::factory()->for($novel)->create();
    Memory::factory()->for($novel)->create(['status' => MemoryStatus::Invalid]);

    expect($novel->memories()->active()->get())->toHaveCount(1)
        ->and($novel->memories()->active()->sole()->is($active))->toBeTrue();
});
