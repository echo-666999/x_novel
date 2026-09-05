<?php

namespace App\Filament\Resources\Novels;

use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\EditNovel;
use App\Filament\Resources\Novels\Pages\ListNovels;
use App\Filament\Resources\Novels\Schemas\NovelForm;
use App\Filament\Resources\Novels\Tables\NovelsTable;
use App\Models\Novel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class NovelResource extends Resource
{
    protected static ?string $model = Novel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = '小说';

    protected static ?string $modelLabel = '小说';

    protected static ?string $pluralModelLabel = '小说';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $slug = 'novels';

    public static function form(Schema $schema): Schema
    {
        return NovelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NovelsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNovels::route('/'),
            'create' => CreateNovel::route('/create'),
            'edit' => EditNovel::route('/{record}/edit'),
        ];
    }
}
