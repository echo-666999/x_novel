<?php

namespace App\Actions\Novels;

use App\Enums\NovelStatus;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnterCompletingModeAction
{
    public function handle(Novel $novel): Novel
    {
        return DB::transaction(function () use ($novel): Novel {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if ($lockedNovel->status === NovelStatus::Completing) {
                return $lockedNovel;
            }

            if ($lockedNovel->status !== NovelStatus::Generating) {
                throw ValidationException::withMessages([
                    'novel' => '只有生成中的小说可以进入收束期。',
                ]);
            }

            $lockedNovel->update(['status' => NovelStatus::Completing]);

            return $lockedNovel->refresh();
        });
    }
}
