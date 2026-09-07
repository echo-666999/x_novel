<?php

namespace App\Services;

use App\Contracts\StoryEventApplier;
use App\Data\StatePatch;
use App\Data\StoryEventCandidate;
use App\Enums\ArtifactType;
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

    public function build(int $chapterId): GenerationArtifact
    {
        $chapter = Chapter::query()->with('novel.canonicalStateVersion')->findOrFail($chapterId);
        $candidateArtifact = $this->latestCandidateArtifact($chapter);
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
            if (! $this->eventApplier->supports($event->eventType->value)) {
                continue;
            }

            foreach ($this->eventApplier->operations($event) as $operation) {
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
                'type' => ArtifactType::StatePatch,
                'version' => ((int) $version) + 1,
                'content' => json_encode($data['operations'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'data' => $data,
                'checksum' => $checksum,
            ]);
        });
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
