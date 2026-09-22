<?php

namespace App\Services;

use App\Models\Novel;
use Illuminate\Validation\ValidationException;

class OutlineContextBuilder
{
    public function __construct(private readonly OutlineProgressResolver $progressResolver) {}

    /** @return array<string, mixed> */
    public function build(Novel $novel): array
    {
        $target = $this->progressResolver->resolve($novel);
        if ($target === null) {
            throw ValidationException::withMessages([
                'outline' => '当前 Novel 没有可规划的 Current Outline Target。',
            ]);
        }

        return [
            ...$target->toArray(),
            'primary_beat_key' => $target->beat['key'],
            'primary_beat_sequence' => $target->beat['sequence'],
            'chapter_budget' => $target->beat['chapter_budget'],
            'acceptance_criteria' => $target->beat['acceptance_criteria'],
            'must_include' => $target->beat['must_include'],
            'must_not_include' => $target->beat['must_not_include'],
            'character_candidates' => $target->beat['character_candidates'],
            'world_entity_candidates' => $target->beat['world_entity_candidates'],
        ];
    }
}
