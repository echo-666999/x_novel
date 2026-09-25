<?php

namespace App\Services;

use App\Enums\ChapterStatus;
use App\Enums\NovelOutlineStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Models\Novel;

final readonly class NovelGenerationReadiness
{
    /**
     * @return array<int, array{key: string, label: string, ready: bool, repair_hint: string}>
     */
    public function evaluate(Novel $novel): array
    {
        $latestCanonical = $novel->chapters()
            ->where('status', ChapterStatus::Canonical)
            ->orderByDesc('sequence')
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
