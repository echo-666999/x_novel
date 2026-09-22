<?php

namespace App\Services;

use App\Models\StoryArc;

class StoryArcBeatContract
{
    /** @return array<int, array{beat_key: string, beat_index: int, text: string}> */
    public function forArc(StoryArc $arc): array
    {
        return collect($arc->beats ?? [])->values()->map(
            function (mixed $beat, int $index): array {
                $text = is_array($beat)
                    ? (string) ($beat['title'] ?? $beat['summary'] ?? $beat['text'] ?? '')
                    : (string) $beat;
                $explicitKey = is_array($beat)
                    ? trim((string) ($beat['key'] ?? $beat['beat_key'] ?? ''))
                    : '';

                return [
                    'beat_key' => $explicitKey !== '' ? $explicitKey : $this->key($text),
                    'beat_index' => is_array($beat)
                        ? (int) ($beat['sequence'] ?? $beat['beat_index'] ?? $index + 1)
                        : $index + 1,
                    'text' => $text,
                ];
            },
        )->all();
    }

    public function key(string $beat): string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $beat)));

        return 'beat-'.substr(hash('sha256', $normalized), 0, 16);
    }
}
