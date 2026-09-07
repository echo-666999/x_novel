<?php

namespace App\Services;

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingRequest;
use App\AI\Exceptions\AiProviderException;
use App\Data\MemoryQuery;
use App\Data\MemorySearchResult;
use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Models\Memory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MemoryQueryBuilder
{
    public function __construct(private readonly EmbeddingProvider $provider) {}

    /** @return Collection<int, MemorySearchResult> */
    public function search(MemoryQuery $query): Collection
    {
        $model = (string) config('ai.embedding.model');
        $dimensions = (int) config('ai.embedding.dimensions');
        $candidateK = $query->candidateK ?? (int) config('context.memory_candidate_k', 30);
        $finalK = $query->finalK ?? (int) config('context.memory_final_k', 10);

        if ($candidateK < 1 || $finalK < 1 || $finalK > $candidateK) {
            throw new InvalidArgumentException('Memory candidate_k 必须大于等于 final_k，且两者必须为正整数。');
        }

        $response = $this->provider->embed(new EmbeddingRequest(
            model: $model,
            input: $query->queryText,
            dimensions: $dimensions,
            metadata: ['novel_id' => $query->novelId, 'task_type' => 'memory_retrieval'],
        ));

        if ($response->model !== $model || count($response->embedding) !== $dimensions) {
            throw new AiProviderException(
                'embedding_dimensions_mismatch',
                '检索向量的模型或维度与 Memory 固定配置不一致。',
                false,
            );
        }

        $builder = $this->metadataQuery($query, $model);

        return DB::getDriverName() === 'pgsql'
            ? $this->searchPostgres($builder, $response->embedding, $candidateK, $finalK)
            : $this->searchInMemory($builder, $response->embedding, $candidateK, $finalK);
    }

    private function metadataQuery(MemoryQuery $query, string $model): Builder
    {
        $builder = Memory::query()
            ->where('novel_id', $query->novelId)
            ->where('status', MemoryStatus::Active)
            ->where('embedding_model', $model)
            ->whereNotNull('embedding');

        $types = collect($query->types)
            ->map(fn (MemoryType|string $type): string => $type instanceof MemoryType ? $type->value : $type)
            ->values()
            ->all();

        if ($types !== []) {
            $builder->whereIn('type', $types);
        }

        if ($query->chapterFrom !== null) {
            $builder->where(fn (Builder $validity): Builder => $validity
                ->whereNull('valid_to_chapter')
                ->orWhere('valid_to_chapter', '>=', $query->chapterFrom));
        }

        if ($query->chapterTo !== null) {
            $builder->where('valid_from_chapter', '<=', $query->chapterTo);
        }

        if ($query->excludeIds !== []) {
            $builder->whereNotIn('id', $query->excludeIds);
        }

        if ($query->entityIds !== []) {
            $builder->where(function (Builder $entities) use ($query): void {
                foreach ($query->entityIds as $entityType => $ids) {
                    foreach ($ids as $id) {
                        $entities->orWhereJsonContains("entities->{$entityType}", (string) $id);
                    }
                }
            });
        }

        return $builder;
    }

    /** @param array<int, float> $embedding @return Collection<int, MemorySearchResult> */
    private function searchPostgres(Builder $builder, array $embedding, int $candidateK, int $finalK): Collection
    {
        $vector = $this->vectorLiteral($embedding);

        return $builder
            ->select('memories.*')
            ->selectRaw('1 - (embedding <=> ?::vector) AS similarity', [$vector])
            ->orderByDesc('similarity')
            ->limit($candidateK)
            ->get()
            ->take($finalK)
            ->map(fn (Memory $memory): MemorySearchResult => new MemorySearchResult(
                $memory,
                max(-1.0, min(1.0, (float) $memory->getAttribute('similarity'))),
            ))
            ->values();
    }

    /** @param array<int, float> $embedding @return Collection<int, MemorySearchResult> */
    private function searchInMemory(Builder $builder, array $embedding, int $candidateK, int $finalK): Collection
    {
        return $builder->get()
            ->map(function (Memory $memory) use ($embedding): MemorySearchResult {
                $stored = $this->parseVector((string) $memory->getRawOriginal('embedding'));

                return new MemorySearchResult($memory, $this->cosineSimilarity($embedding, $stored));
            })
            ->sortByDesc(fn (MemorySearchResult $result): float => $result->similarity)
            ->take($candidateK)
            ->take($finalK)
            ->values();
    }

    /** @param array<int, float> $left @param array<int, float> $right */
    private function cosineSimilarity(array $left, array $right): float
    {
        if (count($left) !== count($right) || $left === []) {
            return -1.0;
        }

        $dot = $leftNorm = $rightNorm = 0.0;

        foreach ($left as $index => $value) {
            $dot += $value * $right[$index];
            $leftNorm += $value ** 2;
            $rightNorm += $right[$index] ** 2;
        }

        return $leftNorm > 0.0 && $rightNorm > 0.0
            ? $dot / (sqrt($leftNorm) * sqrt($rightNorm))
            : -1.0;
    }

    /** @return array<int, float> */
    private function parseVector(string $vector): array
    {
        $decoded = json_decode($vector, true);

        return is_array($decoded) ? array_map(static fn (mixed $value): float => (float) $value, $decoded) : [];
    }

    /** @param array<int, float> $embedding */
    private function vectorLiteral(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }
}
