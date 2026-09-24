<?php

namespace App\Filament\Resources\AIModelPrices\Pages;

use App\AI\AiModelRouteService;
use App\Enums\AiReasoningEffort;
use App\Enums\AiStage;
use App\Filament\Resources\AIModelPrices\AIModelPriceResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;

class ListAIModelPrices extends ListRecords
{
    protected static string $resource = AIModelPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('configureModelRoutes')
                ->label('模型路由')
                ->icon('heroicon-o-arrows-right-left')
                ->modalHeading('按生成节点配置模型')
                ->modalDescription('只影响之后创建的 Generation Run；运行中的任务继续使用已经冻结的 Provider、Model 和推理程度。')
                ->fillForm(fn (): array => ['routes' => app(AiModelRouteService::class)->formState()])
                ->schema(collect(AiStage::cases())->map(fn (AiStage $stage): Grid => Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make("routes.{$stage->value}.model_price_id")
                            ->label($stage->getLabel().'模型')
                            ->options(fn (): array => app(AiModelRouteService::class)->modelOptions())
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->helperText($stage === AiStage::Embedding ? '当前向量生成只支持 OpenAI Provider。' : null),
                        Select::make("routes.{$stage->value}.reasoning_effort")
                            ->label($stage->getLabel().'推理程度')
                            ->options(AiReasoningEffort::options())
                            ->placeholder('Provider 默认')
                            ->native(false)
                            ->disabled($stage === AiStage::Embedding)
                            ->helperText($stage === AiStage::Embedding
                                ? '向量生成不使用推理程度。'
                                : '仅对支持 reasoning_effort 的 Provider 生效。'),
                    ]))
                    ->all())
                ->action(function (array $data, AiModelRouteService $routes): void {
                    $routes->save((array) ($data['routes'] ?? []), auth()->id());

                    Notification::make()
                        ->title('模型路由已保存')
                        ->success()
                        ->send();
                }),
            CreateAction::make()->label('新建模型价格'),
        ];
    }
}
