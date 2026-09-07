<?php

namespace App\Jobs;

use App\Services\MemoryUpdater;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateMemoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $chapterId)
    {
        $this->onQueue('default');
    }

    public function handle(MemoryUpdater $memoryUpdater): void
    {
        $memoryUpdater->update($this->chapterId);
    }
}
