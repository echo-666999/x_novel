<?php

namespace App\Services;

use App\Data\TokenAllocation;
use App\Exceptions\ContextBudgetExceededException;

class TokenBudget
{
    /** @param array<string, mixed> $sections */
    public function allocate(int $budget, array $sections, array $truncatedSections = []): TokenAllocation
    {
        if ($budget < 1) {
            throw new ContextBudgetExceededException(1, $budget);
        }

        $allocation = collect($sections)
            ->map(fn (mixed $content): int => $this->estimate($content))
            ->all();
        $required = array_sum($allocation);

        if ($required > $budget) {
            throw new ContextBudgetExceededException($required, $budget);
        }

        return new TokenAllocation(
            budget: $budget,
            used: $required,
            remaining: $budget - $required,
            sections: $allocation,
            truncatedSections: $truncatedSections,
        );
    }

    public function estimate(mixed $content): int
    {
        $serialized = is_string($content)
            ? $content
            : json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return max(1, (int) ceil(mb_strlen($serialized) / 3));
    }
}
