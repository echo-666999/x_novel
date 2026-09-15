<?php

namespace App\Actions\Foreshadowings;

use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Services\ForeshadowingLifecycleResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeferForeshadowingAction
{
    public function __construct(private readonly ForeshadowingLifecycleResolver $lifecycleResolver) {}

    public function execute(
        Foreshadowing $foreshadowing,
        int $newDueFromChapter,
        int $newDueToChapter,
        string $reason,
        ?int $actorId = null,
    ): Foreshadowing {
        $validated = Validator::make([
            'new_due_from_chapter' => $newDueFromChapter,
            'new_due_to_chapter' => $newDueToChapter,
            'reason' => $reason,
        ], [
            'new_due_from_chapter' => ['required', 'integer', 'min:1'],
            'new_due_to_chapter' => ['required', 'integer', 'gte:new_due_from_chapter'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($foreshadowing, $validated, $actorId): Foreshadowing {
            $locked = Foreshadowing::query()->lockForUpdate()->findOrFail($foreshadowing->getKey());
            $novel = Novel::query()->with('canonicalStateVersion')->findOrFail($locked->novel_id);
            $status = $this->lifecycleResolver->status($locked, $novel);

            if ($status->isTerminal()) {
                throw ValidationException::withMessages([
                    'foreshadowing' => '已兑现或已放弃的伏笔不能延期。',
                ]);
            }

            if ($validated['new_due_from_chapter'] <= $locked->due_to_chapter) {
                throw ValidationException::withMessages([
                    'new_due_from_chapter' => '延期后的窗口必须晚于当前最晚兑现章节。',
                ]);
            }

            $history = is_array($locked->management_history) ? $locked->management_history : [];
            $history[] = [
                'action' => 'defer',
                'reason' => trim($validated['reason']),
                'before' => [
                    'due_from_chapter' => $locked->due_from_chapter,
                    'due_to_chapter' => $locked->due_to_chapter,
                    'status' => $status->value,
                ],
                'after' => [
                    'due_from_chapter' => $validated['new_due_from_chapter'],
                    'due_to_chapter' => $validated['new_due_to_chapter'],
                    'status' => $status->value,
                ],
                'canonical_chapter' => $novel->current_chapter_sequence ?? 0,
                'state_version' => $novel->canonicalStateVersion?->version,
                'actor_id' => $actorId,
                'performed_at' => now()->toISOString(),
            ];

            $locked->update([
                'due_from_chapter' => $validated['new_due_from_chapter'],
                'due_to_chapter' => $validated['new_due_to_chapter'],
                'management_history' => $history,
            ]);

            return $locked->refresh();
        }, 3);
    }
}
