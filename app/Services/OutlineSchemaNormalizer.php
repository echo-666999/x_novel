<?php

namespace App\Services;

class OutlineSchemaNormalizer
{
    public function __construct(private readonly StoryArcBeatContract $beatContract) {}

    /**
     * @param  array<int, mixed>  $beats
     * @return array<int, array<string, mixed>>
     */
    public function normalizeBeats(array $beats): array
    {
        return collect($beats)
            ->values()
            ->map(fn (mixed $beat, int $index): array => $this->normalizeBeat($beat, $index + 1))
            ->all();
    }

    /** @return array<string, mixed> */
    private function normalizeBeat(mixed $beat, int $fallbackSequence): array
    {
        if (! is_array($beat)) {
            $text = (string) $beat;

            return [
                'key' => $this->beatContract->key($text),
                'sequence' => $fallbackSequence,
                'title' => $text,
                'summary' => $text,
                'chapter_budget' => ['min' => 1, 'max' => null],
                'acceptance_criteria' => [$text],
                'must_include' => [],
                'must_not_include' => [],
                'character_candidates' => [],
                'world_entity_candidates' => [],
            ];
        }

        $title = trim((string) ($beat['title'] ?? $beat['text'] ?? $beat['summary'] ?? ''));
        $summary = trim((string) ($beat['summary'] ?? $title));
        $explicitKey = trim((string) ($beat['key'] ?? $beat['beat_key'] ?? ''));
        $budget = is_array($beat['chapter_budget'] ?? null) ? $beat['chapter_budget'] : [];

        return [
            ...$beat,
            'key' => $explicitKey !== '' ? $explicitKey : $this->beatContract->key($title),
            'sequence' => (int) ($beat['sequence'] ?? $beat['beat_index'] ?? $fallbackSequence),
            'title' => $title,
            'summary' => $summary,
            'chapter_budget' => [
                'min' => (int) ($budget['min'] ?? 1),
                'max' => array_key_exists('max', $budget) && $budget['max'] !== null
                    ? (int) $budget['max']
                    : null,
            ],
            'acceptance_criteria' => $this->arrayValue($beat, 'acceptance_criteria', [$title]),
            'must_include' => $this->arrayValue($beat, 'must_include'),
            'must_not_include' => $this->arrayValue($beat, 'must_not_include'),
            'character_candidates' => $this->arrayValue($beat, 'character_candidates'),
            'world_entity_candidates' => $this->arrayValue($beat, 'world_entity_candidates'),
        ];
    }

    /**
     * @param  array<string, mixed>  $beat
     * @param  array<int, mixed>  $default
     * @return array<int, mixed>
     */
    private function arrayValue(array $beat, string $key, array $default = []): array
    {
        return is_array($beat[$key] ?? null) ? array_values($beat[$key]) : $default;
    }
}
