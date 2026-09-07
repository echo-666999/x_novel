<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\AI\AiSettingsResolver;
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
                            ->label('目标字数')
                            ->helperText('必须大于 0。')
                            ->integer()
                            ->minValue(1)
                            ->default(1_000_000)
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('premise')
                            ->label('故事前提')
                            ->rows(5)
                            ->maxLength(10_000)
                            ->columnSpanFull(),
                    ]),
                Section::make('AI Model Overrides')
                    ->description('留空时继承 Global Default。这里只覆盖各 Stage 的模型，不保存 Provider 凭据。')
                    ->icon('heroicon-o-cpu-chip')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema(self::aiModelOverrideFields()),
                Section::make('Budget Limits')
                    ->description('留空时继承全局限制；0 表示立即阻止该范围的新 Provider Request。')
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
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
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        Toggle::make('auto_commit')
                            ->label('Review 通过后自动提交')
                            ->helperText('开启后，PASS Review 会通过 CommitChapterJob 提交正式章节。')
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
            ? config('ai.cost.currency').' '.number_format((float) $limit, 6)
            : '无限制';
    }
}
