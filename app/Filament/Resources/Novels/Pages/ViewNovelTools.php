<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/** 把低频检查集中在一个入口，普通创作不必理解所有技术表。 */
class ViewNovelTools extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static ?string $navigationLabel = '更多';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ellipsis-horizontal';

    public function getTitle(): string
    {
        return '更多检查与设置';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.components.book-tools')->viewData(['novel' => $this->getRecord()])]);
    }
}
