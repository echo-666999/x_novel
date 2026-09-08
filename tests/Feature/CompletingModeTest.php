<?php

use App\Actions\Novels\EnterCompletingModeAction;
use App\Enums\NovelStatus;
use App\Models\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('a generating novel can enter completing mode idempotently', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $action = app(EnterCompletingModeAction::class);

    $first = $action->handle($novel);
    $second = $action->handle($novel);

    expect($first->status)->toBe(NovelStatus::Completing)
        ->and($second->status)->toBe(NovelStatus::Completing)
        ->and($novel->fresh()->status)->toBe(NovelStatus::Completing);
});

test('a novel outside generation cannot enter completing mode', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft]);

    expect(fn () => app(EnterCompletingModeAction::class)->handle($novel))
        ->toThrow(ValidationException::class, '只有生成中的小说可以进入收束期');

    expect($novel->fresh()->status)->toBe(NovelStatus::Draft);
});
