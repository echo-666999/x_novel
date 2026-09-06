<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Fact;
use App\Models\Novel;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

class ViewNovelStoryState extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = NovelResource::class;

    protected static ?string $navigationLabel = '故事状态';

    /** @var array<string, string> */
    private const DOMAINS = [
        'characters' => '人物',
        'relationships' => '关系',
        'locations' => '地点',
        'items' => '物品',
        'world' => '世界',
        'timeline' => '时间线',
        'open_threads' => '开放线索',
        'foreshadowings' => '伏笔',
        'reader_promises' => '读者承诺',
        'facts' => '事实',
    ];

    #[Url(as: 'version')]
    public ?int $selectedVersion = null;

    public string $activeDomain = 'characters';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->selectedVersion = $this->resolveSelectedVersion($this->selectedVersion);
    }

    public function getTitle(): string
    {
        return '故事状态检查器';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.novels.pages.story-state-inspector')
                ->viewData(fn (): array => $this->inspectorData()),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();

        return $table
            ->query($novel->facts()->getQuery())
            ->columns([
                TextColumn::make('subject_type')
                    ->label('主体')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'character' => '人物',
                        'world_entity' => '世界实体',
                        'novel' => '小说',
                        default => $state,
                    })
                    ->description(fn (Fact $record): string => '#'.$record->subject_id)
                    ->searchable(['subject_type', 'subject_id']),
                TextColumn::make('predicate')
                    ->label('谓词')
                    ->fontFamily('mono')
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('value')
                    ->label('值')
                    ->state(fn (Fact $record): string => $record->valueSummary())
                    ->fontFamily('mono')
                    ->limit(48)
                    ->tooltip(fn (Fact $record): string => $record->valueSummary()),
                TextColumn::make('hardness')
                    ->label('硬度')
                    ->badge()
                    ->sortable(),
                TextColumn::make('source_type')
                    ->label('来源')
                    ->badge()
                    ->sortable(),
                IconColumn::make('locked')
                    ->label('锁定')
                    ->boolean()
                    ->trueIcon('heroicon-s-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('locked')
                    ->label('锁定状态')
                    ->placeholder('全部')
                    ->trueLabel('已锁定')
                    ->falseLabel('未锁定'),
                SelectFilter::make('status')
                    ->label('有效状态')
                    ->options(FactStatus::class),
                SelectFilter::make('source_type')
                    ->label('来源')
                    ->options(FactSourceType::class),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordAction(null)
            ->emptyStateHeading('尚未记录事实')
            ->emptyStateDescription('正式故事中的可查询事实会显示在这里。')
            ->emptyStateIcon('heroicon-o-check-badge');
    }

    public function selectVersion(int $version): void
    {
        $this->selectedVersion = $this->resolveSelectedVersion($version);
    }

    public function selectDomain(string $domain): void
    {
        if (array_key_exists($domain, self::DOMAINS)) {
            $this->activeDomain = $domain;
        }
    }

    /** @return array<string, mixed> */
    private function inspectorData(): array
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();
        $versions = $novel->storyStateVersions()
            ->orderByDesc('version')
            ->get();
        $stateVersion = $versions->firstWhere('version', $this->selectedVersion);
        $activeDomain = array_key_exists($this->activeDomain, self::DOMAINS)
            ? $this->activeDomain
            : 'characters';

        return [
            'novel' => $novel,
            'versions' => $versions,
            'stateVersion' => $stateVersion,
            'domains' => self::DOMAINS,
            'activeDomain' => $activeDomain,
            'domainState' => $activeDomain === 'facts'
                ? []
                : ($stateVersion?->state[$activeDomain] ?? []),
            'isCurrent' => $stateVersion?->is($novel->canonicalStateVersion) ?? false,
        ];
    }

    private function resolveSelectedVersion(?int $requestedVersion): ?int
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();

        if ($requestedVersion !== null && $novel->storyStateVersions()->where('version', $requestedVersion)->exists()) {
            return $requestedVersion;
        }

        return $novel->canonicalStateVersion?->version;
    }
}
