<?php

namespace App\Data;

readonly class VolumeCompletionResult
{
    /**
     * @param  array<int, array{key: string, label: string, status: string, message: string}>  $checks
     */
    public function __construct(public array $checks) {}

    public function canComplete(): bool
    {
        return collect($this->checks)->doesntContain(fn (array $check): bool => $check['status'] === 'BLOCK');
    }
}
