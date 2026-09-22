<?php

namespace App\Services;

use App\Data\CurrentOutlineTarget;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Enums\StoryEventStatus;
use App\Enums\VolumeStatus;
use App\Models\Novel;

class OutlineProgressResolver
{
    public function resolve(Novel $novel): ?CurrentOutlineTarget
    {
        $novel->loadMissing('currentOutline');
        $outline = $novel->currentOutline;
        if ($outline === null) {
            return null;
        }

        $content = $outline->content;
        $volumes = collect($content['volumes'] ?? [])->filter(fn (mixed $item): bool => is_array($item));
        $activeVolumes = $novel->volumes()
            ->where('status', VolumeStatus::Active->value)
            ->whereNotNull('outline_key')
            ->get()->keyBy('outline_key');
        $volumeContent = $volumes->sortBy('sequence')->first(fn (array $item): bool => $activeVolumes->has($item['key'] ?? null));
        if ($volumeContent === null) {
            return null;
        }
        $volume = $activeVolumes->get($volumeContent['key']);

        $activeArcs = $novel->storyArcs()
            ->where('volume_id', $volume->getKey())
            ->where('type', StoryArcType::Main->value)
            ->where('status', StoryArcStatus::Active->value)
            ->whereNotNull('outline_key')
            ->get()->keyBy('outline_key');
        $arcContents = collect($volumeContent['arcs'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === StoryArcType::Main->value)
            ->sortBy('sequence');

        $canonicalCompleted = $novel->storyEvents()
            ->where('event_type', EventType::StoryArcBeatCompleted->value)
            ->where('status', StoryEventStatus::Active->value)
            ->where('subject_type', 'story_arc')
            ->get()
            ->map(fn ($event): ?string => is_array($event->payload) ? ($event->payload['beat_key'] ?? null) : null)
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()->sort()->values()->all();
        $baselineCompleted = collect($content['baseline_completions'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item) && is_string($item['beat_key'] ?? null))
            ->pluck('beat_key')->unique()->sort()->values()->all();
        $completed = array_fill_keys([...$canonicalCompleted, ...$baselineCompleted], true);

        foreach ($arcContents as $arcContent) {
            $arc = $activeArcs->get($arcContent['key'] ?? null);
            if ($arc === null) {
                continue;
            }
            $beat = collect($arcContent['beats'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->sortBy('sequence')
                ->first(fn (array $item): bool => ! isset($completed[$item['key'] ?? '']));
            if ($beat === null) {
                continue;
            }

            return new CurrentOutlineTarget(
                outlineId: $outline->getKey(),
                outlineVersion: $outline->version,
                outlineChecksum: $outline->checksum,
                volumeId: $volume->getKey(),
                arcId: $arc->getKey(),
                volume: $this->withoutChildren($volumeContent, 'arcs'),
                arc: $this->withoutChildren($arcContent, 'beats'),
                beat: $beat,
                canonicalCompletedBeatKeys: $canonicalCompleted,
                baselineCompletedBeatKeys: $baselineCompleted,
                chaptersUsedForCurrentBeat: $this->chaptersUsed($novel, $outline->getKey(), $arc->getKey(), (string) $beat['key']),
            );
        }

        return null;
    }

    /** @param array<string, mixed> $node @return array<string, mixed> */
    private function withoutChildren(array $node, string $children): array
    {
        unset($node[$children]);

        return $node;
    }

    private function chaptersUsed(Novel $novel, int $outlineId, int $arcId, string $beatKey): int
    {
        return $novel->chapters()
            ->where('status', ChapterStatus::Canonical->value)
            ->with('latestPlan')
            ->get()
            ->filter(function ($chapter) use ($outlineId, $arcId, $beatKey): bool {
                $plan = $chapter->latestPlan;
                if ($plan === null || $plan->novel_outline_id !== $outlineId) {
                    return false;
                }

                return collect($plan->arc_contributions ?? [])->contains(
                    fn (mixed $item): bool => is_array($item)
                        && ($item['role'] ?? null) === 'primary'
                        && (int) ($item['arc_id'] ?? 0) === $arcId
                        && ($item['beat_key'] ?? null) === $beatKey,
                );
            })->count();
    }
}
