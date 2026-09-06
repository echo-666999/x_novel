<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\Models\Novel;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NovelOverview
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('小说概览')
                ->description('当前小说的核心定位与正式状态。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 4,
                ])
                ->schema([
                    TextEntry::make('title')
                        ->label('标题')
                        ->weight('medium'),
                    TextEntry::make('genre')
                        ->label('题材'),
                    TextEntry::make('status')
                        ->label('状态')
                        ->badge(),
                    TextEntry::make('updated_at')
                        ->label('更新时间')
                        ->dateTime('Y-m-d H:i'),
                    TextEntry::make('premise')
                        ->label('故事前提')
                        ->placeholder('尚未填写故事前提')
                        ->columnSpanFull(),
                ]),
            Section::make('进度与状态')
                ->description('尚未接入的数据会明确标记，不推测正式故事状态。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 3,
                ])
                ->schema([
                    TextEntry::make('target_words')
                        ->label('目标字数')
                        ->numeric(),
                    TextEntry::make('current_words')
                        ->label('当前字数')
                        ->state(0)
                        ->numeric(),
                    TextEntry::make('current_volume')
                        ->label('当前卷')
                        ->state('尚未接入')
                        ->color('gray'),
                    TextEntry::make('current_chapter_sequence')
                        ->label('当前章节')
                        ->placeholder('尚无正式章节')
                        ->formatStateUsing(fn (?int $state): string => $state === null ? '尚无正式章节' : "第 {$state} 章"),
                    TextEntry::make('current_state_version')
                        ->label('当前 State Version')
                        ->state('尚未接入')
                        ->color('gray'),
                    TextEntry::make('generation_status')
                        ->label('生成状态')
                        ->state(fn (Novel $record): string => match ($record->status->value) {
                            'generating' => '生成中',
                            'paused' => '已暂停',
                            'failed' => '生成失败',
                            default => '尚未运行',
                        })
                        ->badge()
                        ->color(fn (Novel $record): string => match ($record->status->value) {
                            'generating' => 'info',
                            'failed' => 'danger',
                            default => 'gray',
                        }),
                ]),
            Section::make('质量与运营')
                ->description('后续任务接入 Review、Generation、Usage 与 Foreshadowing 数据后自动填充。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 5,
                ])
                ->schema([
                    TextEntry::make('today_cost')
                        ->label('今日成本')
                        ->state('¥0.00'),
                    TextEntry::make('review_pass_rate')
                        ->label('Review 通过率')
                        ->state('尚未接入')
                        ->color('gray'),
                    TextEntry::make('rewrite_rate')
                        ->label('Rewrite 比例')
                        ->state('尚未接入')
                        ->color('gray'),
                    TextEntry::make('due_foreshadowings')
                        ->label('待处理伏笔')
                        ->state(0),
                    TextEntry::make('needs_attention')
                        ->label('需要处理')
                        ->state(0),
                ]),
        ]);
    }
}
