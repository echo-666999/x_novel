<?php

namespace App\Services;

use App\Enums\ForeshadowingImportance;
use App\Exceptions\GenerationPreflightException;
use App\Models\Chapter;

class ForeshadowingPlanningGate
{
    public function __construct(private readonly ForeshadowingLifecycleResolver $lifecycleResolver) {}

    public function assertModelPlanningAllowed(Chapter $chapter): void
    {
        $chapter->loadMissing('novel.canonicalStateVersion');
        $novel = $chapter->novel;
        $overdue = $novel->foreshadowings()
            ->where('importance', ForeshadowingImportance::Critical)
            ->where('due_to_chapter', '<', $chapter->sequence)
            ->orderBy('due_to_chapter')
            ->get(['id', 'novel_id', 'title', 'status', 'due_from_chapter', 'due_to_chapter'])
            ->reject(fn ($foreshadowing): bool => $this->lifecycleResolver->status($foreshadowing, $novel)->isTerminal());

        if ($overdue->isEmpty()) {
            return;
        }

        throw GenerationPreflightException::criticalForeshadowingOverdue(
            $overdue->map(fn ($foreshadowing): string => "#{$foreshadowing->getKey()} {$foreshadowing->title}")->all(),
        );
    }
}
