<?php

namespace App\Actions\Story;

use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use App\Models\Fact;
use App\Models\Novel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CreateManualFactAction
{
    /** @param array<string, mixed> $attributes */
    public function execute(Novel $novel, array $attributes): Fact
    {
        return DB::transaction(function () use ($novel, $attributes): Fact {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $this->ensureSubjectBelongsToNovel($lockedNovel, $attributes);

            return $lockedNovel->facts()->create([
                ...$attributes,
                'source_type' => FactSourceType::Manual,
                'source_event_id' => null,
                'status' => FactStatus::Active,
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function ensureSubjectBelongsToNovel(Novel $novel, array $attributes): void
    {
        $subjectType = $attributes['subject_type'] ?? null;
        $subjectId = $attributes['subject_id'] ?? null;

        $exists = match ($subjectType) {
            'character' => $novel->characters()->whereKey($subjectId)->exists(),
            'world_entity' => $novel->worldEntities()->whereKey($subjectId)->exists(),
            'novel' => (int) $subjectId === (int) $novel->getKey(),
            default => false,
        };

        if (! $exists) {
            throw (new ModelNotFoundException)->setModel((string) $subjectType, [$subjectId]);
        }
    }
}
