<?php

namespace App\Actions\Story;

use App\Data\StatePatch;
use App\Enums\EventType;
use App\Enums\StoryEventStatus;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Services\StatePatchBuilder;
use App\Services\StoryStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManualCanonicalCorrectionAction
{
    public function __construct(
        private readonly StatePatchBuilder $statePatchBuilder,
        private readonly StoryStateService $storyState,
    ) {}

    public function execute(
        Novel $novel,
        int $expectedStateVersion,
        string $path,
        mixed $value,
        string $reason,
    ): StoryStateVersion {
        $validated = Validator::make(compact('path', 'reason'), [
            'path' => ['required', 'string', 'max:500'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($novel, $expectedStateVersion, $value, $validated): StoryStateVersion {
            $lockedNovel = Novel::query()
                ->lockForUpdate()
                ->with('canonicalStateVersion')
                ->findOrFail($novel->getKey());
            $current = $lockedNovel->canonicalStateVersion;

            if ($current === null || $current->version !== $expectedStateVersion) {
                throw ValidationException::withMessages([
                    'state' => 'Expected State Version 与当前 Canonical Story State 不一致。',
                ]);
            }

            if ($current->chapter_id === null) {
                throw ValidationException::withMessages([
                    'state' => '至少需要一章正式章节后才能执行人工状态修正。',
                ]);
            }

            $operation = [
                'op' => 'set',
                'path' => $validated['path'],
                'value' => $value,
                'source_event_index' => 0,
            ];
            $patch = new StatePatch($current->version, [$operation]);
            $nextState = $this->statePatchBuilder->applyPatch($current->state, $patch);

            if ($this->storyState->checksum($nextState) === $current->checksum) {
                throw ValidationException::withMessages([
                    'value' => '修正值与当前正式状态一致。',
                ]);
            }

            $nextVersion = $current->version + 1;
            $segments = explode('.', $validated['path']);

            $lockedNovel->storyEvents()->create([
                'chapter_id' => $current->chapter_id,
                'scene_id' => null,
                'event_type' => EventType::ManualCorrection,
                'subject_type' => $segments[0],
                'subject_id' => $segments[1] ?? null,
                'payload' => [
                    'path' => $validated['path'],
                    'before' => data_get($current->state, $validated['path']),
                    'after' => $value,
                    'reason' => $validated['reason'],
                    'previous_state_version' => $current->version,
                ],
                'evidence' => [[
                    'type' => 'manual_correction',
                    'quote' => $validated['reason'],
                ]],
                'story_time' => null,
                'state_version' => $nextVersion,
                'status' => StoryEventStatus::Active,
            ]);

            $version = $lockedNovel->storyStateVersions()->create([
                'version' => $nextVersion,
                'chapter_id' => $current->chapter_id,
                'state' => $nextState,
                'checksum' => $this->storyState->checksum($nextState),
            ]);
            $lockedNovel->update(['canonical_state_version_id' => $version->getKey()]);

            return $version;
        }, 3);
    }
}
