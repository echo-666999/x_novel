<?php

namespace App\Services;

use App\Data\VolumeCompletionResult;
use App\Enums\EventType;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\ReviewDecision;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Models\Foreshadowing;
use App\Models\Review;
use App\Models\StoryEvent;
use App\Models\Volume;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VolumeCompletionGate
{
    private const CHARACTER_STAGE_EVENTS = [
        EventType::CharacterStatusChanged,
        EventType::CharacterGoalChanged,
        EventType::CharacterAbilityAcquired,
        EventType::CharacterAbilityChanged,
        EventType::RelationshipChanged,
    ];

    public function evaluate(Volume $volume): VolumeCompletionResult
    {
        $volume = Volume::query()->with('storyArcs')->findOrFail($volume->getKey());
        $checks = [
            $this->definedCheck('goal', 'Volume Goal', $volume->goal, '本卷目标已定义。', '缺少本卷目标。'),
            $this->definedCheck('climax', 'Climax', $volume->climax, '本卷高潮已定义。', '缺少本卷高潮。'),
            $this->arcCheck($volume),
            $this->characterStageCheck($volume),
            $this->foreshadowingCheck($volume),
            $this->blockingFindingCheck($volume),
        ];

        return new VolumeCompletionResult($checks);
    }

    public function complete(Volume $volume): Volume
    {
        return DB::transaction(function () use ($volume): Volume {
            $locked = Volume::query()->lockForUpdate()->findOrFail($volume->getKey());
            if ($locked->status === VolumeStatus::Completed) {
                return $locked;
            }
            if ($locked->status !== VolumeStatus::Active) {
                throw ValidationException::withMessages(['volume' => '只有进行中的分卷可以完成。']);
            }

            $result = $this->evaluate($locked);
            if (! $result->canComplete()) {
                $labels = collect($result->checks)
                    ->where('status', 'BLOCK')
                    ->pluck('label')
                    ->implode('、');
                throw ValidationException::withMessages(['volume' => 'Completion Checklist 仍有阻塞项：'.$labels]);
            }

            $locked->update(['status' => VolumeStatus::Completed]);

            return $locked->refresh();
        });
    }

    /** @return array{key: string, label: string, status: string, message: string} */
    private function definedCheck(string $key, string $label, ?string $value, string $pass, string $block): array
    {
        $defined = filled($value);

        return $this->check($key, $label, $defined ? 'PASS' : 'BLOCK', $defined ? $pass : $block);
    }

    /** @return array{key: string, label: string, status: string, message: string} */
    private function arcCheck(Volume $volume): array
    {
        if ($volume->storyArcs->isEmpty()) {
            return $this->check('required_arcs', 'Required Arcs', 'WARNING', '本卷未关联 Story Arc，请确认是否符合规划。');
        }

        $open = $volume->storyArcs->where('status', '!=', StoryArcStatus::Completed);

        return $this->check(
            'required_arcs',
            'Required Arcs',
            $open->isEmpty() ? 'PASS' : 'BLOCK',
            $open->isEmpty() ? '本卷关联的 Story Arc 均已完成。' : '仍有 '.$open->count().' 条 Story Arc 未完成。',
        );
    }

    /** @return array{key: string, label: string, status: string, message: string} */
    private function characterStageCheck(Volume $volume): array
    {
        $count = StoryEvent::query()
            ->active()
            ->whereHas('chapter', fn ($query) => $query->where('volume_id', $volume->getKey()))
            ->whereIn('event_type', self::CHARACTER_STAGE_EVENTS)
            ->count();

        return $this->check(
            'character_stage_changes',
            'Character Stage Changes',
            $count > 0 ? 'PASS' : 'WARNING',
            $count > 0 ? "已记录 {$count} 条角色阶段变化事件。" : '未找到角色阶段变化事件，请确认人物弧是否需要推进。',
        );
    }

    /** @return array{key: string, label: string, status: string, message: string} */
    private function foreshadowingCheck(Volume $volume): array
    {
        $chapterSequence = $volume->chapters()->max('sequence');
        $due = Foreshadowing::query()
            ->where('novel_id', $volume->novel_id)
            ->whereNotIn('status', [ForeshadowingStatus::PaidOff, ForeshadowingStatus::Abandoned])
            ->where(function ($query) use ($volume, $chapterSequence): void {
                $query->whereHas('ownerArc', fn ($arc) => $arc->where('volume_id', $volume->getKey()));
                if ($chapterSequence !== null) {
                    $query->orWhere('due_to_chapter', '<=', $chapterSequence);
                }
            })
            ->get()
            ->filter(fn (Foreshadowing $item): bool => $item->isDue($chapterSequence) || $item->isOverdue($chapterSequence));
        $critical = $due->where('importance', ForeshadowingImportance::Critical)->count();
        $status = $critical > 0 ? 'BLOCK' : ($due->isNotEmpty() ? 'WARNING' : 'PASS');
        $message = match ($status) {
            'BLOCK' => "仍有 {$critical} 条关键伏笔到期或逾期。",
            'WARNING' => '仍有 '.$due->count().' 条非关键伏笔到期或逾期，请确认处理方式。',
            default => '没有待处理的到期伏笔。',
        };

        return $this->check('due_foreshadowings', 'Due Foreshadowings', $status, $message);
    }

    /** @return array{key: string, label: string, status: string, message: string} */
    private function blockingFindingCheck(Volume $volume): array
    {
        $reviews = Review::query()
            ->whereIn('id', $this->latestReviewIds($volume))
            ->whereIn('decision', [ReviewDecision::NeedsAttention, ReviewDecision::Block])
            ->count();

        return $this->check(
            'blocking_findings',
            'Blocking Findings',
            $reviews > 0 ? 'BLOCK' : 'PASS',
            $reviews > 0 ? "仍有 {$reviews} 章的最新 Review 需要处理。" : '本卷没有未处理的阻塞审校结果。',
        );
    }

    private function latestReviewIds(Volume $volume): Builder
    {
        $grammar = DB::connection()->getQueryGrammar();
        $reviews = $grammar->wrapTable('reviews');
        $id = $grammar->wrap('id');

        return DB::table('reviews')
            ->join('generation_runs', 'generation_runs.id', '=', 'reviews.generation_run_id')
            ->join('chapters', 'chapters.id', '=', 'generation_runs.chapter_id')
            ->where('chapters.volume_id', $volume->getKey())
            ->groupBy('generation_runs.chapter_id')
            ->selectRaw("MAX({$reviews}.{$id})");
    }

    /** @return array{key: string, label: string, status: string, message: string} */
    private function check(string $key, string $label, string $status, string $message): array
    {
        return compact('key', 'label', 'status', 'message');
    }
}
