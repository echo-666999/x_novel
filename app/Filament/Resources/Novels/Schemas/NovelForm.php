<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\AI\AiModelRouteService;
use App\AI\AiSettingsResolver;
use App\AI\AiSettingsService;
use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Models\Novel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use InvalidArgumentException;

class NovelForm
{
    public const CUSTOM_GENRE_OPTION = '__custom__';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基础信息')
                    ->description('维护小说的核心定位与生成目标。')
                    ->schema([
                        TextInput::make('title')
                            ->label('标题')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('genre')
                            ->label('题材')
                            ->options(self::genreOptions())
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->placeholder('请选择一级题材')
                            ->required()
                            ->validationMessages([
                                'required' => '请选择题材。',
                                'in' => '题材不是受支持的选项。',
                            ]),
                        TextInput::make('custom_genre')
                            ->label('自定义题材')
                            ->helperText('输入标准列表之外的一级题材。')
                            ->placeholder('例如：民俗志怪')
                            ->visible(fn (Get $get): bool => $get('genre') === self::CUSTOM_GENRE_OPTION)
                            ->required(fn (Get $get): bool => $get('genre') === self::CUSTOM_GENRE_OPTION)
                            ->dehydrateStateUsing(fn (mixed $state): mixed => is_string($state) ? trim($state) : $state)
                            ->maxLength(255)
                            ->validationMessages([
                                'required' => '请输入自定义题材。',
                                'max' => '自定义题材不能超过 255 个字符。',
                            ]),
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
                            ->rows(19)
                            ->maxLength(10_000)
                            ->columnSpanFull(),
                    ]),
                Section::make('AI 模型覆盖')
                    ->description('从已启用的模型价格中选择小说级 Provider + Model；留空继承全局路由，不保存 Provider 凭据。')
                    ->icon('heroicon-o-cpu-chip')
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema(self::aiModelOverrideFields()),
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
            ]);
    }

    /** @return array<string, string> */
    public static function genreOptions(): array
    {
        return collect(config('narrative.genres', []))
            ->filter(fn (mixed $genre): bool => is_string($genre) && filled($genre))
            ->mapWithKeys(fn (string $genre): array => $genre === '其他'
                ? [self::CUSTOM_GENRE_OPTION => $genre]
                : [$genre => $genre])
            ->all();
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareGenreForFill(array $data): array
    {
        $genre = $data['genre'] ?? null;

        if (! is_string($genre) || array_key_exists($genre, self::genreOptions())) {
            return $data;
        }

        $data['genre'] = self::CUSTOM_GENRE_OPTION;
        $data['custom_genre'] = $genre;

        return $data;
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareGenreForPersistence(array $data): array
    {
        if (($data['genre'] ?? null) === self::CUSTOM_GENRE_OPTION) {
            $customGenre = $data['custom_genre'] ?? null;
            $data['genre'] = is_string($customGenre) ? trim($customGenre) : $customGenre;
        }

        unset($data['custom_genre']);

        return $data;
    }

    /** @return array<int, Select|TextEntry> */
    private static function aiModelOverrideFields(): array
    {
        return collect(app(AiSettingsService::class)->stages())
            ->flatMap(function (AiStage $stage): array {
                return [
                    Select::make("ai_model_overrides.{$stage->value}")
                        ->label($stage->getLabel().' · 小说级覆盖')
                        ->options(fn (?Novel $record): array => app(AiModelRouteService::class)->novelOverrideOptions($record, $stage))
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->placeholder(fn (): string => self::inheritedAiRoutePlaceholder($stage))
                        ->helperText('只列出“模型价格”中已启用的 Provider + Model；留空继承全局路由。'),
                    TextEntry::make("resolved_ai_models.{$stage->value}")
                        ->label($stage->getLabel().' · 当前表单生效路由')
                        ->state(fn (Get $get, ?Novel $record): string => self::aiRoutePresentation($get, $record, $stage)['text'])
                        ->color(fn (Get $get, ?Novel $record): string => self::aiRoutePresentation($get, $record, $stage)['color'])
                        ->badge(),
                ];
            })
            ->all();
    }

    private static function inheritedAiRoutePlaceholder(AiStage $stage): string
    {
        try {
            $resolved = app(AiSettingsResolver::class)->resolve($stage);

            return '继承 · '.strtoupper($resolved->provider).' / '.$resolved->model;
        } catch (AiProviderException|InvalidArgumentException) {
            // 全局路由缺失时表单仍必须可打开，用户才能通过小说级 Select 修复配置。
            return '继承路由不可用 · 请选择 Provider + Model';
        }
    }

    /** @return array{text: string, color: string} */
    private static function aiRoutePresentation(Get $get, ?Novel $record, AiStage $stage): array
    {
        $selection = $get("ai_model_overrides.{$stage->value}");
        $preview = app(AiModelRouteService::class)->novelOverridePreview($record, $stage, $selection);

        if ($preview['selected']) {
            if (! $preview['ready'] || $preview['provider'] === null || $preview['model'] === null) {
                $model = filled($preview['model']) ? ' · '.$preview['model'] : '';

                return [
                    'text' => '配置不完整'.$model.' · '.($preview['error'] ?? '请重新选择完整路由。'),
                    'color' => 'danger',
                ];
            }

            try {
                app(AiSettingsService::class)->assertProviderAvailable($preview['provider']);
            } catch (AiProviderException $exception) {
                return [
                    'text' => strtoupper($preview['provider']).' / '.$preview['model'].' · '.$exception->getMessage(),
                    'color' => 'danger',
                ];
            }

            // 新 Override 使用 Provider 默认推理；同一路由已有的小说级推理值会保留，不能继承其他 Stage。
            $reasoning = filled($preview['reasoning_effort']) ? $preview['reasoning_effort'] : 'Provider 默认';

            return [
                'text' => strtoupper($preview['provider']).' / '.$preview['model'].' · 推理 '.$reasoning.' · 小说 Override',
                'color' => 'success',
            ];
        }

        try {
            // Select 留空代表继承，因此这里解析不带 Novel 的全局同名 Stage，避免显示尚未保存的旧 Override。
            $resolved = app(AiSettingsResolver::class)->resolve($stage);
            $source = match ($resolved->source) {
                'database' => '数据库路由',
                default => '环境路由',
            };
            $reasoning = filled($resolved->reasoningEffort) ? $resolved->reasoningEffort : 'Provider 默认';

            return [
                'text' => strtoupper($resolved->provider).' / '.$resolved->model.' · 推理 '.$reasoning.' · '.$source,
                'color' => 'gray',
            ];
        } catch (AiProviderException|InvalidArgumentException $exception) {
            return [
                'text' => '继承路由不可用 · '.$exception->getMessage(),
                'color' => 'danger',
            ];
        }
    }

    private static function globalBudgetPlaceholder(string $key): string
    {
        $settings = app(AiSettingsService::class);
        $limit = data_get($settings->budgetSettings(), $key);

        return is_numeric($limit)
            ? data_get($settings->costSettings(), 'currency', 'USD').' '.number_format((float) $limit, 4)
            : '无限制';
    }
}
