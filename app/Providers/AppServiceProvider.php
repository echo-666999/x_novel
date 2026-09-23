<?php

namespace App\Providers;

use App\AI\AiSettingsService;
use App\AI\BudgetService;
use App\AI\Contracts\AiProvider;
use App\AI\Contracts\EmbeddingProvider;
use App\AI\Providers\BudgetGuardAiProvider;
use App\AI\Providers\DeepSeekProvider;
use App\AI\Providers\EmergencyStopAiProvider;
use App\AI\Providers\EmergencyStopEmbeddingProvider;
use App\AI\Providers\OpenAiProvider;
use App\AI\Providers\RoutingAiProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\Providers\TrackingEmbeddingProvider;
use App\AI\UsageRecorder;
use App\Contracts\StoryEventApplier;
use App\Services\DeterministicStoryEventApplier;
use App\Services\EmergencyStopService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(StoryEventApplier::class, DeterministicStoryEventApplier::class);
        $this->app->bind(AiProvider::class, function (): AiProvider {
            // 先按请求中的 provider 路由，再由 Tracking 装饰器统一记录实际用量与费用。
            $provider = new RoutingAiProvider([
                'openai' => new TrackingAiProvider(app(OpenAiProvider::class), app(UsageRecorder::class)),
                'deepseek' => new TrackingAiProvider(app(DeepSeekProvider::class), app(UsageRecorder::class)),
            ], app(AiSettingsService::class));

            // 预算与紧急停止位于最外层，确保任何业务阶段都不能绕过全局保护。
            $budgetedProvider = new BudgetGuardAiProvider($provider, app(BudgetService::class));

            return new EmergencyStopAiProvider($budgetedProvider, app(EmergencyStopService::class));
        });
        $this->app->bind(EmbeddingProvider::class, fn (): EmbeddingProvider => new EmergencyStopEmbeddingProvider(
            new TrackingEmbeddingProvider(app(OpenAiProvider::class), app(UsageRecorder::class)),
            app(EmergencyStopService::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
