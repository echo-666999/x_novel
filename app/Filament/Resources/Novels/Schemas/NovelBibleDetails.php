<?php

namespace App\Filament\Resources\Novels\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NovelBibleDetails
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('当前版本')
                ->description('所有重大设定变更都通过新版本保存，当前版本是后续规划与生成的约束来源。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 4,
                ])
                ->schema([
                    TextEntry::make('currentBible.version')
                        ->label('版本')
                        ->formatStateUsing(fn (int $state): string => "v{$state}")
                        ->placeholder('尚未创建'),
                    TextEntry::make('currentBible.status')
                        ->label('状态')
                        ->badge()
                        ->placeholder('尚未创建'),
                    TextEntry::make('currentBible.created_at')
                        ->label('创建时间')
                        ->dateTime('Y-m-d H:i')
                        ->placeholder('尚未创建'),
                    TextEntry::make('currentBible.logline')
                        ->label('一句话梗概')
                        ->placeholder('尚未创建小说圣经')
                        ->columnSpanFull(),
                ]),
            Section::make('叙事基线')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 3,
                ])
                ->schema([
                    TextEntry::make('currentBible.tone')
                        ->label('基调')
                        ->placeholder('—'),
                    TextEntry::make('currentBible.pov')
                        ->label('视角')
                        ->placeholder('—'),
                    TextEntry::make('currentBible.tense')
                        ->label('时态')
                        ->placeholder('—'),
                    TextEntry::make('currentBible.themes')
                        ->label('主题')
                        ->badge()
                        ->separator(',')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
            Section::make('硬约束')
                ->description('这些约束优先于模型生成结果，冲突内容不得进入正式故事。')
                ->columns([
                    'default' => 1,
                    'lg' => 2,
                ])
                ->schema([
                    TextEntry::make('currentBible.taboos')
                        ->label('禁区')
                        ->bulleted()
                        ->placeholder('—'),
                    TextEntry::make('currentBible.hard_constraints')
                        ->label('硬约束')
                        ->bulleted()
                        ->placeholder('—'),
                ]),
            Section::make('结局契约')
                ->columns([
                    'default' => 1,
                    'lg' => 2,
                ])
                ->schema([
                    TextEntry::make('currentBible.ending_contract.final_protagonist_state')
                        ->label('主角最终状态')
                        ->placeholder('—'),
                    TextEntry::make('currentBible.ending_contract.main_conflict_resolution')
                        ->label('主冲突解决方式')
                        ->placeholder('—'),
                    TextEntry::make('currentBible.ending_contract.theme_payoff')
                        ->label('主题兑现')
                        ->placeholder('—'),
                    TextEntry::make('currentBible.ending_contract.allowed_open_endings')
                        ->label('允许保留的开放结局')
                        ->bulleted()
                        ->placeholder('—'),
                    TextEntry::make('currentBible.ending_contract.required_foreshadowing_payoff')
                        ->label('必须回收的伏笔')
                        ->bulleted()
                        ->placeholder('—'),
                    TextEntry::make('currentBible.ending_contract.character_arc_requirements')
                        ->label('人物弧要求')
                        ->bulleted()
                        ->placeholder('—'),
                ]),
            Section::make('版本历史')
                ->description('历史版本只读保留，可用于追溯设定变化。')
                ->schema([
                    RepeatableEntry::make('bibles')
                        ->label('')
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 4,
                        ])
                        ->schema([
                            TextEntry::make('version')
                                ->label('版本')
                                ->formatStateUsing(fn (int $state): string => "v{$state}"),
                            TextEntry::make('status')
                                ->label('状态')
                                ->badge(),
                            TextEntry::make('created_at')
                                ->label('创建时间')
                                ->dateTime('Y-m-d H:i'),
                            TextEntry::make('logline')
                                ->label('一句话梗概')
                                ->columnSpanFull(),
                            TextEntry::make('themes')
                                ->label('主题')
                                ->badge()
                                ->separator(','),
                            TextEntry::make('tone')->label('基调'),
                            TextEntry::make('pov')->label('视角'),
                            TextEntry::make('tense')->label('时态'),
                            TextEntry::make('taboos')
                                ->label('禁区')
                                ->bulleted(),
                            TextEntry::make('hard_constraints')
                                ->label('硬约束')
                                ->bulleted(),
                            TextEntry::make('ending_contract.final_protagonist_state')
                                ->label('主角最终状态')
                                ->placeholder('—'),
                            TextEntry::make('ending_contract.main_conflict_resolution')
                                ->label('主冲突解决方式')
                                ->placeholder('—'),
                            TextEntry::make('ending_contract.theme_payoff')
                                ->label('主题兑现')
                                ->placeholder('—'),
                            TextEntry::make('ending_contract.allowed_open_endings')
                                ->label('允许保留的开放结局')
                                ->bulleted()
                                ->placeholder('—'),
                            TextEntry::make('ending_contract.required_foreshadowing_payoff')
                                ->label('必须回收的伏笔')
                                ->bulleted()
                                ->placeholder('—'),
                            TextEntry::make('ending_contract.character_arc_requirements')
                                ->label('人物弧要求')
                                ->bulleted()
                                ->placeholder('—'),
                        ]),
                ]),
        ]);
    }
}
