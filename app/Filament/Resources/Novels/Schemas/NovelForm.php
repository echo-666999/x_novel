<?php

namespace App\Filament\Resources\Novels\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NovelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基础信息')
                    ->description('维护小说的核心定位与生成目标。')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('标题')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('genre')
                            ->label('题材')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('target_words')
                            ->label('目标字数')
                            ->helperText('必须大于 0。')
                            ->integer()
                            ->minValue(1)
                            ->default(1_000_000)
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('premise')
                            ->label('故事前提')
                            ->rows(5)
                            ->maxLength(10_000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
