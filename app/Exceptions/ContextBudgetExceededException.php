<?php

namespace App\Exceptions;

use RuntimeException;

class ContextBudgetExceededException extends RuntimeException
{
    public function __construct(public readonly int $required, public readonly int $budget)
    {
        parent::__construct("Token Budget 不足：L0/L1 至少需要 {$required} tokens，当前预算为 {$budget}。");
    }
}
