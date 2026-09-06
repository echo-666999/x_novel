<?php

namespace App\Actions\Novels;

use App\Enums\BibleStatus;
use App\Models\Novel;
use App\Models\NovelBible;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateBibleVersionAction
{
    private const CONTENT_ATTRIBUTES = [
        'logline',
        'themes',
        'tone',
        'pov',
        'tense',
        'taboos',
        'hard_constraints',
        'ending_contract',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Novel $novel, array $attributes): NovelBible
    {
        return DB::transaction(function () use ($novel, $attributes): NovelBible {
            /** @var Novel $lockedNovel */
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            $currentBible = $lockedNovel->bibles()->first();
            $nextVersion = ($currentBible?->version ?? 0) + 1;

            if ($currentBible !== null) {
                $currentBible->update(['status' => BibleStatus::Superseded]);
            }

            return $lockedNovel->bibles()->create([
                ...Arr::only($attributes, self::CONTENT_ATTRIBUTES),
                'version' => $nextVersion,
                'status' => BibleStatus::Current,
            ]);
        });
    }
}
