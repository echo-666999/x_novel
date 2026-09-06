<?php

namespace App\Providers;

use App\AI\BudgetService;
use App\AI\Contracts\AiProvider;
use App\AI\Providers\BudgetGuardAiProvider;
use App\AI\Providers\OpenAiProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\UsageRecorder;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiProvider::class, function (): AiProvider {
            $provider = match (config('ai.provider')) {
                'openai' => app(OpenAiProvider::class),
                default => throw new InvalidArgumentException('Unsupported AI provider: '.config('ai.provider')),
            };

            $trackedProvider = new TrackingAiProvider($provider, app(UsageRecorder::class));

            return new BudgetGuardAiProvider($trackedProvider, app(BudgetService::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
