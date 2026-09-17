<?php

namespace App\Services;

use App\Contracts\StoryEventApplier;
use App\Data\StatePatch;
use App\Data\StoryEventCandidate;
use App\Data\StoryStateRebuildResult;
use App\Enums\EventType;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StoryStateRebuilder
{
    /** @var array<int, string> */
    public const REQUIRED_DOMAINS = [
        'characters',
        'relationships',
        'locations',
        'items',
        'world',
        'timeline',
        'open_threads',
        'foreshadowings',
        'reader_promises',
    ];

    public function __construct(
        private readonly StoryEventApplier $eventApplier,
        private readonly StatePatchBuilder $statePatchBuilder,
        private readonly StoryStateService $storyState,
    ) {}

    public function rebuild(Novel $novel): StoryStateRebuildResult
    {
        $current = $this->storyState->current($novel);
        if ($current === null) {
            throw ValidationException::withMessages([
                'state' => '小说尚无当前 Canonical Story State，无法执行重建校验。请先初始化故事状态。',
            ]);
        }

        [$baseline, $skippedBaselines] = $this->latestCompleteBaseline($novel, $current->version);

        if ($baseline === null) {
            throw ValidationException::withMessages([
                'state' => '目标版本之前没有完整的无章节 Canonical Baseline。请先检查初始化/恢复版本的必要 Domain、schema_version 与 checksum；本次未生成差异，也未写入数据。',
            ]);
        }

        return $this->rebuildUsingBaseline($novel, $current, $baseline, $skippedBaselines);
    }

    public function rebuildFromVersion(Novel $novel, int $baselineVersion): StoryStateRebuildResult
    {
        $current = $this->storyState->current($novel);
        $initial = $this->storyState->findVersion($novel, $baselineVersion);

        if ($current === null || $initial === null) {
            throw ValidationException::withMessages([
                'state' => "小说必须同时存在 State Version {$baselineVersion} 与当前 Canonical Story State。",
            ]);
        }

        $reasons = $this->incompleteBaselineReasons($novel, $initial, $current->version);
        if ($reasons !== []) {
            throw ValidationException::withMessages([
                'state' => "State Version {$baselineVersion} 不是完整的无章节 Canonical Baseline：".implode('；', $reasons).'。',
            ]);
        }

        return $this->rebuildUsingBaseline($novel, $current, $initial, []);
    }

    /**
     * @param  array<int, array{version: int, reasons: array<int, string>}>  $skippedBaselines
     */
    private function rebuildUsingBaseline(
        Novel $novel,
        StoryStateVersion $current,
        StoryStateVersion $initial,
        array $skippedBaselines,
    ): StoryStateRebuildResult {
        $rangeEvents = $novel->storyEvents()
            ->where('state_version', '>', $initial->version)
            ->where('state_version', '<=', $current->version)
            ->orderBy('state_version')
            ->orderBy('id')
            ->get();
        $replayable = collect();
        $skippedEvents = [];

        foreach ($rangeEvents as $event) {
            if ($event->status->value !== 'active') {
                $skippedEvents[] = $this->skippedEvent($event, '事件已失效');

                continue;
            }

            if ($this->operationsFor($event) === []) {
                $skippedEvents[] = $this->skippedEvent($event, '事件类型不产生可重放状态操作');

                continue;
            }

            $replayable->push($event);
        }

        $rebuilt = $this->replay($initial->state, $replayable);
        $first = $replayable->first();
        $last = $replayable->last();

        return new StoryStateRebuildResult(
            currentVersion: $current->version,
            currentChecksum: $current->checksum,
            rebuiltChecksum: $this->storyState->checksum($rebuilt),
            baselineVersion: $initial->version,
            baselineChecksum: $initial->checksum,
            replayedEventCount: $replayable->count(),
            firstReplayedEventId: $first?->getKey(),
            lastReplayedEventId: $last?->getKey(),
            firstReplayedStateVersion: $first?->state_version,
            lastReplayedStateVersion: $last?->state_version,
            skippedEvents: $skippedEvents,
            skippedBaselines: $skippedBaselines,
            rebuiltState: $rebuilt,
            changes: $this->storyState->diff($current->state, $rebuilt),
        );
    }

    /**
     * @return array{?StoryStateVersion, array<int, array{version: int, reasons: array<int, string>}>}
     */
    private function latestCompleteBaseline(Novel $novel, int $targetVersion): array
    {
        $skipped = [];

        foreach ($novel->storyStateVersions()->whereNull('chapter_id')->where('version', '<=', $targetVersion)->orderByDesc('version')->get() as $candidate) {
            $reasons = $this->incompleteBaselineReasons($novel, $candidate, $targetVersion);
            if ($reasons === []) {
                return [$candidate, $skipped];
            }

            $skipped[] = ['version' => $candidate->version, 'reasons' => $reasons];
        }

        return [null, $skipped];
    }

    /** @return array<int, string> */
    private function incompleteBaselineReasons(Novel $novel, StoryStateVersion $candidate, int $targetVersion): array
    {
        $reasons = [];

        if ($candidate->novel_id !== $novel->getKey()) {
            $reasons[] = '不属于当前小说';
        }
        if ($candidate->chapter_id !== null) {
            $reasons[] = '关联了章节';
        }
        if ($candidate->version > $targetVersion) {
            $reasons[] = '晚于目标版本';
        }
        if (! is_int(data_get($candidate->state, 'schema_version')) || data_get($candidate->state, 'schema_version') < 1) {
            $reasons[] = '缺少有效 schema_version';
        }

        foreach (self::REQUIRED_DOMAINS as $domain) {
            if (! array_key_exists($domain, $candidate->state) || ! is_array($candidate->state[$domain])) {
                $reasons[] = "缺少有效 Domain {$domain}";
            }
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $candidate->checksum)
            || ! hash_equals($candidate->checksum, $this->storyState->checksum($candidate->state))) {
            $reasons[] = 'checksum 与快照内容不一致';
        }

        return $reasons;
    }

    /** @return array{id: int, state_version: int, reason: string} */
    private function skippedEvent(StoryEvent $event, string $reason): array
    {
        return [
            'id' => $event->getKey(),
            'state_version' => $event->state_version,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  Collection<int, StoryEvent>  $events
     * @return array<string, mixed>
     */
    public function replay(array $baseline, Collection $events): array
    {
        $rebuilt = $baseline;

        foreach ($events->sortBy([['state_version', 'asc'], ['id', 'asc']]) as $event) {
            $operations = $this->operationsFor($event);

            if ($operations !== []) {
                $rebuilt = $this->statePatchBuilder->applyPatch(
                    $rebuilt,
                    new StatePatch(max(0, $event->state_version - 1), $operations),
                );
            }
        }

        return $rebuilt;
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
