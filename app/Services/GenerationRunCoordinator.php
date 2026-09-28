<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Models\GenerationRun;
use Illuminate\Database\Eloquent\Builder;

/** Shared reuse and lease recovery decision for a locked Stage scope. */
final class GenerationRunCoordinator
{
    public function __construct(private readonly GenerationRunLease $lease) {}

    /**
     * @param  Builder<GenerationRun>  $runs
     * @return array{run: GenerationRun|null, reused: bool, attempt: int}
     */
    public function resolve(Builder $runs, string $inputHash, string $interruptedMessage): array
    {
        $active = (clone $runs)->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();
        if ($this->lease->isFresh($active)) {
            return ['run' => $active, 'reused' => true, 'attempt' => (int) $active->attempt];
        }

        if ($active !== null) {
            $active->update([
                'status' => RunStatus::Failed,
                'error_code' => 'worker_interrupted',
                'error_message' => $interruptedMessage,
                'error_retryable' => false,
                'error_metadata' => [
                    'category' => 'worker_lost',
                    'stage' => $active->stage->value,
                    'input_hash' => $active->input_hash,
                    'next_action' => '继续执行',
                ],
                'finished_at' => now(),
            ]);
        }

        $succeeded = (clone $runs)->where('input_hash', $inputHash)
            ->whereHas('artifacts')
            ->where('status', RunStatus::Succeeded)->latest('id')->first();
        if ($succeeded !== null) {
            return ['run' => $succeeded, 'reused' => true, 'attempt' => (int) $succeeded->attempt];
        }

        return [
            'run' => null,
            'reused' => false,
            'attempt' => ((int) (clone $runs)->max('attempt')) + 1,
        ];
    }
}
