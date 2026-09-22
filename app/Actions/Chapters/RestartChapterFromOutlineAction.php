<?php

namespace App\Actions\Chapters;

use App\Enums\ChapterStatus;
use App\Enums\NovelOutlineStatus;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\PlanChapterJob;
use App\Models\Novel;
use App\Services\GenerationJobDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestartChapterFromOutlineAction
{
    public function __construct(private readonly GenerationJobDispatcher $dispatcher) {}

    /** @return array{status: string, chapter_id: int, outline_id: int, dispatched: bool} */
    public function handle(
        Novel $novel,
        int $expectedOutlineId,
        string $expectedOutlineChecksum,
        ?int $actorId = null,
    ): array {
        $result = DB::transaction(function () use ($novel, $expectedOutlineId, $expectedOutlineChecksum, $actorId): array {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $outline = $lockedNovel->currentOutline()->lockForUpdate()->first();
            if ($outline === null
                || $outline->status !== NovelOutlineStatus::Current
                || $outline->getKey() !== $expectedOutlineId
                || ! hash_equals($outline->checksum, $expectedOutlineChecksum)) {
                throw ValidationException::withMessages(['outline' => 'Current Outline 已变化，请重新载入后再重建当前章。']);
            }

            $chapter = $lockedNovel->chapters()
                ->where('sequence', ((int) $lockedNovel->current_chapter_sequence) + 1)
                ->lockForUpdate()
                ->first();
            if ($chapter === null || $chapter->status === ChapterStatus::Canonical) {
                throw ValidationException::withMessages(['chapter' => '当前没有可按新 Outline 重建的非正式章节。']);
            }

            $settingPath = "outline_restarts.{$outline->getKey()}.{$chapter->getKey()}";
            $existing = data_get($lockedNovel->settings, $settingPath);
            if (is_array($existing)) {
                return [
                    'status' => 'already_applied',
                    'chapter_id' => $chapter->getKey(),
                    'outline_id' => $outline->getKey(),
                    'should_dispatch' => blank($existing['dispatched_at'] ?? null),
                ];
            }

            if ($lockedNovel->generationRuns()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
                throw ValidationException::withMessages(['generation_runs' => '当前章仍有排队中或运行中的 Generation Run；请等待结束或先安全停止后再重建。']);
            }

            $chapter->plans()->whereIn('status', [PlanStatus::Draft, PlanStatus::Ready])->update([
                'status' => PlanStatus::Superseded,
            ]);
            $chapter->scenes()->update([
                'status' => SceneStatus::Planned,
                'current_artifact_id' => null,
            ]);
            $chapter->update([
                'status' => ChapterStatus::Void,
                'canonical_artifact_id' => null,
                'canonical_metadata' => null,
            ]);

            $settings = $lockedNovel->settings ?? [];
            data_set($settings, $settingPath, [
                'chapter_id' => $chapter->getKey(),
                'outline_id' => $outline->getKey(),
                'outline_checksum' => $outline->checksum,
                'actor_id' => $actorId,
                'executed_at' => now()->toISOString(),
                'dispatched_at' => null,
            ]);
            $lockedNovel->update(['settings' => $settings]);

            return [
                'status' => 'applied',
                'chapter_id' => $chapter->getKey(),
                'outline_id' => $outline->getKey(),
                'should_dispatch' => true,
            ];
        }, 3);

        $dispatched = false;
        if ($result['should_dispatch']) {
            $dispatched = $this->dispatcher->dispatch(new PlanChapterJob($result['chapter_id'], true));
            if ($dispatched) {
                $this->recordDispatch($novel->getKey(), $result['outline_id'], $result['chapter_id']);
            }
        }
        unset($result['should_dispatch']);

        return [...$result, 'dispatched' => $dispatched];
    }

    private function recordDispatch(int $novelId, int $outlineId, int $chapterId): void
    {
        DB::transaction(function () use ($novelId, $outlineId, $chapterId): void {
            $novel = Novel::query()->lockForUpdate()->findOrFail($novelId);
            $settings = $novel->settings ?? [];
            data_set($settings, "outline_restarts.{$outlineId}.{$chapterId}.dispatched_at", now()->toISOString());
            $novel->update(['settings' => $settings]);
        }, 3);
    }
}
