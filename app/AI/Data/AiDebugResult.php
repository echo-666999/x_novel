<?php

namespace App\AI\Data;

final readonly class AiDebugResult
{
    public function __construct(
        public AiResponse $response,
        // 缺价格必须显示未知，不能用零费用冒充。
        public ?float $estimatedCost,
    ) {}
}
