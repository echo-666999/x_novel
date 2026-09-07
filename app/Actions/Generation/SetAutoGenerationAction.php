<?php

namespace App\Actions\Generation;

use App\Models\Novel;
use Illuminate\Support\Facades\DB;

class SetAutoGenerationAction
{
    public function handle(Novel $novel, bool $enabled): Novel
    {
        return DB::transaction(function () use ($novel, $enabled): Novel {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $settings = $lockedNovel->settings ?? [];
            $settings['auto_generate'] = $enabled;
            $lockedNovel->update(['settings' => $settings]);

            return $lockedNovel->refresh();
        });
    }
}
