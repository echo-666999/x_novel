<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\AI\AiSettingsResolver;
use App\Enums\AiStage;
use App\Models\Novel;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
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
                            ->label('小说目标总字数')
                            ->helperText('整部小说的预计总字数，必须大于 0。')
                            ->integer()
                            ->minValue(1)
                            ->default(1_000_000)
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('generation_chapter_target_words')
                            ->label('单章目标字数')
                            ->helperText('用于章节规划并按场景分配写作字数；建议网络小说设置为 2,000～5,000 字。')
                            ->integer()
                            ->minValue(500)
                            ->maxValue(20_000)
                            ->default(3_000)
                            ->required(),
                        Textarea::make('premise')
                            ->label('故事前提')
                            ->rows(5)
                            ->maxLength(10_000)
                            ->columnSpanFull(),
                    ]),
                Section::make('创作风格')
                    ->description('题材、基调、文风、时代语言、节奏与叙事视角分别控制，生成时统一展开为 Style Profile。')
                    ->icon('heroicon-o-pencil-square')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('editorial_subgenre')->label('子题材')->maxLength(100)->placeholder('例如：汉朝、刑侦、民俗灵异'),
                        Select::make('editorial_target_platform')->label('目标平台')->options(config('narrative.platforms'))->default('general')->required(),
                        Select::make('editorial_story_tone')->label('故事基调')->options(config('narrative.tones'))->default('serious')->required(),
                        Select::make('editorial_primary_style')->label('主文风')->options(self::styleOptions())->default('accessible_brisk')->required()->searchable(),
                        CheckboxList::make('editorial_secondary_styles')->label('辅助文风')->options(self::styleOptions())->helperText('最多选择两种；与主文风重复的选项会在生成时忽略。')->maxItems(2)->columns(['default' => 2, 'lg' => 3])->columnSpanFull(),
                        Select::make('editorial_language_era')->label('语言时代感')->options(config('narrative.language_eras'))->default('modern_spoken')->required(),
                        Select::make('editorial_pacing')->label('故事节奏')->options(config('narrative.paces'))->default('balanced')->required(),
                        Select::make('editorial_narrative_pov')->label('叙事视角')->options(config('narrative.povs'))->default('third_limited')->required(),
                    ]),

                Grid::make(['default' => 1, 'lg' => 2])
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(1)->schema([
                            Section::make('文风高级设置')
                                ->description('留空时继承主文风 Preset；1 表示最低，5 表示最高。')
                                ->collapsed()
                                ->collapsible()
                                ->columns(['default' => 1, 'md' => 3])
                                ->schema(collect([
                                    'ornateness' => '语言华丽度', 'dialogue_ratio' => '对白占比', 'description_density' => '环境描写',
                                    'psychology_density' => '心理描写', 'humor_level' => '幽默程度', 'literary_level' => '文学性',
                                ])->map(fn (string $label, string $key): Select => Select::make("editorial_style_parameters.{$key}")
                                    ->label($label)->options([1 => '1 / 5', 2 => '2 / 5', 3 => '3 / 5', 4 => '4 / 5', 5 => '5 / 5'])
                                    ->placeholder('使用主文风默认'))->values()->all()),
                            Section::make('Budget Limits')
                                ->description('留空时继承全局限制；0 表示立即阻止该范围的新 Provider Request。')
                                ->icon('heroicon-o-banknotes')
                                ->columns(['default' => 1, 'md' => 2])
                                ->schema([
                                    TextInput::make('budget_limits.novel_total_limit')
                                        ->label('Novel Total Limit')
                                        ->numeric()
                                        ->minValue(0)
                                        ->step(0.000001)
                                        ->placeholder(fn (): string => self::globalBudgetPlaceholder('novel_total_limit')),
                                    TextInput::make('budget_limits.chapter_max_cost')
                                        ->label('Chapter Max Cost')
                                        ->numeric()
                                        ->minValue(0)
                                        ->step(0.000001)
                                        ->placeholder(fn (): string => self::globalBudgetPlaceholder('chapter_max_cost')),
                                ]),
                            Section::make('生成自动化')
                                ->description('控制 Review 通过后的下一步。关闭时仍可在章节工作台手工提交。')
                                ->icon('heroicon-o-bolt')
                                ->schema([
                                    Toggle::make('auto_commit')
                                        ->label('Review 通过后自动提交')
                                        ->helperText('开启后，PASS Review 会通过 CommitChapterJob 提交正式章节。')
                                        ->default(false),
                                ]),
                        ]),
                        Section::make('AI Model Overrides')
                            ->description('留空时继承 Global Default。这里只覆盖各 Stage 的模型，不保存 Provider 凭据。')
                            ->icon('heroicon-o-cpu-chip')
                            ->columns(['default' => 1, 'lg' => 2])
                            ->schema(self::aiModelOverrideFields()),
                    ]),
            ]);
    }

    /** @return array<string, string> */
    private static function styleOptions(): array
    {
        return collect(config('narrative.styles'))->mapWithKeys(fn (array $style, string $code): array => [$code => $style['name']])->all();
    }

    /** @return array<int, TextInput|TextEntry> */
    private static function aiModelOverrideFields(): array
    {
        return collect(AiStage::cases())
            ->flatMap(function (AiStage $stage): array {
                return [
                    TextInput::make("ai_model_overrides.{$stage->value}")
                        ->label($stage->getLabel().' Override')
                        ->placeholder(fn (): string => app(AiSettingsResolver::class)->modelFor($stage))
                        ->helperText('留空继承全局设置。')
                        ->maxLength(255),
                    TextEntry::make("resolved_ai_models.{$stage->value}")
                        ->label($stage->getLabel().' Resolved Model')
                        ->state(function (?Novel $record) use ($stage): string {
                            if ($record === null) {
                                return app(AiSettingsResolver::class)->modelFor($stage);
                            }

                            $resolved = app(AiSettingsResolver::class)->resolve($stage, $record);
                            $source = $resolved->source === 'novel' ? 'Novel Override' : 'Global Default';

                            return $resolved->model.' · '.$source;
                        })
                        ->badge(),
                ];
            })
            ->all();
    }

    private static function globalBudgetPlaceholder(string $key): string
    {
        $limit = config("ai.budget.{$key}");

        return is_numeric($limit)
            ? config('ai.cost.currency').' '.number_format((float) $limit, 4)
            : '无限制';
    }
}
