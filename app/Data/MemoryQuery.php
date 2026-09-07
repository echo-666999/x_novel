<?php

namespace App\Data;

use App\Enums\MemoryType;
use InvalidArgumentException;

final readonly class MemoryQuery
{
    /**
     * @param  array<int, MemoryType|string>  $types
     * @param  array<string, array<int, int|string>>  $entityIds
     * @param  array<int, int>  $excludeIds
     */
    public function __construct(
        public int $novelId,
        public string $queryText,
        public array $types = [],
        public array $entityIds = [],
        public ?int $chapterFrom = null,
        public ?int $chapterTo = null,
        public array $excludeIds = [],
        public ?int $candidateK = null,
        public ?int $finalK = null,
    ) {
        if ($novelId < 1 || blank($queryText)) {
            throw new InvalidArgumentException('记忆检索必须指定小说和查询文本。');
        }

        if ($chapterFrom !== null && $chapterTo !== null && $chapterFrom > $chapterTo) {
            throw new InvalidArgumentException('记忆检索的起始章节不能晚于结束章节。');
        }
    }
}
