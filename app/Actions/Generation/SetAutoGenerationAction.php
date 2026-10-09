<?php

namespace App\Actions\Generation;

use App\Models\Novel;
use Illuminate\Support\Facades\DB;

class SetAutoGenerationAction
{
    /** 更新自动续章开关；显式选择运行模式时同时冻结 PASS 后的提交策略。 */
    public function handle(Novel $novel, bool $enabled, ?bool $autoCommit = null): Novel
    {
        return DB::transaction(function () use ($novel, $enabled, $autoCommit): Novel {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $settings = $lockedNovel->settings ?? [];
            $settings['auto_generate'] = $enabled;
            if ($autoCommit !== null) {
                $settings['auto_commit'] = $autoCommit;
                $settings['auto_commit_configured'] = true;
            }
            if ($enabled || $autoCommit !== null) {
                unset($settings['auto_stop']);
            }
            $lockedNovel->update(['settings' => $settings]);

            return $lockedNovel->refresh();
        });
    }
}
