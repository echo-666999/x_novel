<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\AI\AiSettingsResolver;
use App\AI\AiSettingsService;
use App\Enums\AiStage;
use App\Models\Novel;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
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
                Section::make('AI Model Overrides')
                    ->description('留空时继承 Global Default。这里只覆盖各 Stage 的模型，不保存 Provider 凭据。')
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
                Section::make('Canonical Workflow')
                    ->description('控制 Review PASS 后是否自动进入正式提交。')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        Toggle::make('workflow_auto_commit')
                            ->label('Review 通过后自动提交正式章节')
                            ->helperText('默认关闭。开启后，只有当前 Draft、PASS Review、Event Candidate、State Patch 与 Expected State Version 全部一致时才会提交；提交会更新 Canonical Story State 并启动现有后置任务。')
                            ->default(false),
                    ]),
            ]);
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
                            $source = match ($resolved->source) {
                                'novel' => 'Novel Override',
                                'database' => 'Database Default',
                                default => 'Environment Default',
                            };

                            return $resolved->model.' · '.$source;
                        })
                        ->badge(),
                ];
            })
            ->all();
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
