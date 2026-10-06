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
use Illuminate\Validation\ValidationException;

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
                ->modalDescription('只影响之后创建的 Generation Run；运行中的任务继续使用已经冻结的 Provider、Model 和推理程度。Outline 模型必须先在模型价格记录中填写已核实容量并开启必要能力。')
                ->fillForm(fn (): array => ['routes' => app(AiModelRouteService::class)->formState()])
                ->schema(collect(AiStage::cases())->map(fn (AiStage $stage): Grid => Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make("routes.{$stage->value}.model_price_id")
                            ->label($stage->getLabel().'模型')
                            ->options(fn (): array => app(AiModelRouteService::class)->modelOptions())
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->helperText(match (true) {
                                $stage === AiStage::Embedding => '当前向量生成只支持 OpenAI Provider。',
                                in_array($stage, [AiStage::OutlineFoundation, AiStage::OutlineStructure, AiStage::OutlineArcBeats, AiStage::OutlineBeatDetail], true) => '必须先在模型价格记录中填写容量并开启结构化输出；设置推理程度时还需开启对应能力。',
                                default => null,
                            }),
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
                ->action(function (array $data, AiModelRouteService $routes, Action $action): void {
                    try {
                        $routes->save((array) ($data['routes'] ?? []), auth()->id());
                    } catch (ValidationException $exception) {
                        $statePath = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                        $messages = collect($exception->errors())->flatten()->unique()->values();

                        foreach ($exception->errors() as $field => $fieldMessages) {
                            foreach ($fieldMessages as $message) {
                                // Domain Service 使用 routes.*；Action 表单需要完整状态路径才能在对应字段显示错误。
                                $this->addError("{$statePath}.{$field}", $message);
                            }
                        }

                        Notification::make()
                            ->title('模型路由未保存')
                            ->body($messages->take(3)->implode('；').($messages->count() > 3 ? '；请检查表单中的全部错误。' : ''))
                            ->danger()
                            ->persistent()
                            ->send();

                        $action->halt();
                    }

                    Notification::make()
                        ->title('模型路由已保存')
                        ->success()
                        ->send();
                }),
            CreateAction::make()->label('新建模型价格'),
        ];
    }
}
