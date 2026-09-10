<?php

namespace App\Actions\Novels;

use App\Enums\BibleStatus;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CleanupEditorialSettingsAction
{
    public function __construct(
        private readonly CreateBibleVersionAction $bibleVersions,
    ) {}

    public function execute(): int
    {
        return DB::transaction(function (): int {
            $novels = Novel::query()
                ->with('currentBible')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $issues = $novels
                ->map(fn (Novel $novel): ?string => $this->migrationIssue($novel))
                ->filter()
                ->values()
                ->all();

            if ($issues !== []) {
                throw ValidationException::withMessages([
                    'migration' => '旧 Editorial 清理已拒绝；以下小说尚未完成 Current Bible 迁移：'.implode('；', $issues),
                ]);
            }

            $cleaned = 0;

            foreach ($novels as $novel) {
                $settings = $novel->settings ?? [];

                if (! array_key_exists('editorial', $settings)) {
                    continue;
                }

                unset($settings['editorial']);
                $novel->update(['settings' => $settings]);
                $cleaned++;
            }

            return $cleaned;
        });
    }

    private function migrationIssue(Novel $novel): ?string
    {
        $bible = $novel->currentBible;
        $identity = "#{$novel->getKey()}《{$novel->title}》";

        if ($bible === null) {
            return $identity.'缺少 Current Bible';
        }

        if ($bible->status !== BibleStatus::Current) {
            return $identity.'最新 Bible 不是 Current 状态';
        }

        if (blank($bible->tone) || blank($bible->pov) || blank($bible->tense)) {
            return $identity.'叙事基线不完整';
        }

        try {
            $this->bibleVersions->validateStyleProfile($bible->style_profile);
        } catch (ValidationException) {
            return $identity.'文风设置不完整或无效';
        }

        return null;
    }
}
