<?php

namespace App\Actions\Foreshadowings;

use App\Actions\Story\ManualCanonicalCorrectionAction;
use App\Enums\ForeshadowingStatus;
use App\Models\Foreshadowing;
use App\Models\StoryStateVersion;
use App\Services\ForeshadowingLifecycleResolver;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AbandonForeshadowingAction
{
    public function __construct(
        private readonly ForeshadowingLifecycleResolver $lifecycleResolver,
        private readonly ManualCanonicalCorrectionAction $correction,
    ) {}

    public function execute(Foreshadowing $foreshadowing, string $reason, ?int $actorId = null): StoryStateVersion
    {
        $validated = Validator::make(compact('reason'), [
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        $foreshadowing->loadMissing('novel.canonicalStateVersion');
        $version = $foreshadowing->novel->canonicalStateVersion;

        if ($version === null) {
            throw ValidationException::withMessages([
                'state' => '小说尚未初始化 Canonical Story State，不能执行放弃操作。',
            ]);
        }

        if ($this->lifecycleResolver->status($foreshadowing, $foreshadowing->novel)->isTerminal()) {
            throw ValidationException::withMessages([
                'foreshadowing' => '已兑现或已放弃的伏笔不能再次放弃。',
            ]);
        }

        return $this->correction->execute(
            novel: $foreshadowing->novel,
            expectedStateVersion: $version->version,
            path: "foreshadowings.{$foreshadowing->getKey()}.status",
            value: ForeshadowingStatus::Abandoned->value,
            reason: trim($validated['reason']),
            actorId: $actorId,
            metadata: ['management_action' => 'abandon'],
        );
    }
}
