<?php

namespace App\Console\Commands;

use App\Services\AutomaticGenerationRecoveryService;
use Illuminate\Console\Command;

class RecoverAutomaticGeneration extends Command
{
    protected $signature = 'generation:recover-automatic';

    protected $description = '根据 PostgreSQL 状态恢复丢失的生成 Worker 和提交后断点';

    public function handle(AutomaticGenerationRecoveryService $recovery): int
    {
        $result = $recovery->recover();
        $this->info(
            "已标记 {$result['marked']} 个停滞任务；恢复 Worker {$result['worker_lost']} 个；"
            ."派发提交后恢复 {$result['post_commit']} 个；内容自愈超限 {$result['content_repair_expired']} 个；"
            ."达到恢复上限 {$result['exhausted']} 个。",
        );

        return self::SUCCESS;
    }
}
