<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\CharacterStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Character;
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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ManageNovelCharacters extends ManageRelatedRecords
{
    protected static string $resource = NovelResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string $relationship = 'characters';

    protected static ?string $navigationLabel = '角色';

    protected static ?string $relationshipTitle = '角色';

    public function getTitle(): string
    {
        return '角色管理';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('身份')
                ->description('用于识别人物及其在故事中的定位。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ])
                ->schema([
                    TextInput::make('name')
                        ->label('姓名')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('role')
                        ->label('角色定位')
                        ->placeholder('例如：主角、反派、导师')
                        ->required()
                        ->maxLength(255),
                    TagsInput::make('aliases')
                        ->label('别名')
                        ->placeholder('输入后按回车')
                        ->columnSpanFull(),
                    Select::make('status')
                        ->label('状态')
                        ->options(CharacterStatus::class)
                        ->default(CharacterStatus::Active)
                        ->required(),
                ]),
            Section::make('人物档案')
                ->description('静态设定与长期驱动力。键名应稳定，便于后续上下文读取。')
                ->schema([
                    KeyValue::make('profile')
                        ->label('档案')
                        ->keyLabel('属性')
                        ->valueLabel('内容')
                        ->addActionLabel('添加档案项')
                        ->default([]),
                    Textarea::make('motivation')
                        ->label('核心动机')
                        ->rows(3)
                        ->required(),
                    KeyValue::make('personality')
                        ->label('性格')
                        ->keyLabel('特质')
                        ->valueLabel('表现 / 说明')
                        ->addActionLabel('添加性格项')
                        ->default([]),
                ]),
            Section::make('能力与知识')
                ->description('记录明确能力与知识边界；避免用长篇自由文本混合不同事实。')
                ->columns([
                    'default' => 1,
                    'xl' => 2,
                ])
                ->schema([
                    KeyValue::make('abilities')
                        ->label('能力')
                        ->keyLabel('能力')
                        ->valueLabel('程度 / 限制')
                        ->addActionLabel('添加能力')
                        ->default([]),
                    KeyValue::make('knowledge')
                        ->label('知识')
                        ->keyLabel('信息')
                        ->valueLabel('掌握程度 / 来源')
                        ->addActionLabel('添加知识')
                        ->default([]),
                ]),
            Section::make('当前状态与锁定')
                ->description('当前状态是轻量投影；锁定字段使用稳定路径，例如 profile.age。')
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
                    ->label('姓名')
                    ->weight('medium')
                    ->description(fn (Character $record): string => collect($record->aliases)->join('、'))
                    ->searchable(['name', 'role'])
                    ->sortable(),
                TextColumn::make('role')
                    ->label('角色定位')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->sortable(),
                TextColumn::make('current_location')
                    ->label('当前位置')
                    ->state(fn (Character $record): string => $this->currentLocation($record))
                    ->placeholder('未设置'),
                TextColumn::make('important_flags')
                    ->label('重要标记')
                    ->state(fn (Character $record): array => $this->importantFlags($record))
                    ->badge()
                    ->color('warning')
                    ->separator(','),
                TextColumn::make('updated_at')
                    ->label('更新于')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状态')
                    ->options(CharacterStatus::class),
                SelectFilter::make('role')
                    ->label('角色定位')
                    ->options(fn (): array => $this->getRecord()->characters()
                        ->select('role')
                        ->distinct()
                        ->reorder('role')
                        ->pluck('role', 'role')
                        ->all()),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordAction('view')
            ->emptyStateHeading('尚未建立角色')
            ->emptyStateDescription('创建主要人物，记录身份、动机、能力与当前状态。')
            ->emptyStateIcon('heroicon-o-users')
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
                ->label('创建角色')
                ->icon('heroicon-o-plus')
                ->slideOver(),
        ];
    }

    private function currentLocation(Character $character): string
    {
        $location = data_get($character->current_state, 'location');

        return is_scalar($location) && filled((string) $location) ? (string) $location : '未设置';
    }

    /** @return array<string> */
    private function importantFlags(Character $character): array
    {
        $flags = collect(data_get($character->current_state, 'flags', []))
            ->filter(fn ($value): bool => $value === true || $value === 1 || $value === 'true')
            ->keys()
            ->map(fn ($flag): string => (string) $flag);

        if (filled($character->locked_fields)) {
            $flags->push('锁定 '.count($character->locked_fields).' 项');
        }

        return $flags->values()->all();
    }
}
