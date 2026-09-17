<?php

namespace App\Services;

use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\StoryArcStatus;
use App\Enums\StoryEventStatus;
use App\Models\Novel;
use App\Models\StoryArc;

class StoryArcProgressProjector
{
    public function __construct(private readonly StoryArcBeatContract $beatContract) {}

    public function refreshNovel(Novel $novel): void
    {
        $completed = $novel->storyEvents()
            ->where('event_type', EventType::StoryArcBeatCompleted->value)
            ->where('status', StoryEventStatus::Active->value)
            ->get()
            ->groupBy(fn ($event): int => (int) $event->subject_id)
            ->map(fn ($events) => $events->pluck('payload')->map(fn (array $payload) => $payload['beat_key'] ?? null)->filter()->unique());

        $conditionAudits = $novel->chapters()
            ->where('status', ChapterStatus::Canonical->value)
            ->whereNotNull('canonical_metadata')
            ->get(['canonical_metadata'])
            ->flatMap(fn ($chapter) => data_get($chapter->canonical_metadata, 'arc_completion_audits', []))
            ->filter(fn (mixed $audit): bool => is_array($audit) && ($audit['status'] ?? null) === 'fulfilled')
            ->pluck('arc_id')->map(fn ($id): int => (int) $id)->unique();

        $novel->storyArcs()->get()->each(function (StoryArc $arc) use ($completed, $conditionAudits): void {
            $beatKeys = collect($this->beatContract->forArc($arc))->pluck('beat_key')->unique();
            $completedCount = $beatKeys->intersect($completed->get($arc->getKey(), collect()))->count();
            $progress = $beatKeys->isEmpty() ? 0.0 : round($completedCount / $beatKeys->count(), 4);
            $conditionsSatisfied = empty($arc->completion_conditions) || $conditionAudits->contains($arc->getKey());
            $status = $progress >= 1.0 && $conditionsSatisfied
                ? StoryArcStatus::Completed
                : ($arc->status === StoryArcStatus::Planned && $progress === 0.0 ? StoryArcStatus::Planned : StoryArcStatus::Active);

            $arc->update(['progress' => min(1.0, max(0.0, $progress)), 'status' => $status]);
        });
    }

    /** @return array{canonical_completed: int, total: int, draft_pending: int} */
    public function summary(StoryArc $arc): array
    {
        $keys = collect($this->beatContract->forArc($arc))->pluck('beat_key')->unique();
        $canonical = $arc->novel->storyEvents()
            ->where('event_type', EventType::StoryArcBeatCompleted->value)
            ->where('status', StoryEventStatus::Active->value)
            ->where('subject_type', 'story_arc')
            ->where('subject_id', (string) $arc->getKey())
            ->get()->pluck('payload')->map(fn (array $payload) => $payload['beat_key'] ?? null)->filter()->unique();
        $draft = $arc->novel->chapters()
            ->where('status', '!=', ChapterStatus::Canonical->value)
            ->with('latestPlan')
            ->get()->flatMap(fn ($chapter) => $chapter->latestPlan?->arc_contributions ?? [])
            ->filter(fn (mixed $item): bool => is_array($item) && (int) ($item['arc_id'] ?? 0) === $arc->getKey())
            ->pluck('beat_key')->filter()->unique()->diff($canonical);

        return [
            'canonical_completed' => $keys->intersect($canonical)->count(),
            'total' => $keys->count(),
            'draft_pending' => $keys->intersect($draft)->count(),
        ];
    }
}
