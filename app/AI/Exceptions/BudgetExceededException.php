<?php

namespace App\AI\Exceptions;

class BudgetExceededException extends AiProviderException
{
    public function __construct(
        public readonly string $scope,
        public readonly float $used,
        public readonly float $limit,
    ) {
        parent::__construct(
            errorCode: "budget_{$scope}_hard_limit",
            message: "AI {$scope} hard limit 已达到。",
            retryable: false,
        );
    }
}
