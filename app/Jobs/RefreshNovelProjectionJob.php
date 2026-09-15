<?php

namespace App\Jobs;

use App\Models\Novel;
use App\Services\ProjectionRebuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshNovelProjectionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $novelId,
        public readonly int $stateVersionId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "novel:{$this->novelId}:state:{$this->stateVersionId}";
    }

    public function handle(ProjectionRebuilder $rebuilder): void
    {
        $novel = Novel::query()->findOrFail($this->novelId);

        if ((int) $novel->canonical_state_version_id !== $this->stateVersionId) {
            return;
        }

        $rebuilder->rebuild($novel);
    }
}
