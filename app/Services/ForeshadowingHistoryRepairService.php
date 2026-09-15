<?php

namespace App\Services;

use App\Enums\EventType;
use App\Enums\ForeshadowingStatus;
use App\Enums\MemoryStatus;
use App\Enums\StoryEventStatus;
use App\Models\Fact;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ForeshadowingHistoryRepairService
{
    private const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly StoryStateRebuilder $stateRebuilder,
        private readonly StoryStateService $storyState,
        private readonly ProjectionRebuilder $projectionRebuilder,
    ) {}

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    public function preview(array $plan): array
    {
        $context = $this->validatedContext($plan);
        $events = $this->candidateEvents($context['novel'], $context['baseline'], $context['current'], $plan);
        $rebuilt = $this->stateRebuilder->replay($context['baseline']->state, $events);
        $projection = $this->projectionRebuilder->inspect($context['novel']);
        $affectedForeshadowingIds = collect($plan['event_repairs'])
            ->map(fn (array $repair): int => (int) StoryEvent::query()->findOrFail($repair['event_id'])->subject_id)
            ->merge(collect($plan['state_corrections'])->map(
                fn (array $correction): int => (int) explode('.', $correction['path'])[1],
            ))->unique()->sort()->values();
        $affectedEventIds = collect($plan['event_repairs'])->pluck('event_id')->map(fn ($id): int => (int) $id);

        return [
            'repair_key' => $this->repairKey($plan),
            'novel_id' => $context['novel']->getKey(),
            'novel_title' => $context['novel']->title,
            'baseline_state_version' => $context['baseline']->version,
            'expected_state_version' => $context['current']->version,
            'result_state_version' => $context['current']->version + 1,
            'source_rebuild_matches_current' => $context['source_rebuild_matches_current'],
            'event_repairs' => collect($plan['event_repairs'])->map(fn (array $repair): array => [
                'event_id' => (int) $repair['event_id'],
                'action' => $repair['action'],
                'expected_event_type' => $repair['expected_event_type'],
                'replacement_event_type' => $repair['replacement_event_type'] ?? null,
                'reason' => $repair['reason'],
                'memory_records_affected' => Memory::query()
                    ->where('novel_id', $context['novel']->getKey())
                    ->where('source_type', 'story_event')
                    ->where('source_id', $repair['event_id'])
                    ->count(),
            ])->all(),
            'state_corrections' => $plan['state_corrections'],
            'unresolved_items' => $plan['unresolved_items'] ?? [],
            'before_foreshadowings' => data_get($context['current']->state, 'foreshadowings', []),
            'after_foreshadowings' => data_get($rebuilt, 'foreshadowings', []),
            'state_changes' => $this->storyState->diff($context['current']->state, $rebuilt),
            'result_checksum' => $this->storyState->checksum($rebuilt),
            'facts_affected' => Fact::query()->where('novel_id', $context['novel']->getKey())
                ->whereIn('source_event_id', $affectedEventIds)->count(),
            'projection_before' => [
                'character_drift_ids' => $projection->characterDriftIds,
                'world_entity_drift_ids' => $projection->worldEntityDriftIds,
                'foreshadowing_drift_ids' => $projection->foreshadowingDriftIds,
                'errors' => $projection->errors,
            ],
            'foreshadowing_projection_ids_to_rebuild' => $affectedForeshadowingIds->all(),
            'projection_changes' => [
                'characters' => collect($projection->characterDriftIds)->map(function (int $id) use ($context, $projection): array {
                    $character = $context['novel']->characters->firstWhere('id', $id);

                    return ['id' => $id, 'name' => $character?->name, 'before' => $character?->current_state, 'after' => $projection->characterStates[$id]];
                })->all(),
                'world_entities' => collect($projection->worldEntityDriftIds)->map(function (int $id) use ($context, $projection): array {
                    $entity = $context['novel']->worldEntities->firstWhere('id', $id);

                    return ['id' => $id, 'name' => $entity?->name, 'before' => $entity?->current_state, 'after' => $projection->worldEntityStates[$id]];
                })->all(),
                'foreshadowings' => $affectedForeshadowingIds->map(function (int $id) use ($context, $rebuilt, $events): array {
                    $foreshadowing = $context['novel']->foreshadowings->firstWhere('id', $id);

                    return [
                        'id' => $id,
                        'title' => $foreshadowing?->title,
                        'before' => $foreshadowing === null ? null : [
                            'status' => $foreshadowing->status->value,
                            'reinforce_count' => $foreshadowing->reinforce_count,
                            'setup_chapter_id' => $foreshadowing->setup_chapter_id,
                            'payoff_chapter_id' => $foreshadowing->payoff_chapter_id,
                        ],
                        'after' => $this->plannedForeshadowingProjection($id, $rebuilt, $events),
                    ];
                })->all(),
            ],
        ];
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    public function execute(array $plan, ?int $actorId = null): array
    {
        if ($actorId === null || ! User::query()->whereKey($actorId)->exists()) {
            throw ValidationException::withMessages(['actor' => '显式执行历史修复必须提供有效操作者。']);
        }

        $repairKey = $this->repairKey($plan);

        return DB::transaction(function () use ($plan, $actorId, $repairKey): array {
            $novel = Novel::query()->lockForUpdate()->findOrFail((int) $plan['novel_id']);
            $existing = $this->appliedEvent($novel, $repairKey);

            if ($existing !== null) {
                return [
                    'status' => 'already_applied',
                    'repair_key' => $repairKey,
                    'result_state_version' => (int) data_get($existing->payload, 'result_state_version'),
                ];
            }

            $preview = $this->preview($plan);
            $current = $novel->fresh()->canonicalStateVersion()->firstOrFail();
            $nextVersion = $current->version + 1;
            foreach ($plan['event_repairs'] as $repair) {
                $source = StoryEvent::query()->lockForUpdate()->findOrFail((int) $repair['event_id']);
                $source->update(['status' => StoryEventStatus::Invalidated, 'invalidated_at' => now()]);
                $replacement = null;

                if ($repair['action'] === 'replace') {
                    $replacement = $novel->storyEvents()->create([
                        'chapter_id' => $source->chapter_id,
                        'scene_id' => $source->scene_id,
                        'event_type' => EventType::from($repair['replacement_event_type']),
                        'subject_type' => $source->subject_type,
                        'subject_id' => $source->subject_id,
                        'payload' => [...$source->payload, 'historical_replacement_for' => $source->getKey()],
                        'evidence' => $source->evidence,
                        'story_time' => $source->story_time,
                        'state_version' => $source->state_version,
                        'status' => StoryEventStatus::Active,
                    ]);
                    $this->replaceMemorySource($source, $replacement);
                } else {
                    $this->invalidateMemorySource($source);
                }

                $auditType = $replacement === null ? EventType::EventInvalidated : EventType::EventCorrected;
                $novel->storyEvents()->create([
                    'chapter_id' => $current->chapter_id,
                    'scene_id' => null,
                    'event_type' => $auditType,
                    'subject_type' => 'story_event',
                    'subject_id' => (string) $source->getKey(),
                    'payload' => [
                        'repair_key' => $repairKey,
                        'source_event_id' => $source->getKey(),
                        'replacement_event_id' => $replacement?->getKey(),
                        'before_event_type' => $source->event_type->value,
                        'after_event_type' => $replacement?->event_type->value,
                        'reason' => $repair['reason'],
                        'actor_id' => $actorId,
                        'performed_at' => now()->toISOString(),
                        'result_state_version' => $nextVersion,
                    ],
                    'evidence' => [['type' => 'historical_repair', 'quote' => $repair['reason']]],
                    'story_time' => null,
                    'state_version' => $nextVersion,
                    'status' => StoryEventStatus::Active,
                ]);
            }

            foreach ($plan['state_corrections'] as $correction) {
                $novel->storyEvents()->create([
                    'chapter_id' => $current->chapter_id,
                    'scene_id' => null,
                    'event_type' => EventType::ManualCorrection,
                    'subject_type' => explode('.', $correction['path'])[0] ?? null,
                    'subject_id' => explode('.', $correction['path'])[1] ?? null,
                    'payload' => [
                        'repair_key' => $repairKey,
                        'path' => $correction['path'],
                        'before' => data_get($current->state, $correction['path']),
                        'after' => $correction['value'],
                        'reason' => $correction['reason'],
                        'previous_state_version' => $current->version,
                        'actor_id' => $actorId,
                        'performed_at' => now()->toISOString(),
                        'result_state_version' => $nextVersion,
                    ],
                    'evidence' => [['type' => 'historical_repair', 'quote' => $correction['reason']]],
                    'story_time' => null,
                    'state_version' => $nextVersion,
                    'status' => StoryEventStatus::Active,
                ]);
            }

            $baseline = $novel->storyStateVersions()->where('version', $plan['baseline_state_version'])->firstOrFail();
            $events = $novel->storyEvents()->active()
                ->where('state_version', '>', $baseline->version)
                ->where('state_version', '<=', $nextVersion)
                ->orderBy('state_version')->orderBy('id')->get();
            $state = $this->stateRebuilder->replay($baseline->state, $events);
            $checksum = $this->storyState->checksum($state);

            if (! hash_equals($preview['result_checksum'], $checksum)) {
                throw ValidationException::withMessages(['plan' => '执行结果与 dry-run 冻结结果不一致。']);
            }

            $version = $novel->storyStateVersions()->create([
                'version' => $nextVersion,
                'chapter_id' => $current->chapter_id,
                'state' => $state,
                'checksum' => $checksum,
            ]);
            $novel->update(['canonical_state_version_id' => $version->getKey()]);
            $health = $this->projectionRebuilder->rebuild($novel->fresh());

            if (! $health->isHealthy()) {
                throw ValidationException::withMessages(['projection' => '历史修复后领域投影仍不一致。']);
            }

            return [
                'status' => 'applied',
                'repair_key' => $repairKey,
                'result_state_version' => $nextVersion,
                'result_checksum' => $checksum,
                'invalidated_event_ids' => collect($plan['event_repairs'])->pluck('event_id')->map(fn ($id): int => (int) $id)->all(),
                'projection_healthy' => true,
            ];
        }, 3);
    }

    /** @param array<string, mixed> $plan @return array<string, mixed>|null */
    public function appliedResult(array $plan): ?array
    {
        if (! isset($plan['novel_id']) || ! is_numeric($plan['novel_id'])) {
            return null;
        }

        $repairKey = $this->repairKey($plan);
        $novel = Novel::query()->find((int) $plan['novel_id']);
        $existing = $novel === null ? null : $this->appliedEvent($novel, $repairKey);

        return $existing === null ? null : [
            'status' => 'already_applied',
            'repair_key' => $repairKey,
            'result_state_version' => (int) data_get($existing->payload, 'result_state_version'),
        ];
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    private function validatedContext(array $plan): array
    {
        $eventTypes = collect(EventType::cases())->map->value->all();
        Validator::make($plan, [
            'schema_version' => ['required', 'integer', 'in:'.self::SCHEMA_VERSION],
            'novel_id' => ['required', 'integer', 'min:1'],
            'novel_title' => ['required', 'string'],
            'expected_state_version' => ['required', 'integer', 'min:1'],
            'expected_state_checksum' => ['required', 'string', 'size:64'],
            'baseline_state_version' => ['required', 'integer', 'min:0'],
            'event_repairs' => ['required', 'array'],
            'event_repairs.*.event_id' => ['required', 'integer', 'distinct'],
            'event_repairs.*.action' => ['required', Rule::in(['replace', 'invalidate'])],
            'event_repairs.*.expected_event_type' => ['required', Rule::in($eventTypes)],
            'event_repairs.*.replacement_event_type' => ['nullable', Rule::in([
                EventType::ForeshadowingPlanted->value,
                EventType::ForeshadowingReinforced->value,
                EventType::ForeshadowingPaidOff->value,
                EventType::ForeshadowingAbandoned->value,
            ])],
            'event_repairs.*.reason' => ['required', 'string', 'max:2000'],
            'state_corrections' => ['present', 'array'],
            'state_corrections.*.path' => ['required', 'string', 'regex:/^foreshadowings\\.\\d+\\.(status|reinforce_count)$/'],
            'state_corrections.*.value' => ['present'],
            'state_corrections.*.reason' => ['required', 'string', 'max:2000'],
            'unresolved_items' => ['present', 'array'],
        ])->validate();

        foreach ($plan['event_repairs'] as $repair) {
            if (($repair['action'] === 'replace') !== filled($repair['replacement_event_type'] ?? null)) {
                throw ValidationException::withMessages(['plan' => 'replace 必须提供 replacement_event_type；invalidate 不得提供。']);
            }
        }

        $novel = Novel::query()->with('canonicalStateVersion')->findOrFail((int) $plan['novel_id']);
        $current = $novel->canonicalStateVersion;
        $baseline = $novel->storyStateVersions()->where('version', $plan['baseline_state_version'])->first();

        if ($novel->title !== $plan['novel_title'] || $current === null || $baseline === null
            || $current->version !== (int) $plan['expected_state_version']
            || ! hash_equals($current->checksum, $plan['expected_state_checksum'])
            || $baseline->chapter_id !== null || $baseline->version >= $current->version) {
            throw ValidationException::withMessages(['plan' => '小说、Canonical 版本/checksum 或完整无章节基线与冻结计划不一致。']);
        }

        $sourceRebuild = $this->stateRebuilder->rebuildFromVersion($novel, $baseline->version);
        if (! $sourceRebuild->matches()) {
            throw ValidationException::withMessages(['plan' => '指定基线不能完整重建当前 Canonical State，禁止修复。']);
        }

        foreach ($plan['event_repairs'] as $repair) {
            $event = $novel->storyEvents()->with('chapter.canonicalArtifact')->find((int) $repair['event_id']);
            if ($event === null || $event->status !== StoryEventStatus::Active
                || $event->event_type->value !== $repair['expected_event_type']
                || ! $event->event_type->isForeshadowing()
                || $event->subject_type !== 'foreshadowing'
                || ! $novel->foreshadowings()->whereKey((int) $event->subject_id)->exists()) {
                throw ValidationException::withMessages(['plan' => "事件 #{$repair['event_id']} 与冻结计划不一致。"]);
            }
            $this->validateCanonicalEvidence($event);
        }

        $eventIds = collect($plan['event_repairs'])->pluck('event_id')->map(fn ($id): int => (int) $id);
        if (Fact::query()->where('novel_id', $novel->getKey())->whereIn('source_event_id', $eventIds)->exists()) {
            throw ValidationException::withMessages(['plan' => '待修复 Event 仍被 Fact 引用；当前计划没有定义 Fact 修复，禁止执行。']);
        }

        $paths = [];
        foreach ($plan['state_corrections'] as $correction) {
            if (in_array($correction['path'], $paths, true) || data_get($current->state, $correction['path']) === null) {
                throw ValidationException::withMessages(['plan' => "State 修正路径 {$correction['path']} 重复或不存在。"]);
            }
            $paths[] = $correction['path'];

            if (str_ends_with($correction['path'], '.status')) {
                $status = is_string($correction['value']) ? ForeshadowingStatus::tryFrom($correction['value']) : null;
                if ($status === null || $status->isLegacyDue()) {
                    throw ValidationException::withMessages(['plan' => "State 修正路径 {$correction['path']} 的状态值无效。"]);
                }
            } elseif (! is_int($correction['value']) || $correction['value'] < 0) {
                throw ValidationException::withMessages(['plan' => "State 修正路径 {$correction['path']} 的计数值无效。"]);
            }
        }

        return compact('novel', 'current', 'baseline') + ['source_rebuild_matches_current' => true];
    }

    /** @param array<string, mixed> $plan @return Collection<int, StoryEvent> */
    private function candidateEvents(Novel $novel, StoryStateVersion $baseline, StoryStateVersion $current, array $plan): Collection
    {
        $events = $novel->storyEvents()->active()
            ->where('state_version', '>', $baseline->version)
            ->where('state_version', '<=', $current->version)
            ->orderBy('state_version')->orderBy('id')->get();
        $nextId = (int) $events->max('id') + 1;

        foreach ($plan['event_repairs'] as $repair) {
            $source = $events->firstWhere('id', (int) $repair['event_id']);
            $events = $events->reject(fn (StoryEvent $event): bool => $event->getKey() === (int) $repair['event_id']);
            if ($repair['action'] === 'replace' && $source !== null) {
                $replacement = new StoryEvent;
                $replacement->forceFill([
                    'id' => $nextId++,
                    'novel_id' => $source->novel_id,
                    'chapter_id' => $source->chapter_id,
                    'scene_id' => $source->scene_id,
                    'event_type' => $repair['replacement_event_type'],
                    'subject_type' => $source->subject_type,
                    'subject_id' => $source->subject_id,
                    'payload' => $source->payload,
                    'evidence' => $source->evidence,
                    'story_time' => $source->story_time,
                    'state_version' => $source->state_version,
                    'status' => StoryEventStatus::Active,
                ]);
                $events->push($replacement);
            }
        }

        foreach ($plan['state_corrections'] as $correction) {
            $event = new StoryEvent;
            $event->forceFill([
                'id' => $nextId++,
                'event_type' => EventType::ManualCorrection->value,
                'payload' => ['path' => $correction['path'], 'after' => $correction['value']],
                'evidence' => [['quote' => $correction['reason']]],
                'state_version' => $current->version + 1,
                'status' => StoryEventStatus::Active->value,
            ]);
            $events->push($event);
        }

        return $events;
    }

    private function validateCanonicalEvidence(StoryEvent $event): void
    {
        $artifact = $event->chapter?->canonicalArtifact;
        if ($artifact === null) {
            throw ValidationException::withMessages(['evidence' => "事件 #{$event->getKey()} 没有 Canonical Artifact。"]);
        }

        foreach ($event->evidence as $evidence) {
            $quote = is_array($evidence) ? ($evidence['quote'] ?? null) : null;
            if (! is_string($quote) || $quote === '' || ! str_contains($artifact->content, $quote)
                || (isset($evidence['artifact_id']) && (int) $evidence['artifact_id'] !== $artifact->getKey())) {
                throw ValidationException::withMessages(['evidence' => "事件 #{$event->getKey()} 的证据不是当前 Canonical 正文逐字引用。"]);
            }
        }
    }

    /** @param array<string, mixed> $state @param Collection<int, StoryEvent> $events @return array<string, mixed> */
    private function plannedForeshadowingProjection(int $id, array $state, Collection $events): array
    {
        $setupChapterId = null;
        $payoffChapterId = null;

        foreach ($events->sortBy([['state_version', 'asc'], ['id', 'asc']]) as $event) {
            if ($event->subject_type === 'foreshadowing' && (int) $event->subject_id === $id) {
                if ($event->event_type === EventType::ForeshadowingPlanted) {
                    $setupChapterId ??= $event->chapter_id;
                } elseif ($event->event_type === EventType::ForeshadowingPaidOff) {
                    $payoffChapterId = $event->chapter_id;
                } elseif ($event->event_type === EventType::ForeshadowingAbandoned) {
                    $payoffChapterId = null;
                }
            }

            if ($event->event_type === EventType::ManualCorrection
                && data_get($event->payload, 'path') === "foreshadowings.{$id}.status"
                && data_get($event->payload, 'after') === ForeshadowingStatus::Abandoned->value) {
                $payoffChapterId = null;
            }
        }

        return [
            'status' => data_get($state, "foreshadowings.{$id}.status"),
            'reinforce_count' => (int) data_get($state, "foreshadowings.{$id}.reinforce_count", 0),
            'setup_chapter_id' => $setupChapterId,
            'payoff_chapter_id' => $payoffChapterId,
        ];
    }

    private function invalidateMemorySource(StoryEvent $source): void
    {
        Memory::query()->where('novel_id', $source->novel_id)
            ->where('source_type', 'story_event')->where('source_id', $source->getKey())
            ->where('status', MemoryStatus::Active)->update(['status' => MemoryStatus::Invalid]);
    }

    private function replaceMemorySource(StoryEvent $source, StoryEvent $replacement): void
    {
        $memories = Memory::query()->where('novel_id', $source->novel_id)
            ->where('source_type', 'story_event')->where('source_id', $source->getKey())
            ->where('status', MemoryStatus::Active)->get();
        $this->invalidateMemorySource($source);

        foreach ($memories as $memory) {
            Memory::query()->create([
                'novel_id' => $memory->novel_id,
                'type' => $memory->type,
                'source_type' => 'story_event',
                'source_id' => $replacement->getKey(),
                'summary' => $memory->summary,
                'entities' => $memory->entities,
                'salience' => $memory->salience,
                'status' => MemoryStatus::Active,
                'embedding' => $memory->embedding,
                'embedding_model' => $memory->embedding_model,
                'valid_from_chapter' => $memory->valid_from_chapter,
                'valid_to_chapter' => $memory->valid_to_chapter,
            ]);
        }
    }

    private function appliedEvent(Novel $novel, string $repairKey): ?StoryEvent
    {
        return $novel->storyEvents()->get()->first(
            fn (StoryEvent $event): bool => data_get($event->payload, 'repair_key') === $repairKey,
        );
    }

    /** @param array<string, mixed> $plan */
    private function repairKey(array $plan): string
    {
        return 'foreshadowing-history:'.hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
