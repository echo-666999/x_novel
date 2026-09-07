<?php

namespace App\Data;

use App\Enums\StateFindingSeverity;
use Illuminate\Validation\ValidationException;

final readonly class StateValidationResult
{
    /** @param array<int, StateFinding> $findings */
    public function __construct(public array $findings) {}

    public function isBlocked(): bool
    {
        return collect($this->findings)->contains(
            fn (StateFinding $finding): bool => $finding->severity === StateFindingSeverity::Hard,
        );
    }

    public function decision(): string
    {
        return $this->isBlocked() ? 'BLOCK' : 'PASS';
    }

    public function assertCanCommit(): void
    {
        if (! $this->isBlocked()) {
            return;
        }

        throw ValidationException::withMessages([
            'state' => collect($this->findings)
                ->where('severity', StateFindingSeverity::Hard)
                ->map(fn (StateFinding $finding): string => "[{$finding->code}] {$finding->message}")
                ->all(),
        ]);
    }
}
