<?php

namespace App\Services;

use App\Data\MemoryQuery;
use App\Data\MemorySearchResult;
use Illuminate\Support\Collection;

class MemoryRetriever
{
    public function __construct(
        private readonly MemoryQueryBuilder $queryBuilder,
        private readonly MemoryRanker $ranker,
    ) {}

    /** @return Collection<int, MemorySearchResult> */
    public function retrieve(MemoryQuery $query): Collection
    {
        return $this->ranker->rank($this->queryBuilder->candidates($query), $query);
    }
}
