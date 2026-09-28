<?php

namespace App\Services;

use App\Data\NextChapterReadinessResult;
use App\Enums\ChapterStatus;
use App\Enums\NovelOutlineStatus;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Models\Novel;
use Illuminate\Validation\ValidationException;

final readonly class NovelGenerationReadiness
{
    public function __construct(
        private OutlineProgressResolver $outlineProgress,
        private GenerationRunLease $runLease,
        private NarrativeStyleProfile $narrativeStyleProfile,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, ready: bool, repair_hint: string}>
     */
    public function evaluate(Novel $novel): array
    {
        $latestCanonical = $novel->chapters()
            ->where('status', ChapterStatus::Canonical)
            ->reorder('sequence', 'desc')
            ->first(['id', 'sequence', 'summary']);

        return [
            $this->item(
                'current_outline',
                'Current Outline',
                $novel->currentOutline()->where('status', NovelOutlineStatus::Current)->exists(),
                '前往大纲工作区创建并采用一个 Current Outline。',
            ),
            $this->item(
                'bible',
                '小说圣经',
                $novel->currentBible()->exists(),
                '前往小说圣经创建并启用当前版本。',
            ),
            $this->item(
                'protagonist',
                '主角',
                $novel->characters()->where('role', '主角')->exists(),
                '前往人物管理创建至少一名角色类型为“主角”的人物。',
            ),
            $this->item(
                'world_entity',
                '世界设定',
                $novel->worldEntities()->exists(),
                '前往世界设定创建至少一个世界实体。',
            ),
            $this->item(
                'active_volume',
                'Active Volume',
                $novel->volumes()->where('status', VolumeStatus::Active)->exists(),
                '前往分卷规划激活当前分卷。',
            ),
            $this->item(
                'active_story_arc',
                'Active Story Arc',
                $novel->storyArcs()->where('status', StoryArcStatus::Active)->exists(),
                '前往故事线规划激活至少一条故事线。',
            ),
            $this->item(
                'initial_state',
                'Initial State',
                $novel->canonical_state_version_id !== null
                    && $novel->canonicalStateVersion()->where('novel_id', $novel->getKey())->exists(),
                '使用恢复操作“初始化故事状态”；AI Outline 正常采用时会自动完成。',
            ),
            $this->item(
                'previous_chapter_derivatives',
                '上一章派生数据',
                $latestCanonical === null || filled($latestCanonical->summary),
                $latestCanonical === null
                    ? '尚无正式章节，无需生成上一章派生数据。'
                    : "恢复第 {$latestCanonical->sequence} 章的 Canonical Summary 后再继续。",
            ),
        ];
    }

    public function isReady(Novel $novel): bool
    {
        return $this->allReady($this->evaluate($novel));
    }

    /**
     * Side-effect-free domain gate shared by UI callers and GenerateNextChapterAction.
     */
    public function nextChapter(Novel $novel): NextChapterReadinessResult
    {
        $blockers = [];
        $add = static function (string $code, string $message, string $repairAction, ?string $relatedType = null, ?int $relatedId = null) use (&$blockers): void {
            $blockers[] = array_filter([
                'code' => $code,
                'message' => $message,
                'repair_action' => $repairAction,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
            ], static fn (mixed $value): bool => $value !== null);
        };

        if ($novel->status === NovelStatus::Paused) {
            $add('novel_paused', '小说已暂停，无法生成下一章。', '恢复小说后继续', 'novel', $novel->getKey());
        } elseif (! in_array($novel->status, [NovelStatus::Generating, NovelStatus::Completing], true)) {
            $add('novel_status_unavailable', '小说必须处于生成中或收束中。', '检查小说生命周期状态', 'novel', $novel->getKey());
        }

        try {
            $this->narrativeStyleProfile->forNovel($novel);
        } catch (ValidationException $exception) {
            $add(
                'current_bible_incomplete',
                '小说圣经尚未完成迁移：'.$exception->getMessage().' 请先在“小说圣经”中创建完整的 Current Bible Version。',
                '补全并启用 Current Bible',
                'novel',
                $novel->getKey(),
            );
        }

        if ($novel->canonical_state_version_id === null
            || ! $novel->canonicalStateVersion()->where('novel_id', $novel->getKey())->exists()) {
            $add('story_state_uninitialized', 'Story State 尚未初始化。', '初始化或重建正式故事状态', 'novel', $novel->getKey());
        }

        $volume = $novel->volumes()->where('status', VolumeStatus::Active)->orderBy('sequence')->first();
        if ($volume === null) {
            $add('current_volume_missing', '没有进行中的卷，请先将一个卷设为进行中。', '激活 Current Volume', 'novel', $novel->getKey());
        }

        $outlineTarget = null;
        if ($novel->current_outline_id === null) {
            $add('current_outline_missing', '缺少 Current Outline。', '创建并采用 Current Outline', 'novel', $novel->getKey());
        } elseif ($volume !== null) {
            try {
                $target = $this->outlineProgress->resolve($novel);
                if ($target === null) {
                    $add('current_outline_target_exhausted', 'Current Outline 没有可继续执行的 Main Milestone。', '完成收束或采用后续 Outline', 'novel_outline', $novel->current_outline_id);
                } else {
                    $outlineTarget = $target->toArray();
                }
            } catch (ValidationException $exception) {
                $add('current_outline_target_invalid', $exception->getMessage(), '修复 Current Outline、Milestone 或 Handoff 来源链', 'novel_outline', $novel->current_outline_id);
            }
        }

        $currentSequence = (int) ($novel->current_chapter_sequence ?? 0);
        $latestCanonical = $novel->chapters()
            ->where('status', ChapterStatus::Canonical)
            ->reorder('sequence', 'desc')
            ->first();
        if ($currentSequence > 0 && ($latestCanonical === null || $latestCanonical->sequence !== $currentSequence)) {
            $add('previous_chapter_not_canonical', 'Novel 的当前章节指针没有对应到最新正式章节。', '重建正式章节与 Novel 指针', 'chapter', $latestCanonical?->getKey());
        } elseif ($latestCanonical !== null) {
            if ($latestCanonical->canonical_artifact_id === null) {
                $add('previous_chapter_commit_incomplete', '上一章缺少 Canonical Artifact。', '恢复 Canonical Commit', 'chapter', $latestCanonical->getKey());
            }
            if (blank($latestCanonical->summary)) {
                $add('previous_chapter_summary_missing', "第 {$latestCanonical->sequence} 章的正式摘要尚未生成，请先在生成恢复中心重试章节摘要。", '重试 Canonical Summary', 'chapter', $latestCanonical->getKey());
            }
            if ($novel->canonicalStateVersion()->where('chapter_id', $latestCanonical->getKey())->doesntExist()) {
                $add('previous_chapter_state_missing', '上一正式章没有对应的当前 Story State Version。', '重建正式故事状态', 'chapter', $latestCanonical->getKey());
            }
        }

        $failedChapter = $novel->chapters()
            ->where('status', ChapterStatus::Blocked)
            ->orderBy('sequence')
            ->first();
        if ($failedChapter !== null) {
            $add('blocked_review', '存在被审校或生成错误阻塞的章节。', '处理阻塞章节', 'chapter', $failedChapter->getKey());
        }

        $failedScene = $novel->chapters()->whereHas('scenes', fn ($query) => $query->where('status', SceneStatus::Failed))->orderBy('sequence')->first();
        if ($failedScene !== null && $failedScene->getKey() !== $failedChapter?->getKey()) {
            $add('failed_chapter_exists', '存在 Scene 生成失败且尚未恢复的章节。', '从失败 Scene 恢复', 'chapter', $failedScene->getKey());
        }

        $attention = $novel->chapters()
            ->whereHas('generationRuns.review', fn ($query) => $query->where('decision', ReviewDecision::NeedsAttention))
            ->orderBy('sequence')
            ->first();
        if ($attention !== null) {
            $add('needs_attention_pending', '存在尚未处理的 NEEDS_ATTENTION 审校。', '处理审校建议或人工覆盖', 'chapter', $attention->getKey());
        }

        $targetSequence = $currentSequence + 1;
        $targetChapterId = $novel->chapters()->where('sequence', $targetSequence)->value('id');
        $activeChapter = $novel->chapters()
            ->whereIn('status', [ChapterStatus::Planned, ChapterStatus::Generating, ChapterStatus::Review, ChapterStatus::Rewrite])
            ->where('sequence', '!=', $targetSequence)
            ->orderBy('sequence')
            ->first();
        if ($activeChapter !== null) {
            $add('active_workflow_exists', '该小说已有另一个活跃章节工作流。', '等待或恢复当前 Stage', 'chapter', $activeChapter->getKey());
        }
        $activeRun = $novel->generationRuns()
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->when($targetChapterId !== null, fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('chapter_id')->orWhere('chapter_id', '!=', $targetChapterId)))
            ->latest('id')->get()->first(fn ($run): bool => $run->status === RunStatus::Queued || $this->runLease->isFresh($run));
        if ($activeRun !== null && $activeChapter === null) {
            $add('active_workflow_exists', '该小说已有另一个活跃章节工作流。', '等待或恢复当前 Stage', 'generation_run', $activeRun->getKey());
        }

        $priority = [
            'novel_paused' => 10,
            'novel_status_unavailable' => 20,
            'active_workflow_exists' => 30,
            'blocked_review' => 40,
            'failed_chapter_exists' => 50,
            'needs_attention_pending' => 60,
            'previous_chapter_summary_missing' => 70,
        ];
        usort($blockers, static fn (array $left, array $right): int => ($priority[$left['code']] ?? 100) <=> ($priority[$right['code']] ?? 100));

        return new NextChapterReadinessResult($blockers, $outlineTarget);
    }

    /** @param array<int, array{key: string, label: string, ready: bool, repair_hint: string}> $items */
    public function allReady(array $items): bool
    {
        return collect($items)->every(
            fn (array $item): bool => $item['ready'],
        );
    }

    /** @return array<int, array{key: string, label: string, ready: bool, repair_hint: string}> */
    public function missing(Novel $novel): array
    {
        return array_values(array_filter(
            $this->evaluate($novel),
            fn (array $item): bool => ! $item['ready'],
        ));
    }

    public function missingMessage(Novel $novel): string
    {
        return $this->missingMessageFor($this->evaluate($novel));
    }

    /** @param array<int, array{key: string, label: string, ready: bool, repair_hint: string}> $items */
    public function missingMessageFor(array $items): string
    {
        $labels = collect($items)
            ->reject(fn (array $item): bool => $item['ready'])
            ->pluck('label')
            ->implode('、');

        return $labels === '' ? '' : '规划尚未就绪，缺少：'.$labels.'。';
    }

    /** @return array{key: string, label: string, ready: bool, repair_hint: string} */
    private function item(string $key, string $label, bool $ready, string $repairHint): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ready' => $ready,
            'repair_hint' => $repairHint,
        ];
    }
}
