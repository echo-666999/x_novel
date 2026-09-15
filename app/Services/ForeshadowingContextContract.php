<?php

namespace App\Services;

use App\Enums\ForeshadowingStatus;
use App\Enums\ForeshadowingTimingStatus;
use App\Models\ChapterPlan;
use App\Models\NovelBible;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use Illuminate\Support\Collection;

class ForeshadowingContextContract
{
    /** @return array<string, mixed> */
    public function build(
        ChapterPlan $plan,
        NovelBible $bible,
        StoryStateVersion $state,
    ): array {
        $plan->loadMissing(['chapter', 'chapter.novel', 'chapter.novel.foreshadowings.ownerArc']);
        $chapter = $plan->chapter;
        $novel = $chapter->novel;
        $actions = $plan->foreshadowingActionContracts();
        $foreshadowings = $novel->foreshadowings->keyBy('id');
        $ids = collect($actions)
            ->pluck('foreshadowing_id')
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $events = $this->latestEvents($novel->getKey(), $ids, $state->version);

        $payload = [
            'schema_version' => 'foreshadowing-contract-v1',
            'bible_version' => $bible->version,
            'state_version' => $state->version,
            'chapter_plan_id' => $plan->getKey(),
            'chapter_plan_version' => $plan->version,
            'legacy_reference_ids' => $plan->legacyForeshadowingIds(),
            'actions' => collect($actions)->map(function (array $action) use ($foreshadowings, $events, $state, $chapter): array {
                $id = (int) ($action['foreshadowing_id'] ?? 0);
                $foreshadowing = $foreshadowings->get($id);

                if ($foreshadowing === null) {
                    return [
                        'foreshadowing_id' => $id,
                        'invalid_reference' => true,
                        'plan_action' => $action,
                    ];
                }

                $canonicalStatus = data_get($state->state, "foreshadowings.{$id}.status");
                $status = is_string($canonicalStatus)
                    ? ForeshadowingStatus::tryFrom($canonicalStatus)
                    : null;
                $statusSource = $status === null ? 'domain_projection' : 'canonical_state';
                $status ??= $foreshadowing->status;
                $timing = ForeshadowingTimingStatus::forTargetChapter(
                    $status,
                    $foreshadowing->due_from_chapter,
                    $foreshadowing->due_to_chapter,
                    $chapter->sequence,
                );

                return [
                    'foreshadowing_id' => $id,
                    'title' => $foreshadowing->title,
                    'description' => $foreshadowing->description,
                    'promised_payoff' => $foreshadowing->promised_payoff,
                    'content_status' => $status->value,
                    'content_status_source' => $statusSource,
                    'timing_status' => $timing?->value,
                    'due_window' => [
                        'from_chapter' => $foreshadowing->due_from_chapter,
                        'to_chapter' => $foreshadowing->due_to_chapter,
                    ],
                    'importance' => $foreshadowing->importance->value,
                    'owner_arc' => $foreshadowing->ownerArc === null ? null : [
                        'id' => $foreshadowing->ownerArc->getKey(),
                        'title' => $foreshadowing->ownerArc->title,
                    ],
                    'plan_action' => $action,
                    'latest_effective_event' => $this->eventPayload($events->get($id)),
                ];
            })->values()->all(),
        ];

        return [
            ...$payload,
            'checksum' => hash('sha256', json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )),
        ];
    }

    /** @param array<int, int> $ids
     * @return Collection<int, StoryEvent>
     */
    private function latestEvents(int $novelId, array $ids, int $stateVersion): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return StoryEvent::query()
            ->active()
            ->where('novel_id', $novelId)
            ->where('subject_type', 'foreshadowing')
            ->whereIn('subject_id', array_map('strval', $ids))
            ->where('state_version', '<=', $stateVersion)
            ->orderByDesc('state_version')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (StoryEvent $event): int => (int) $event->subject_id)
            ->keyBy(fn (StoryEvent $event): int => (int) $event->subject_id);
    }

    /** @return array<string, mixed>|null */
    private function eventPayload(?StoryEvent $event): ?array
    {
        if ($event === null) {
            return null;
        }

        return [
            'story_event_id' => $event->getKey(),
            'event_type' => $event->event_type->value,
            'chapter_id' => $event->chapter_id,
            'scene_id' => $event->scene_id,
            'state_version' => $event->state_version,
            'evidence' => $event->evidence,
        ];
    }
}
