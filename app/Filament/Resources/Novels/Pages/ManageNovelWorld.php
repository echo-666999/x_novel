<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\WorldEntityStatus;
use App\Enums\WorldEntityType;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\WorldEntity;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageNovelWorld extends ManageRelatedRecords
{
    protected static string $resource = NovelResource::class;

    protected static string $relationship = 'worldEntities';

    protected static ?string $navigationLabel = '世界';

    protected static ?string $relationshipTitle = '世界';

    public function getTitle(): string
    {
        return '世界实体';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'locations' => Tab::make('地点')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', WorldEntityType::Location)),
            'items' => Tab::make('物品')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', WorldEntityType::Item)),
            'factions' => Tab::make('阵营与组织')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('type', [
                    WorldEntityType::Faction,
                    WorldEntityType::Organization,
                ])),
            'rules' => Tab::make('规则')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', WorldEntityType::Rule)),
            'other' => Tab::make('其他')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', WorldEntityType::Concept)),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('实体定义')
                ->description('地点、物品、阵营、组织、规则和概念统一在这里维护。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ])
                ->schema([
                    Select::make('type')
                        ->label('类型')
                        ->options(WorldEntityType::class)
                        ->default(fn (): WorldEntityType => $this->defaultType())
                        ->required(),
                    TextInput::make('name')
                        ->label('名称')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('描述')
                        ->rows(4)
                        ->required()
                        ->columnSpanFull(),
                    Select::make('status')
                        ->label('状态')
                        ->options(WorldEntityStatus::class)
                        ->default(WorldEntityStatus::Active)
                        ->required(),
                ]),
            Section::make('属性与规则')
                ->description('使用稳定键名记录结构化属性和约束，方便后续上下文与校验读取。')
                ->columns([
                    'default' => 1,
                    'xl' => 2,
                ])
                ->schema([
                    KeyValue::make('attributes')
                        ->label('属性')
                        ->keyLabel('属性')
                        ->valueLabel('内容')
                        ->addActionLabel('添加属性')
                        ->default([]),
                    KeyValue::make('rules')
                        ->label('规则')
                        ->keyLabel('规则')
                        ->valueLabel('约束 / 说明')
                        ->addActionLabel('添加规则')
                        ->default([]),
                ]),
            Section::make('当前状态与锁定')
                ->description('当前状态是轻量投影；锁定字段使用稳定路径，例如 rules.access。')
                ->schema([
                    KeyValue::make('current_state')
                        ->label('当前状态')
                        ->keyLabel('状态字段')
                        ->valueLabel('当前值')
                        ->addActionLabel('添加状态')
                        ->default([]),
                    TagsInput::make('locked_fields')
                        ->label('锁定字段')
                        ->placeholder('输入字段路径后按回车')
                        ->helperText('锁定标记供后续校验使用，不会在本页面触发 Canonical State 修改。'),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('名称')
                    ->weight('medium')
                    ->description(fn (WorldEntity $record): string => (string) str($record->description)->limit(48))
                    ->searchable(['name', 'description'])
                    ->sortable(),
                TextColumn::make('type')
                    ->label('类型')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->sortable(),
                TextColumn::make('state_summary')
                    ->label('当前状态')
                    ->state(fn (WorldEntity $record): string => $this->stateSummary($record))
                    ->wrap(),
                TextColumn::make('locked_fields')
                    ->label('锁定')
                    ->state(fn (WorldEntity $record): string => count($record->locked_fields ?? []) > 0
                        ? '锁定 '.count($record->locked_fields).' 项'
                        : '无')
                    ->badge()
                    ->color(fn (string $state): string => $state === '无' ? 'gray' : 'warning'),
                TextColumn::make('updated_at')
                    ->label('更新于')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('类型')
                    ->options(WorldEntityType::class),
                SelectFilter::make('status')
                    ->label('状态')
                    ->options(WorldEntityStatus::class),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordAction('view')
            ->emptyStateHeading('当前分类尚无世界实体')
            ->emptyStateDescription('创建世界实体，集中维护描述、属性、规则与当前状态。')
            ->emptyStateIcon('heroicon-o-globe-alt')
            ->recordActions([
                ViewAction::make()
                    ->label('查看')
                    ->slideOver(),
                EditAction::make()
                    ->label('编辑')
                    ->slideOver(),
                DeleteAction::make()->label('删除'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('创建世界实体')
                ->icon('heroicon-o-plus')
                ->slideOver(),
        ];
    }

    private function stateSummary(WorldEntity $entity): string
    {
        if (blank($entity->current_state)) {
            return '未设置';
        }

        return collect($entity->current_state)
            ->take(2)
            ->map(fn ($value, $key): string => $key.': '.(is_scalar($value) ? (string) $value : '结构化数据'))
            ->join(' · ');
    }

    private function defaultType(): WorldEntityType
    {
        return match ($this->activeTab) {
            'items' => WorldEntityType::Item,
            'factions' => WorldEntityType::Faction,
            'rules' => WorldEntityType::Rule,
            'other' => WorldEntityType::Concept,
            default => WorldEntityType::Location,
        };
    }
}
