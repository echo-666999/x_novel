<?php

use App\Exceptions\ContextBudgetExceededException;
use App\Services\TokenBudget;

test('token budget records section allocation and remaining capacity', function () {
    $allocation = app(TokenBudget::class)->allocate(100, [
        'l0' => ['rule' => '必须遵守'],
        'l1' => ['state' => '当前状态'],
    ]);

    expect($allocation->sections)->toHaveKeys(['l0', 'l1'])
        ->and($allocation->used)->toBe(array_sum($allocation->sections))
        ->and($allocation->remaining)->toBe(100 - $allocation->used)
        ->and($allocation->truncatedSections)->toBe([]);
});

test('token budget never truncates mandatory l0 or l1', function () {
    app(TokenBudget::class)->allocate(1, [
        'l0' => str_repeat('hard constraint ', 20),
        'l1' => str_repeat('canonical state ', 20),
    ]);
})->throws(ContextBudgetExceededException::class, 'L0/L1 至少需要');
