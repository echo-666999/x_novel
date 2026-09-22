<?php

namespace App\Data;

use Illuminate\Validation\ValidationException;

readonly class NovelOutlineValidationResult
{
    /** @param array<int, string> $errors */
    public function __construct(public array $errors) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function assertValid(): void
    {
        if (! $this->isValid()) {
            throw ValidationException::withMessages(['outline' => $this->errors]);
        }
    }
}
