<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Novels\CreateBibleVersionAction;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Schemas\NovelBibleDetails;
use App\Models\Novel;
use App\Models\NovelBible;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
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
                    Section::make('作品定位')
                        ->columns([
                            'default' => 1,
                            'md' => 2,
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
                            Select::make('style_profile.subgenre')
                                ->label('子题材')
                                ->options(fn (Select $component): array => $this->narrativeValueOptions('subgenres', 'style_profile.subgenre', $component->getState()))
                                ->searchable()
                                ->placeholder('请选择子题材')
                                ->createOptionForm(self::customValueForm('子题材', 100))
                                ->createOptionModalHeading('添加自定义子题材')
                                ->createOptionUsing(fn (array $data): string => trim($data['value']))
                                ->validationMessages([
                                    'max' => '子题材不能超过 100 个字符。',
                                ]),
                            Select::make('style_profile.target_platform')
                                ->label('目标平台')
                                ->options(config('narrative.platforms'))
                                ->required()
                                ->validationMessages([
                                    'required' => '请选择目标平台。',
                                    'in' => '目标平台不是受支持的选项。',
                                ]),
                        ]),
                    Section::make('叙事与文风基线')
                        ->description('基调、视角、时态与文风设置会随 Bible Version 一起冻结。')
                        ->columns([
                            'default' => 1,
                            'md' => 3,
                        ])
                        ->schema([
                            Select::make('tone')
                                ->label('基调')
                                ->options(fn (Select $component): array => $this->narrativeValueOptions('tones', 'tone', $component->getState()))
                                ->searchable()
                                ->required()
                                ->createOptionForm(self::customValueForm('基调'))
                                ->createOptionModalHeading('添加自定义基调')
                                ->createOptionUsing(fn (array $data): string => trim($data['value'])),
                            Select::make('pov')
                                ->label('视角')
                                ->options(fn (Select $component): array => $this->narrativeValueOptions('povs', 'pov', $component->getState()))
                                ->searchable()
                                ->required()
                                ->createOptionForm(self::customValueForm('视角'))
                                ->createOptionModalHeading('添加自定义视角')
                                ->createOptionUsing(fn (array $data): string => trim($data['value'])),
                            Select::make('tense')
                                ->label('时态')
                                ->options(fn (Select $component): array => $this->narrativeValueOptions('tenses', 'tense', $component->getState()))
                                ->searchable()
                                ->required()
                                ->createOptionForm(self::customValueForm('时态'))
                                ->createOptionModalHeading('添加自定义时态')
                                ->createOptionUsing(fn (array $data): string => trim($data['value'])),
                            Select::make('style_profile.primary_style')
                                ->label('主文风')
                                ->options(self::styleOptions())
                                ->required()
                                ->searchable()
                                ->validationMessages([
                                    'required' => '请选择主文风。',
                                    'in' => '主文风不是受支持的选项。',
                                ]),
                            Select::make('style_profile.language_era')
                                ->label('语言时代感')
                                ->options(config('narrative.language_eras'))
                                ->required()
                                ->validationMessages([
                                    'required' => '请选择语言时代感。',
                                    'in' => '语言时代感不是受支持的选项。',
                                ]),
                            Select::make('style_profile.pacing')
                                ->label('故事节奏')
                                ->options(config('narrative.paces'))
                                ->required()
                                ->validationMessages([
                                    'required' => '请选择故事节奏。',
                                    'in' => '故事节奏不是受支持的选项。',
                                ]),
                            CheckboxList::make('style_profile.secondary_styles')
                                ->label('辅助文风')
                                ->options(self::styleOptions())
                                ->helperText('最多选择两种，且不能与主文风重复。')
                                ->maxItems(2)
                                ->columns([
                                    'default' => 2,
                                    'lg' => 3,
                                ])
                                ->columnSpanFull()
                                ->validationMessages([
                                    'max' => '辅助文风最多选择两项。',
                                ]),
                        ]),
                    Section::make('文风高级设置')
                        ->description('六项参数必须明确设置；1 表示最低，5 表示最高。')
                        ->columns([
                            'default' => 1,
                            'md' => 3,
                        ])
                        ->schema(self::styleParameterFields()),
                    Section::make('写作边界')
                        ->description('这些内容会约束规划和正文生成。')
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
                'style_profile' => self::defaultStyleProfile(),
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
            'style_profile',
        ]);

        if (! is_array($data['style_profile'] ?? null)) {
            $data['style_profile'] = self::defaultStyleProfile();
        }

        foreach (['required_foreshadowing_payoff', 'character_arc_requirements', 'allowed_open_endings'] as $key) {
            $value = data_get($data, "ending_contract.{$key}", []);
            data_set($data, "ending_contract.{$key}", is_array($value) ? $value : array_values(array_filter([$value])));
        }

        return $data;
    }

    /** @return array<string, string> */
    private static function styleOptions(): array
    {
        return collect(config('narrative.styles', []))
            ->mapWithKeys(fn (array $style, string $code): array => [$code => $style['name']])
            ->all();
    }

    /** @return array<string, string> */
    private function narrativeValueOptions(string $configKey, string $biblePath, mixed $selectedValue = null): array
    {
        $values = [
            ...array_values(config("narrative.{$configKey}", [])),
            ...$this->getRecord()->bibles
                ->map(fn (NovelBible $bible): mixed => data_get($bible, $biblePath))
                ->all(),
            $selectedValue,
        ];

        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && filled($value))
            ->mapWithKeys(fn (string $value): array => [$value => $value])
            ->all();
    }

    /** @return array<int, TextInput> */
    private static function customValueForm(string $label, int $maxLength = 255): array
    {
        return [
            TextInput::make('value')
                ->label($label)
                ->required()
                ->maxLength($maxLength),
        ];
    }

    /** @return array<int, Select> */
    private static function styleParameterFields(): array
    {
        return collect(self::styleParameterLabels())
            ->map(fn (string $label, string $key): Select => Select::make("style_profile.parameters.{$key}")
                ->label($label)
                ->options([1 => '1 / 5', 2 => '2 / 5', 3 => '3 / 5', 4 => '4 / 5', 5 => '5 / 5'])
                ->required()
                ->validationMessages([
                    'required' => "请选择{$label}。",
                    'in' => "{$label}必须是 1 至 5 的整数。",
                ]))
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private static function styleParameterLabels(): array
    {
        return [
            'ornateness' => '语言华丽度',
            'dialogue_ratio' => '对白占比',
            'description_density' => '环境描写密度',
            'psychology_density' => '心理描写密度',
            'humor_level' => '幽默程度',
            'literary_level' => '文学性',
        ];
    }

    /** @return array<string, mixed> */
    private static function defaultStyleProfile(): array
    {
        $primaryStyle = 'accessible_brisk';
        $parameterKeys = config('narrative.parameter_keys', []);
        $parameterValues = data_get(config('narrative.styles', []), "{$primaryStyle}.parameters", []);

        return [
            'subgenre' => null,
            'target_platform' => 'general',
            'primary_style' => $primaryStyle,
            'secondary_styles' => [],
            'language_era' => 'modern_spoken',
            'pacing' => 'balanced',
            'parameters' => collect($parameterKeys)
                ->mapWithKeys(fn (string $key, int $index): array => [$key => $parameterValues[$index]])
                ->all(),
        ];
    }
}
