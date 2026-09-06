<?php

namespace App\Data;

use App\Enums\PlanFindingSeverity;

readonly class PlanFinding
{
    public function __construct(
        public PlanFindingSeverity $severity,
        public string $code,
        public string $message,
    ) {}
}
