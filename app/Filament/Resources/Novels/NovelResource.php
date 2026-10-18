<?php

namespace App\Filament\Resources\Novels;

use App\Filament\Pages\GenerationIssues;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\EditNovel;
use App\Filament\Resources\Novels\Pages\ListNovels;
use App\Filament\Resources\Novels\Pages\ManageNovelBible;
use App\Filament\Resources\Novels\Pages\ManageNovelChapters;
use App\Filament\Resources\Novels\Pages\ManageNovelCharacters;
use App\Filament\Resources\Novels\Pages\ManageNovelForeshadowings;
use App\Filament\Resources\Novels\Pages\ManageNovelOutline;
use App\Filament\Resources\Novels\Pages\ManageNovelStoryArcs;
use App\Filament\Resources\Novels\Pages\ManageNovelVolumes;
use App\Filament\Resources\Novels\Pages\ManageNovelWorld;
use App\Filament\Resources\Novels\Pages\ViewBookChapter;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Filament\Resources\Novels\Pages\ViewNovelEndingAudit;
use App\Filament\Resources\Novels\Pages\ViewNovelPlanning;
use App\Filament\Resources\Novels\Pages\ViewNovelPlanningPreview;
use App\Filament\Resources\Novels\Pages\ViewNovelStoryState;
use App\Filament\Resources\Novels\Pages\ViewNovelTools;
use App\Filament\Resources\Novels\Schemas\NovelForm;
use App\Filament\Resources\Novels\Schemas\NovelOverview;
use App\Filament\Resources\Novels\Tables\NovelsTable;
use App\Models\Novel;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\Page;
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

    public static function infolist(Schema $schema): Schema
    {
        return NovelOverview::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /** @return array<NavigationItem> */
    public static function getRecordSubNavigation(Page $page): array
    {
        if ($page->getRecord()?->current_generation_task_id) {
            $items = $page->generateNavigationItems([ViewNovel::class, ManageNovelChapters::class, ViewNovelPlanning::class]);
            foreach ($items as $index => $item) {
                $item->label(['工作台', '正文', '设定与计划'][$index]);
            }
            // 子页面也归入实际业务入口，否则窄屏菜单找不到当前项而显示无名称按钮。
            $items[0]->isActiveWhen(fn (): bool => $page instanceof ViewNovel || $page instanceof EditNovel);
            $items[1]->isActiveWhen(fn (): bool => $page instanceof ManageNovelChapters || $page instanceof ViewBookChapter);
            $items[2]->isActiveWhen(fn (): bool => $page instanceof ViewNovelPlanning || $page instanceof ViewNovelPlanningPreview);
            $items[] = NavigationItem::make('异常')->icon('heroicon-o-exclamation-circle')->url(GenerationIssues::getUrl(['novel' => $page->getRecord()->id]));

            $tools = $page->generateNavigationItems([ViewNovelTools::class]);
            $tools[0]->isActiveWhen(fn (): bool => $page instanceof ViewNovelTools
                || $page instanceof ViewNovelStoryState || $page instanceof ViewNovelEndingAudit
                || $page instanceof ManageNovelCharacters || $page instanceof ManageNovelWorld
                || $page instanceof ManageNovelForeshadowings);

            return [...$items, ...$tools];
        }

        return $page->generateNavigationItems([
            ViewNovel::class,
            ManageNovelOutline::class,
            ManageNovelBible::class,
            ManageNovelCharacters::class,
            ManageNovelWorld::class,
            ManageNovelForeshadowings::class,
            ViewNovelStoryState::class,
            ViewNovelPlanning::class,
            ManageNovelChapters::class,
            ManageNovelVolumes::class,
            ManageNovelStoryArcs::class,
            ViewNovelEndingAudit::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNovels::route('/'),
            'create' => CreateNovel::route('/create'),
            'view' => ViewNovel::route('/{record}'),
            'outline' => ManageNovelOutline::route('/{record}/outline'),
            'bible' => ManageNovelBible::route('/{record}/bible'),
            'characters' => ManageNovelCharacters::route('/{record}/characters'),
            'world' => ManageNovelWorld::route('/{record}/world'),
            'foreshadowings' => ManageNovelForeshadowings::route('/{record}/foreshadowings'),
            'story-state' => ViewNovelStoryState::route('/{record}/story-state'),
            'planning' => ViewNovelPlanning::route('/{record}/planning'),
            'planning-preview' => ViewNovelPlanningPreview::route('/{record}/planning-preview/{chapter}'),
            'chapters' => ManageNovelChapters::route('/{record}/chapters'),
            'book-chapter' => ViewBookChapter::route('/{record}/book-chapters/{chapter}'),
            'chapter' => ViewNovelChapter::route('/{record}/chapters/{chapter}'),
            'volumes' => ManageNovelVolumes::route('/{record}/planning/volumes'),
            'story-arcs' => ManageNovelStoryArcs::route('/{record}/planning/story-arcs'),
            'tools' => ViewNovelTools::route('/{record}/tools'),
            'ending-audit' => ViewNovelEndingAudit::route('/{record}/ending-audit'),
            'edit' => EditNovel::route('/{record}/edit'),
        ];
    }
}
