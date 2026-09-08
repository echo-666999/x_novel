<?php

namespace App\Services;

use App\Data\ClosureDebtItem;
use App\Data\ClosureDebtResult;
use App\Enums\ForeshadowingImportance;
use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Models\Novel;

class ClosureDebtService
{
    private const TERMINAL_STATUSES = ['resolved', 'closed', 'completed', 'fulfilled', 'paid_off', 'abandoned'];

    private const ENDING_CONTRACT_FIELDS = [
        'final_protagonist_state' => '主角最终状态',
        'main_conflict_resolution' => '主冲突解决方式',
        'theme_payoff' => '主题兑现',
        'required_foreshadowing_payoff' => '必须回收的伏笔',
        'character_arc_requirements' => '人物弧要求',
        'allowed_open_endings' => '允许保留的开放结局',
    ];

    public function calculate(Novel $novel): ClosureDebtResult
    {
        $novel->loadMissing(['storyArcs', 'foreshadowings', 'currentBible', 'canonicalStateVersion']);

        $state = $novel->canonicalStateVersion?->state ?? [];
        $items = [
            ...$this->openArcs($novel),
            ...$this->openReaderPromises($state),
            ...$this->dueForeshadowings($novel),
            ...$this->importantRelationships($state),
            ...$this->worldCrises($state),
            ...$this->endingContractGaps($novel->currentBible?->ending_contract ?? []),
        ];

        return new ClosureDebtResult($items);
    }

    /** @return array<int, ClosureDebtItem> */
    private function openArcs(Novel $novel): array
    {
        return $novel->storyArcs
            ->reject(fn ($arc): bool => $arc->status === StoryArcStatus::Completed)
            ->map(fn ($arc): ClosureDebtItem => new ClosureDebtItem(
                'open_arc',
                '未完成故事弧',
                $arc->title,
                '故事弧仍处于'.$arc->status->getLabel().'状态。',
                $arc->type === StoryArcType::Main,
            ))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $state
     * @return array<int, ClosureDebtItem>
     */
    private function openReaderPromises(array $state): array
    {
        return collect($this->entries(data_get($state, 'reader_promises', [])))
            ->filter(fn (array $promise): bool => isset($promise['value'])
                && is_array($promise['value'])
                && ! $this->isResolved($promise['value']))
            ->map(fn (array $promise): ClosureDebtItem => new ClosureDebtItem(
                'reader_promise',
                '未兑现读者承诺',
                $this->title($promise['value'], $promise['key'], '未命名读者承诺'),
                '正式故事状态中的读者承诺尚未兑现。',
                $this->isCritical($promise['value']),
            ))
            ->values()
            ->all();
    }

    /** @return array<int, ClosureDebtItem> */
    private function dueForeshadowings(Novel $novel): array
    {
        return $novel->foreshadowings
            ->filter(fn ($foreshadowing): bool => $foreshadowing->isDue($novel->current_chapter_sequence)
                || $foreshadowing->isOverdue($novel->current_chapter_sequence))
            ->map(fn ($foreshadowing): ClosureDebtItem => new ClosureDebtItem(
                'due_foreshadowing',
                '到期伏笔',
                $foreshadowing->title,
                $foreshadowing->isOverdue($novel->current_chapter_sequence) ? '伏笔已超过最晚兑现章节。' : '伏笔已进入兑现窗口。',
                $foreshadowing->importance === ForeshadowingImportance::Critical,
            ))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $state
     * @return array<int, ClosureDebtItem>
     */
    private function importantRelationships(array $state): array
    {
        return collect($this->entries(data_get($state, 'relationships', [])))
            ->filter(function (array $relationship): bool {
                $value = $relationship['value'];

                return is_array($value) && $this->isImportant($value) && ! $this->isResolved($value);
            })
            ->map(fn (array $relationship): ClosureDebtItem => new ClosureDebtItem(
                'important_relationship',
                '未解决重要关系',
                $this->title($relationship['value'], $relationship['key'], '未命名关系'),
                '正式故事状态中的重要关系仍未解决。',
                $this->isCritical($relationship['value']),
            ))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $state
     * @return array<int, ClosureDebtItem>
     */
    private function worldCrises(array $state): array
    {
        $crises = [
            ...$this->entries(data_get($state, 'world.crises', [])),
            ...array_filter(
                $this->entries(data_get($state, 'open_threads', [])),
                fn (array $thread): bool => is_array($thread['value'])
                    && in_array(data_get($thread, 'value.type'), ['world_crisis', 'world-crisis'], true),
            ),
        ];

        return collect($crises)
            ->filter(fn (array $crisis): bool => is_array($crisis['value']) && ! $this->isResolved($crisis['value']))
            ->unique(fn (array $crisis): string => (string) $crisis['key'])
            ->map(fn (array $crisis): ClosureDebtItem => new ClosureDebtItem(
                'world_crisis',
                '未解决世界危机',
                $this->title($crisis['value'], $crisis['key'], '未命名世界危机'),
                '正式故事状态中的世界危机仍未解决。',
                true,
            ))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $contract
     * @return array<int, ClosureDebtItem>
     */
    private function endingContractGaps(array $contract): array
    {
        return collect(self::ENDING_CONTRACT_FIELDS)
            ->filter(fn (string $label, string $key): bool => blank($contract[$key] ?? null))
            ->map(fn (string $label): ClosureDebtItem => new ClosureDebtItem(
                'ending_contract_gap',
                '结局契约缺口',
                $label,
                '当前 Bible 尚未定义此项结局约束。',
                true,
            ))
            ->values()
            ->all();
    }

    /** @return array<int, array{key: string, value: mixed}> */
    private function entries(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $item, int|string $key): array => ['key' => (string) $key, 'value' => $item])
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $value */
    private function isResolved(array $value): bool
    {
        return in_array(strtolower((string) ($value['status'] ?? 'open')), self::TERMINAL_STATUSES, true);
    }

    /** @param array<string, mixed> $value */
    private function isImportant(array $value): bool
    {
        return ($value['important'] ?? $value['is_important'] ?? false) === true
            || in_array(strtolower((string) ($value['importance'] ?? $value['priority'] ?? '')), ['high', 'critical', 'core'], true);
    }

    /** @param array<string, mixed> $value */
    private function isCritical(array $value): bool
    {
        return ($value['critical'] ?? false) === true
            || strtolower((string) ($value['importance'] ?? $value['priority'] ?? '')) === 'critical';
    }

    /** @param array<string, mixed> $value */
    private function title(array $value, string $key, string $fallback): string
    {
        return (string) ($value['title'] ?? $value['name'] ?? $value['description'] ?? ($key !== '' ? $key : $fallback));
    }
}
