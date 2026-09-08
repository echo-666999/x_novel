<?php

namespace App\Services;

use App\Contracts\StoryEventApplier;
use App\Data\StatePatch;
use App\Data\StoryEventCandidate;
use App\Data\StoryStateRebuildResult;
use App\Enums\EventType;
use App\Models\Novel;
use App\Models\StoryEvent;
use Illuminate\Validation\ValidationException;

class StoryStateRebuilder
{
    public function __construct(
        private readonly StoryEventApplier $eventApplier,
        private readonly StatePatchBuilder $statePatchBuilder,
        private readonly StoryStateService $storyState,
    ) {}

    public function rebuild(Novel $novel): StoryStateRebuildResult
    {
        $current = $this->storyState->current($novel);
        $initial = $this->storyState->findVersion($novel, 0);

        if ($current === null || $initial === null) {
            throw ValidationException::withMessages([
                'state' => '小说必须同时存在 State Version 0 与当前 Canonical Story State。',
            ]);
        }

        $events = $novel->storyEvents()
            ->active()
            ->where('state_version', '<=', $current->version)
            ->orderBy('state_version')
            ->orderBy('id')
            ->get();
        $rebuilt = $initial->state;

        foreach ($events as $event) {
            $operations = $this->operationsFor($event);

            if ($operations !== []) {
                $rebuilt = $this->statePatchBuilder->applyPatch(
                    $rebuilt,
                    new StatePatch(max(0, $event->state_version - 1), $operations),
                );
            }
        }

        return new StoryStateRebuildResult(
            currentVersion: $current->version,
            currentChecksum: $current->checksum,
            rebuiltChecksum: $this->storyState->checksum($rebuilt),
            replayedEventCount: $events->count(),
            rebuiltState: $rebuilt,
            changes: $this->storyState->diff($current->state, $rebuilt),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function operationsFor(StoryEvent $event): array
    {
        if ($event->event_type === EventType::ManualCorrection) {
            $path = $event->payload['path'] ?? null;

            return is_string($path) && $path !== ''
                ? [[
                    'op' => 'set',
                    'path' => $path,
                    'value' => $event->payload['after'] ?? null,
                    'source_event_index' => 0,
                ]]
                : [];
        }

        $candidate = new StoryEventCandidate(
            eventType: $event->event_type,
            subjectType: $event->subject_type,
            subjectId: $event->subject_id,
            payload: $event->payload,
            evidence: $event->evidence,
            storyTime: $event->story_time,
            confidence: 1,
        );

        if (! $this->eventApplier->supports($event->event_type->value)) {
            return [];
        }

        return array_map(
            fn (array $operation): array => [...$operation, 'source_event_index' => 0],
            $this->eventApplier->operations($candidate),
        );
    }
}
