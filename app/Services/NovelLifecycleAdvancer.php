<?php

namespace App\Services;

use App\Actions\Novels\CompleteNovelAction;
use App\Data\NovelLifecycleResult;
use App\Enums\NovelStatus;
use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Enums\VolumeStatus;
use App\Exceptions\GenerationPreflightException;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\Volume;
use Illuminate\Support\Facades\DB;

/**
 * 在正式章节提交后推进运行态 Arc、Volume 和小说完结状态。
 *
 * Story Event 与 Story State 已由 CanonicalCommitService 提交；本服务只推进规划运行态，
 * 不写正式故事事实，也不绕过 Volume Completion Gate 或 Ending Audit。
 */
final class NovelLifecycleAdvancer
{
    public function __construct(
        private readonly VolumeCompletionGate $volumeCompletionGate,
        private readonly EndingAuditService $endingAudit,
        private readonly CompleteNovelAction $completeNovel,
    ) {}

    public function advance(Novel $novel): NovelLifecycleResult
    {
        $result = DB::transaction(function () use ($novel): NovelLifecycleResult {
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if ($locked->status === NovelStatus::Completed) {
                return new NovelLifecycleResult('completed', false, true);
            }

            if (! in_array($locked->status, [NovelStatus::Generating, NovelStatus::Completing], true)) {
                throw GenerationPreflightException::unavailableStatus();
            }

            $volume = $locked->volumes()
                ->where('status', VolumeStatus::Active)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->first();

            if ($volume === null) {
                $volume = $this->activateNextVolume($locked);
                if ($volume === null) {
                    if (! $locked->volumes()->exists()) {
                        throw GenerationPreflightException::currentVolumeMissing();
                    }

                    $locked->update(['status' => NovelStatus::Completing]);

                    return new NovelLifecycleResult('ending_audit', false);
                }
            }

            if ($this->activateCurrentMainArc($volume)) {
                return new NovelLifecycleResult('chapter_generation', true);
            }

            $gate = $this->volumeCompletionGate->evaluate($volume);
            if (! $gate->canComplete()) {
                $blocking = collect($gate->checks)
                    ->where('status', 'BLOCK')
                    ->map(fn (array $check): string => (string) ($check['label'] ?? $check['key'] ?? '未知检查项'))
                    ->implode('、');

                throw GenerationPreflightException::fromReadiness(
                    'volume_gate_failed',
                    '当前分卷已没有未完成主线，但完成检查仍被阻塞：'.$blocking,
                );
            }

            $this->volumeCompletionGate->complete($volume);
            $nextVolume = $this->activateNextVolume($locked);
            if ($nextVolume !== null) {
                if (! $this->activateCurrentMainArc($nextVolume)) {
                    throw GenerationPreflightException::fromReadiness(
                        'volume_main_arc_missing',
                        '下一分卷没有可推进的主线 Story Arc。',
                    );
                }

                return new NovelLifecycleResult('next_volume', true);
            }

            $locked->update(['status' => NovelStatus::Completing]);

            return new NovelLifecycleResult('ending_audit', false);
        }, 3);

        if ($result->state !== 'ending_audit') {
            return $result;
        }

        $novel = $novel->fresh();
        $audit = $this->endingAudit->audit($novel);
        if (data_get($audit->data, 'decision') !== 'PASS') {
            $blocking = collect(data_get($audit->data, 'checks', []))
                ->where('status', 'BLOCK')
                ->pluck('label')
                ->filter()
                ->implode('、');

            throw GenerationPreflightException::fromReadiness(
                'ending_audit_block',
                '结局审计未通过：'.($blocking !== '' ? $blocking : '存在未解决的收束债务。'),
            );
        }

        $this->completeNovel->handle($novel);

        return new NovelLifecycleResult('completed', false, true);
    }

    private function activateNextVolume(Novel $novel): ?Volume
    {
        $volume = $novel->volumes()
            ->where('status', VolumeStatus::Planned)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->first();

        if ($volume !== null) {
            $volume->update(['status' => VolumeStatus::Active]);
        }

        return $volume?->refresh();
    }

    /** 保证同一卷只有顺序最早的未完成主线处于 Active，支线随当前卷保持可规划。 */
    private function activateCurrentMainArc(Volume $volume): bool
    {
        $arcs = StoryArc::query()
            ->where('volume_id', $volume->getKey())
            ->with('sourceOutlineArc')
            ->lockForUpdate()
            ->get();
        $mainArcs = $arcs
            ->where('type', StoryArcType::Main)
            ->sortBy(fn (StoryArc $arc): string => sprintf(
                '%010d:%010d',
                $arc->sourceOutlineArc?->mainline_sequence ?? PHP_INT_MAX,
                $arc->sequence,
            ));
        $current = $mainArcs->first(fn (StoryArc $arc): bool => $arc->status !== StoryArcStatus::Completed);

        foreach ($mainArcs as $arc) {
            if ($arc->status === StoryArcStatus::Completed) {
                continue;
            }

            $arc->update(['status' => $current?->is($arc) ? StoryArcStatus::Active : StoryArcStatus::Planned]);
        }

        $arcs->where('type', StoryArcType::Subplot)
            ->where('status', StoryArcStatus::Planned)
            ->each(fn (StoryArc $arc) => $arc->update(['status' => StoryArcStatus::Active]));

        return $current !== null;
    }
}
