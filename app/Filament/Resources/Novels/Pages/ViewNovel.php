<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewNovel extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static ?string $navigationLabel = '概览';

    public function getTitle(): string
    {
        return $this->getRecord()->title;
    }

    public function getSubheading(): ?string
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();

        return "{$novel->genre} · {$novel->status->getLabel()}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateNextChapter')
                ->label('生成下一章')
                ->icon('heroicon-o-play')
                ->disabled()
                ->tooltip('将在 TASK-061 中启用'),
            Action::make('autoGenerate')
                ->label('自动生成')
                ->icon('heroicon-o-bolt')
                ->color('gray')
                ->disabled()
                ->tooltip('将在 TASK-100 中启用'),
            Action::make('pause')
                ->label('暂停')
                ->icon('heroicon-o-pause')
                ->color('gray')
                ->disabled()
                ->tooltip('将在 TASK-101 中启用'),
            Action::make('resume')
                ->label('继续')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->disabled()
                ->tooltip('将在 TASK-102 中启用'),
            EditAction::make()
                ->label('编辑基础信息')
                ->color('gray'),
        ];
    }
}
