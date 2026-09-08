<?php

namespace App\Actions\Novels;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Models\Novel;
use App\Services\EndingAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteNovelAction
{
    public function __construct(private readonly EndingAuditService $endingAudit) {}

    public function handle(Novel $novel): Novel
    {
        return DB::transaction(function () use ($novel): Novel {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if ($lockedNovel->status === NovelStatus::Completed) {
                return $lockedNovel;
            }

            if ($lockedNovel->status !== NovelStatus::Completing) {
                throw ValidationException::withMessages([
                    'novel' => '只有收束中的小说可以完结。',
                ]);
            }

            $latestAudit = $lockedNovel->generationRuns()
                ->where('stage', GenerationStage::EndingAudit)
                ->latest('id')
                ->first()?->artifacts()
                ->where('type', ArtifactType::EndingAudit)
                ->latest('id')
                ->first();

            if (data_get($latestAudit?->data, 'decision') !== 'PASS') {
                throw ValidationException::withMessages([
                    'ending_audit' => '必须先完成并通过结局审计。',
                ]);
            }

            $audit = $this->endingAudit->audit($lockedNovel);

            if (data_get($audit->data, 'decision') !== 'PASS') {
                throw ValidationException::withMessages([
                    'ending_audit' => '结局审计未通过，请先处理所有 BLOCK 项。',
                ]);
            }

            $settings = $lockedNovel->settings ?? [];
            $settings['auto_generate'] = false;

            $lockedNovel->update([
                'status' => NovelStatus::Completed,
                'settings' => $settings,
            ]);

            return $lockedNovel->refresh();
        });
    }
}
