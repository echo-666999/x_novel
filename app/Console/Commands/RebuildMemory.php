<?php

namespace App\Console\Commands;

use App\Enums\ChapterStatus;
use App\Jobs\UpdateMemoryJob;
use App\Models\Novel;
use Illuminate\Console\Command;

class RebuildMemory extends Command
{
    protected $signature = 'memory:rebuild {novel : Novel ID} {--sync : 在当前进程同步执行}';

    protected $description = '为小说的 Canonical Chapters 重新执行幂等 Memory Update';

    public function handle(): int
    {
        $novel = Novel::query()->find($this->argument('novel'));
        if ($novel === null) {
            $this->error('找不到指定小说。');

            return self::FAILURE;
        }

        $chapterIds = $novel->chapters()->where('status', ChapterStatus::Canonical)->pluck('id');
        $chapterIds->each(function (int $chapterId): void {
            $this->option('sync')
                ? UpdateMemoryJob::dispatchSync($chapterId)
                : UpdateMemoryJob::dispatch($chapterId);
        });

        $mode = $this->option('sync') ? '同步执行' : '已排队';
        $this->info("{$mode} {$chapterIds->count()} 个 Canonical Chapter 的 Memory Update。");

        return self::SUCCESS;
    }
}
