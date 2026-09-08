<?php

namespace App\Jobs;

use App\Services\EndingAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EndingAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $novelId)
    {
        $this->onQueue('default');
    }

    public function handle(EndingAuditService $endingAudit): void
    {
        $endingAudit->audit($this->novelId);
    }
}
