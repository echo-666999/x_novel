<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Novels\CreateBibleVersionAction;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Schemas\NovelBibleDetails;
use App\Models\Novel;
use App\Models\NovelBible;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ManageNovelBible extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = '小说圣经';

    public function getTitle(): string
    {
        return '小说圣经';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    public function infolist(Schema $schema): Schema
    {
        return NovelBibleDetails::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createBibleVersion')
                ->label(fn (): string => $this->getRecord()->currentBible === null ? '创建首个版本' : '创建新版本')
                ->icon('heroicon-o-document-plus')
                ->modalHeading(fn (): string => $this->getRecord()->currentBible === null ? '创建小说圣经' : '创建小说圣经新版本')
                ->modalDescription('保存后会生成新的只读版本，不会覆盖已有内容。')
                ->modalWidth('5xl')
                ->fillForm(fn (): array => $this->currentBibleFormData())
                ->schema([
                    Section::make('核心定位')
                        ->columns([
                            'default' => 1,
                            'md' => 3,
                        ])
                        ->schema([
                            Textarea::make('logline')
                                ->label('一句话梗概')
                                ->required()
                                ->rows(3)
                                ->columnSpanFull(),
                            TagsInput::make('themes')
                                ->label('主题')
                                ->required()
                                ->columnSpanFull(),
                            TextInput::make('tone')
                                ->label('基调')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('pov')
                                ->label('视角')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('tense')
                                ->label('时态')
                                ->required()
                                ->maxLength(255),
                        ]),
                    Section::make('写作约束')
                        ->columns([
                            'default' => 1,
                            'lg' => 2,
                        ])
                        ->schema([
                            TagsInput::make('taboos')
                                ->label('禁区')
                                ->helperText('不得在正文中出现的处理方式或内容。'),
                            TagsInput::make('hard_constraints')
                                ->label('硬约束')
                                ->helperText('模型输出不得违反的正式规则。'),
                        ]),
                    Section::make('结局契约')
                        ->statePath('ending_contract')
                        ->columns([
                            'default' => 1,
                            'lg' => 2,
                        ])
                        ->schema([
                            Textarea::make('final_protagonist_state')
                                ->label('主角最终状态')
                                ->required()
                                ->rows(3),
                            Textarea::make('main_conflict_resolution')
                                ->label('主冲突解决方式')
                                ->required()
                                ->rows(3),
                            Textarea::make('theme_payoff')
                                ->label('主题兑现')
                                ->required()
                                ->rows(3),
                            TagsInput::make('allowed_open_endings')
                                ->label('允许保留的开放结局')
                                ->required()
                                ->helperText('每项填写一个允许保留到结局之后的问题。'),
                            TagsInput::make('required_foreshadowing_payoff')
                                ->label('必须回收的伏笔')
                                ->required(),
                            TagsInput::make('character_arc_requirements')
                                ->label('人物弧要求')
                                ->required(),
                        ]),
                ])
                ->action(function (array $data, CreateBibleVersionAction $createBibleVersion): void {
                    $createBibleVersion->execute($this->getRecord(), $data);
                    $this->getRecord()->refresh()->load(['currentBible', 'bibles']);

                    Notification::make()
                        ->title('小说圣经新版本已创建')
                        ->success()
                        ->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    private function currentBibleFormData(): array
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();
        /** @var NovelBible|null $bible */
        $bible = $novel->currentBible;

        if ($bible === null) {
            return [
                'themes' => [],
                'taboos' => [],
                'hard_constraints' => [],
                'ending_contract' => [],
            ];
        }

        $data = $bible->only([
            'logline',
            'themes',
            'tone',
            'pov',
            'tense',
            'taboos',
            'hard_constraints',
            'ending_contract',
        ]);

        foreach (['required_foreshadowing_payoff', 'character_arc_requirements', 'allowed_open_endings'] as $key) {
            $value = data_get($data, "ending_contract.{$key}", []);
            data_set($data, "ending_contract.{$key}", is_array($value) ? $value : array_values(array_filter([$value])));
        }

        return $data;
    }
}
