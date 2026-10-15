<?php

namespace App\Data;

/** 小说结构推进结果，供自动续章判断是否还需要创建下一章。 */
final readonly class NovelLifecycleResult
{
    public function __construct(
        public string $state,
        public bool $canContinue,
        public bool $completed = false,
    ) {}
}
