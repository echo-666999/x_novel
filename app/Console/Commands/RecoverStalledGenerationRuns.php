<?php

namespace App\Console\Commands;

use App\Services\StalledRunRecoveryService;
use Illuminate\Console\Command;

class RecoverStalledGenerationRuns extends Command
{
    protected $signature = 'generation:mark-stalled';

    protected $description = 'Mark generation runs left running by lost workers as recoverable failures';

    public function handle(StalledRunRecoveryService $recovery): int
    {
        $count = $recovery->markStalledRuns();
        $this->info("已标记 {$count} 个 Worker 丢失的 Generation Run。");

        return self::SUCCESS;
    }
}
