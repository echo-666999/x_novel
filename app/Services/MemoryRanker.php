<?php

namespace App\Services;

use App\Data\MemoryQuery;
use App\Data\MemorySearchResult;
use App\Models\Memory;
use Illuminate\Support\Collection;

class MemoryRanker
{
    public function __construct(private readonly TokenBudget $tokenBudget) {}

    /**
     * @param  Collection<int, MemorySearchResult>  $candidates
     * @return Collection<int, MemorySearchResult>
     */
    public function rank(Collection $candidates, MemoryQuery $query): Collection
    {
        $finalK = $query->finalK ?? (int) config('context.memory_final_k', 10);
        $tokenLimit = $query->tokenBudget ?? (int) config('context.long_term_memory_token_budget', 1_500);
        $targetChapter = $query->chapterTo ?? $candidates->max(fn (MemorySearchResult $result): int => $result->memory->valid_from_chapter) ?? 1;
        $ranked = $candidates
            ->map(fn (MemorySearchResult $candidate): MemorySearchResult => $this->score($candidate, $query, $targetChapter))
            ->sortByDesc(fn (MemorySearchResult $result): float => $result->finalScore)
            ->values();
        $selected = collect();
        $usedTokens = 0;

        return $ranked->map(function (MemorySearchResult $candidate) use ($selected, $finalK, $tokenLimit, &$usedTokens): MemorySearchResult {
            $reason = $this->duplicateReason($candidate, $selected);

            if ($reason === null && $selected->count() >= $finalK) {
                $reason = '超出结果数量限制';
            }

            if ($reason === null && $usedTokens + $candidate->estimatedTokens > $tokenLimit) {
                $reason = '超出长期记忆 Token Budget';
            }

            $isSelected = $reason === null;
            $result = new MemorySearchResult(
                memory: $candidate->memory,
                similarity: $candidate->similarity,
                salienceScore: $candidate->salienceScore,
                recencyScore: $candidate->recencyScore,
                entityMatchScore: $candidate->entityMatchScore,
                finalScore: $candidate->finalScore,
                selected: $isSelected,
                reason: $reason ?? '综合评分入选',
                estimatedTokens: $candidate->estimatedTokens,
            );

            if ($isSelected) {
                $selected->push($result);
                $usedTokens += $candidate->estimatedTokens;
            }

            return $result;
        });
    }

    private function score(MemorySearchResult $candidate, MemoryQuery $query, int $targetChapter): MemorySearchResult
    {
        $memory = $candidate->memory;
        $salience = (float) $memory->salience;
        $window = max(1, (int) config('context.memory_recency_window', 100));
        $age = max(0, $targetChapter - $memory->valid_from_chapter);
        $recency = max(0.0, 1.0 - ($age / $window));
        $entityMatch = $this->entityMatch($memory, $query->entityIds);
        $score =
            $candidate->similarity * (float) config('context.memory_ranking.similarity', 0.60)
            + $salience * (float) config('context.memory_ranking.salience', 0.20)
            + $recency * (float) config('context.memory_ranking.recency', 0.10)
            + $entityMatch * (float) config('context.memory_ranking.entity_match', 0.10);
        $payload = ['summary' => $memory->summary, 'source' => $memory->sourceLabel()];

        return new MemorySearchResult(
            memory: $memory,
            similarity: $candidate->similarity,
            salienceScore: $salience,
            recencyScore: $recency,
            entityMatchScore: $entityMatch,
            finalScore: round($score, 6),
            estimatedTokens: $this->tokenBudget->estimate($payload),
        );
    }

    /** @param Collection<int, MemorySearchResult> $selected */
    private function duplicateReason(MemorySearchResult $candidate, Collection $selected): ?string
    {
        foreach ($selected as $existing) {
            if ($existing->memory->source_type === $candidate->memory->source_type
                && $existing->memory->source_id === $candidate->memory->source_id) {
                return '与已选结果来源重复';
            }

            if ($this->sameEntities($existing->memory, $candidate->memory)
                && $this->memorySimilarity($existing->memory, $candidate->memory)
                    >= (float) config('context.memory_semantic_dedup_threshold', 0.98)) {
                return '与已选结果语义重复';
            }
        }

        return null;
    }

    /** @param array<string, array<int, int|string>> $requested */
    private function entityMatch(Memory $memory, array $requested): float
    {
        $needles = collect($requested)->flatMap(
            fn (array $ids, string $type): array => array_map(fn (int|string $id): string => $type.':'.$id, $ids),
        )->unique();

        if ($needles->isEmpty()) {
            return 0.0;
        }

        $memoryEntities = collect($memory->entities ?? [])->flatMap(
            fn (array $ids, string $type): array => array_map(fn (int|string $id): string => $type.':'.$id, $ids),
        )->unique();

        return $needles->intersect($memoryEntities)->count() / $needles->count();
    }

    private function sameEntities(Memory $left, Memory $right): bool
    {
        $leftEntities = collect($left->entities ?? [])->sortKeys()->all();
        $rightEntities = collect($right->entities ?? [])->sortKeys()->all();

        return $leftEntities !== [] && $leftEntities === $rightEntities;
    }

    private function memorySimilarity(Memory $left, Memory $right): float
    {
        $leftVector = $this->parseVector((string) $left->getRawOriginal('embedding'));
        $rightVector = $this->parseVector((string) $right->getRawOriginal('embedding'));

        if ($leftVector === [] || count($leftVector) !== count($rightVector)) {
            return -1.0;
        }

        $dot = $leftNorm = $rightNorm = 0.0;
        foreach ($leftVector as $index => $value) {
            $dot += $value * $rightVector[$index];
            $leftNorm += $value ** 2;
            $rightNorm += $rightVector[$index] ** 2;
        }

        return $leftNorm > 0.0 && $rightNorm > 0.0 ? $dot / (sqrt($leftNorm) * sqrt($rightNorm)) : -1.0;
    }

    /** @return array<int, float> */
    private function parseVector(string $vector): array
    {
        $decoded = json_decode($vector, true);

        return is_array($decoded) ? array_map(static fn (mixed $value): float => (float) $value, $decoded) : [];
    }
}
