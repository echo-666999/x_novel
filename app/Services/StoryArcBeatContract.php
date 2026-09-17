<?php

namespace App\Services;

use App\Models\StoryArc;

class StoryArcBeatContract
{
    /** @return array<int, array{beat_key: string, beat_index: int, text: string}> */
    public function forArc(StoryArc $arc): array
    {
        return collect($arc->beats ?? [])->values()->map(
            fn (mixed $beat, int $index): array => [
                'beat_key' => $this->key((string) $beat),
                'beat_index' => $index + 1,
                'text' => (string) $beat,
            ],
        )->all();
    }

    public function key(string $beat): string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $beat)));

        return 'beat-'.substr(hash('sha256', $normalized), 0, 16);
    }
}
