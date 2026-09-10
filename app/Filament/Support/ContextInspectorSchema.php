<?php

namespace App\Filament\Support;

use App\Services\ContextInspector;
use Closure;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class ContextInspectorSchema
{
    /** @return array<int, mixed> */
    public static function make(Closure $runResolver, string $prefix = 'context_'): array
    {
        $inspections = [];
        $inspect = function (mixed $record = null) use (&$inspections, $runResolver): array {
            $run = $runResolver($record);

            return $inspections[$run->getKey()] ??= app(ContextInspector::class)->inspect($run);
        };
        $json = fn (mixed $value): string => json_encode(
            $value ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: '{}';

        return [
            Section::make('Context 版本')->columns(5)->schema([
                TextEntry::make($prefix.'state_version')->label('故事状态版本')->state(fn ($record): string => 'v'.(data_get($inspect($record), 'meta.state_version') ?? '—'))->badge(),
                TextEntry::make($prefix.'bible_version')->label('Bible 版本')->state(fn ($record): string => 'v'.(data_get($inspect($record), 'meta.bible_version') ?? '—'))->badge(),
                TextEntry::make($prefix.'style_contract_checksum')->label('Style Contract checksum')->state(fn ($record) => data_get($inspect($record), 'meta.style_contract_checksum'))->placeholder('—')->copyable(),
                TextEntry::make($prefix.'prompt_version')->label('Prompt Version')->state(fn ($record) => data_get($inspect($record), 'meta.prompt_version'))->placeholder('—'),
                TextEntry::make($prefix.'model')->label('模型')->state(fn ($record) => data_get($inspect($record), 'meta.model'))->placeholder('—'),
            ]),
            ...self::layerSections($inspect, $json, $prefix),
            Section::make('Token 分配')
                ->description('各层实际占用和剩余预算；L0/L1/L4 硬性契约为不可裁剪层。')
                ->columns(3)
                ->schema([
                    TextEntry::make($prefix.'token_budget')->label('总预算')->state(fn ($record): int => (int) data_get($inspect($record), 'token_allocation.budget'))->numeric(),
                    TextEntry::make($prefix.'token_used')->label('已使用')->state(fn ($record): int => (int) data_get($inspect($record), 'token_allocation.used'))->numeric(),
                    TextEntry::make($prefix.'token_remaining')->label('剩余')->state(fn ($record): int => (int) data_get($inspect($record), 'token_allocation.remaining'))->numeric(),
                    RepeatableEntry::make($prefix.'token_allocation')
                        ->label('分层用量')
                        ->state(fn ($record): array => data_get($inspect($record), 'token_allocation.sections', []))
                        ->columns(2)
                        ->schema([
                            TextEntry::make('section')->label('Context 层')->badge(),
                            TextEntry::make('tokens')->label('Tokens')->numeric(),
                        ])
                        ->columnSpanFull(),
                ]),
            Section::make('已裁剪部分')
                ->description('因 Token Budget 被裁剪或不可用的 Context 部分。')
                ->schema([
                    RepeatableEntry::make($prefix.'truncated_sections')
                        ->hiddenLabel()
                        ->state(fn ($record): array => data_get($inspect($record), 'truncated_sections', []))
                        ->schema([TextEntry::make('section')->hiddenLabel()->badge()->color('warning')]),
                    TextEntry::make($prefix.'no_truncation')
                        ->hiddenLabel()
                        ->state('没有被裁剪的 Context。')
                        ->color('success')
                        ->visible(fn ($record): bool => data_get($inspect($record), 'truncated_sections', []) === []),
                ]),
            Section::make('已选择记忆')
                ->description('这些长期记忆进入了本次冻结 Context，用于解释模型为何知道相关历史。')
                ->schema([
                    RepeatableEntry::make($prefix.'selected_memories')
                        ->hiddenLabel()
                        ->state(fn ($record): array => data_get($inspect($record), 'selected_memories', []))
                        ->columns(4)
                        ->schema([
                            TextEntry::make('summary')->label('记忆摘要')->columnSpan(2)->wrap(),
                            TextEntry::make('type')->label('类型')->badge(),
                            TextEntry::make('status')->label('当前状态')->badge(),
                            TextEntry::make('source')->label('来源'),
                            TextEntry::make('source_chapter')->label('来源章节')->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : '第 '.(int) $state.' 章'),
                            TextEntry::make('final_score')->label('最终评分')->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : number_format((float) $state, 4)),
                        ]),
                    TextEntry::make($prefix.'no_selected_memory')
                        ->hiddenLabel()
                        ->state('本次 Context 没有选择长期记忆。')
                        ->color('gray')
                        ->visible(fn ($record): bool => data_get($inspect($record), 'selected_memories', []) === []),
                ]),
        ];
    }

    /** @return array<int, Section> */
    private static function layerSections(Closure $inspect, Closure $json, string $prefix): array
    {
        $layers = [
            'l0' => ['L0 · 硬约束', 'Bible、Locked Facts、世界规则与计划硬约束，权威级别最高。'],
            'l1' => ['L1 · 当前状态', '来自不可变 Canonical Story State Version。'],
            'l2' => ['L2 · 近期故事', '来自近期正式章节、事件与上一章结尾，不依赖向量检索。'],
            'l3' => ['L3 · 长期记忆', '通过 pgvector 检索、排序、去重和 Token Budget 后选入。'],
            'l4' => ['L4 · Style Contract', '来自指定 Bible Version 的冻结文风契约；POV、时态和硬性边界不可裁剪。'],
        ];

        return collect($layers)->map(function (array $labels, string $layer) use ($inspect, $json, $prefix): Section {
            return Section::make($labels[0])
                ->description($labels[1])
                ->schema([
                    TextEntry::make($prefix.$layer)
                        ->hiddenLabel()
                        ->state(fn ($record): string => $json(data_get($inspect($record), $layer)))
                        ->fontFamily('mono')
                        ->copyable(),
                ]);
        })->values()->all();
    }
}
