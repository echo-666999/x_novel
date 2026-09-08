<?php

namespace App\Actions\Generation;

use App\Enums\NovelStatus;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\Novel;
use App\Services\MvpSoakRunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartMvpSoakRunAction
{
    public function __construct(private readonly GenerateNextChapterAction $generateNextChapter) {}

    public function handle(Novel $novel): Chapter
    {
        return DB::transaction(function () use ($novel): Chapter {
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if (! in_array($locked->status, [NovelStatus::Generating, NovelStatus::Completing], true)) {
                throw ValidationException::withMessages(['novel_id' => '只有生成中或收束中的小说可以启动 MVP 浸泡测试。']);
            }
            if ($this->hasRunningLongRun($locked)) {
                throw ValidationException::withMessages(['novel_id' => '该小说已有正在运行的长跑。']);
            }

            $start = ($locked->current_chapter_sequence ?? 0) + 1;
            $settings = $locked->settings ?? [];
            $settings['auto_generate'] = true;
            unset($settings['auto_stop']);
            $settings['soak_run'] = [
                'status' => 'running',
                'start_sequence' => $start,
                'target_sequence' => $start + MvpSoakRunService::CHAPTER_TARGET - 1,
                'started_at' => now()->toISOString(),
            ];
            $locked->update(['settings' => $settings]);

            $chapter = $this->generateNextChapter->handle($locked->refresh());
            if ($chapter->wasRecentlyCreated) {
                PlanChapterJob::dispatch($chapter->getKey())->afterCommit();
            }

            return $chapter;
        }, 3);
    }

    private function hasRunningLongRun(Novel $novel): bool
    {
        return (bool) data_get($novel->settings, 'auto_generate', false)
            && collect(['smoke_run', 'reliability_run', 'soak_run'])
                ->contains(fn (string $key): bool => data_get($novel->settings, "{$key}.status") === 'running');
    }
}
