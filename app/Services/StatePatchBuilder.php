<?php

namespace App\Services;

use App\Contracts\StoryEventApplier;
use App\Data\StatePatch;
use App\Data\StoryEventCandidate;
use App\Enums\ArtifactType;
use App\Enums\EventType;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StatePatchBuilder
{
    public function __construct(
        private readonly StoryEventApplier $eventApplier,
        private readonly StoryStateService $storyState,
    ) {}

    public function build(int $chapterId, ?int $candidateId = null): GenerationArtifact
    {
        $chapter = Chapter::query()->with('novel.canonicalStateVersion')->findOrFail($chapterId);
        // 新链显式指定当前事件候选，不能把另一 Run 的最新版本混入当前草稿。
        $candidateArtifact = $candidateId ? GenerationArtifact::whereKey($candidateId)->where('type', ArtifactType::EventCandidate)
            ->whereHas('generationRun', fn ($q) => $q->where('chapter_id', $chapterId))->firstOrFail() : $this->latestCandidateArtifact($chapter);
        $stateVersion = $chapter->novel->canonicalStateVersion;

        if ($stateVersion === null) {
            throw ValidationException::withMessages(['state' => 'Novel 尚未初始化 Canonical Story State。']);
        }

        if ($candidateArtifact->generationRun->state_version !== $stateVersion->version) {
            throw ValidationException::withMessages(['state' => 'Event Candidate 使用的 State Version 已过期，请重新提取事件。']);
        }

        $events = $this->events($candidateArtifact);
        $operations = [];

        foreach ($events as $index => $event) {
            if (in_array($event->eventType, [EventType::CharacterIntroduced, EventType::WorldEntityIntroduced], true)) {
                continue;
            }

            if (! $this->eventApplier->supports($event->eventType->value)) {
                continue;
            }

            foreach ($this->eventOperations($event, (bool) data_get($candidateArtifact->generationRun->execution_contract, 'book_workflow'), $stateVersion->state) as $operation) {
                $operations[] = [...$operation, 'source_event_index' => $index];
            }
        }

        $patch = new StatePatch($stateVersion->version, $operations);
        [$after, $changes] = $this->apply($stateVersion->state, $patch, $events);
        $data = [
            ...$patch->toArray(),
            'status' => 'candidate',
            'source_artifact_id' => $candidateArtifact->getKey(),
            'before_checksum' => $stateVersion->checksum,
            'after_checksum' => $this->storyState->checksum($after),
            'changes' => $changes,
        ];
        $checksum = $this->checksum($data);

        return DB::transaction(function () use ($chapter, $candidateArtifact, $stateVersion, $data, $checksum): GenerationArtifact {
            $lockedChapter = Chapter::query()->lockForUpdate()->with('novel.canonicalStateVersion')->findOrFail($chapter->getKey());

            if ($lockedChapter->novel->canonicalStateVersion?->version !== $stateVersion->version) {
                throw ValidationException::withMessages(['state' => 'State Patch 构建期间 Canonical Story State 已变化。']);
            }

            $existing = $candidateArtifact->generationRun->artifacts()
                ->where('type', ArtifactType::StatePatch)
                ->where('checksum', $checksum)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $version = GenerationArtifact::query()
                ->where('type', ArtifactType::StatePatch)
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->max('version');

            return $candidateArtifact->generationRun->artifacts()->create([
                // 来源随不可变补丁首次写入，禁止创建后再补写来源字段。
                'source_manifest' => [['artifact_id' => $candidateArtifact->id, 'checksum' => $candidateArtifact->checksum]],
                'type' => ArtifactType::StatePatch,
                'version' => ((int) $version) + 1,
                'content' => json_encode($data['operations'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'data' => $data,
                'checksum' => $checksum,
            ]);
        });
    }

    /** 整书链按正式状态中已有实体所在集合定位，历史合同维持原行为。 */
    public function eventOperations(StoryEventCandidate $event, bool $bookWorkflow = false, array $state = []): array
    {
        $operations = $this->eventApplier->operations($event);
        if ($bookWorkflow && $event->subjectType === 'world_entity') {
            foreach ($operations as &$operation) {
                if ($operation['path'] === 'world.'.$event->subjectId) {
                    $paths = array_values(array_filter(['locations.'.$event->subjectId, 'items.'.$event->subjectId, 'world.entities.'.$event->subjectId], fn (string $path): bool => Arr::has($state, $path)));
                    if (count($paths) > 1) {
                        throw ValidationException::withMessages(['state' => '世界实体存在多个正式路径，不能推断更新目标。']);
                    }
                    $operation['path'] = $paths[0] ?? 'world.entities.'.$event->subjectId;
                }
            }
            unset($operation);
        }

        return $operations;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public function applyArtifact(array $state, GenerationArtifact $artifact): array
    {
        if ($artifact->type !== ArtifactType::StatePatch) {
            throw ValidationException::withMessages(['state_patch' => '指定 Artifact 不是 State Patch。']);
        }

        $patch = new StatePatch(
            expectedStateVersion: (int) data_get($artifact->data, 'expected_state_version', -1),
            operations: data_get($artifact->data, 'operations', []),
            factChanges: data_get($artifact->data, 'fact_changes', []),
            foreshadowingChanges: data_get($artifact->data, 'foreshadowing_changes', []),
        );

        return $this->applyPatch($state, $patch);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public function applyPatch(array $state, StatePatch $patch): array
    {
        foreach ($patch->operations as $operation) {
            $state = $this->applyOperation($state, $operation);
        }

        return $state;
    }

    private function latestCandidateArtifact(Chapter $chapter): GenerationArtifact
    {
        $artifact = GenerationArtifact::query()
            ->where('type', ArtifactType::EventCandidate)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->with('generationRun')
            ->latest('version')
            ->latest('id')
            ->first();

        if ($artifact === null) {
            throw ValidationException::withMessages(['events' => '请先生成 Story Event Candidates。']);
        }

        return $artifact;
    }

    /** @return array<int, StoryEventCandidate> */
    private function events(GenerationArtifact $artifact): array
    {
        $events = data_get($artifact->data, 'events');

        if (! is_array($events) || ! array_is_list($events)) {
            throw ValidationException::withMessages(['events' => 'Event Candidate Artifact 数据无效。']);
        }

        return array_map(function (mixed $event): StoryEventCandidate {
            if (! is_array($event)) {
                throw ValidationException::withMessages(['events' => 'Event Candidate Artifact 数据无效。']);
            }

            return StoryEventCandidate::fromArray($event);
        }, $events);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<int, StoryEventCandidate>  $events
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function apply(array $state, StatePatch $patch, array $events): array
    {
        $after = $state;
        $changes = [];

        foreach ($patch->operations as $operation) {
            [$beforeExists, $before] = $this->valueAt($after, $operation['path']);
            $after = $this->applyOperation($after, $operation);
            [$afterExists, $afterValue] = $this->valueAt($after, $operation['path']);
            $source = $events[$operation['source_event_index']];

            $changes[] = [
                'path' => $operation['path'],
                'operation' => $operation['op'],
                'before' => $before,
                'after' => $afterValue,
                'before_missing' => ! $beforeExists,
                'after_missing' => ! $afterExists,
                'source_event_index' => $operation['source_event_index'],
                'source_event_type' => $source->eventType->value,
                'source_subject_type' => $source->subjectType,
                'source_subject_id' => $source->subjectId,
            ];
        }

        return [$after, $changes];
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $operation @return array<string, mixed> */
    private function applyOperation(array $state, array $operation): array
    {
        $path = $operation['path'];
        [$exists, $current] = $this->valueAt($state, $path);

        match ($operation['op']) {
            'set' => data_set($state, $path, $operation['value']),
            'unset' => Arr::forget($state, $path),
            'increment' => data_set($state, $path, ($exists && is_numeric($current) ? $current : 0) + $operation['value']),
            'append_unique' => data_set($state, $path, array_values(array_unique([
                ...($exists && is_array($current) ? $current : []),
                $operation['value'],
            ], SORT_REGULAR))),
            'remove' => data_set($state, $path, array_values(array_filter(
                $exists && is_array($current) ? $current : [],
                fn (mixed $item): bool => $item !== $operation['value'],
            ))),
        };

        return $state;
    }

    /** @param array<string, mixed> $state @return array{bool, mixed} */
    private function valueAt(array $state, string $path): array
    {
        $value = $state;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }

    /** @param array<string, mixed> $data */
    private function checksum(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
