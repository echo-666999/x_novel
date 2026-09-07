<?php

namespace App\Providers;

use App\AI\BudgetService;
use App\AI\Contracts\AiProvider;
use App\AI\Contracts\EmbeddingProvider;
use App\AI\Providers\BudgetGuardAiProvider;
use App\AI\Providers\EmergencyStopAiProvider;
use App\AI\Providers\EmergencyStopEmbeddingProvider;
use App\AI\Providers\OpenAiProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\Providers\TrackingEmbeddingProvider;
use App\AI\UsageRecorder;
use App\Contracts\StoryEventApplier;
use App\Services\DeterministicStoryEventApplier;
use App\Services\EmergencyStopService;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(StoryEventApplier::class, DeterministicStoryEventApplier::class);
        $this->app->bind(AiProvider::class, function (): AiProvider {
            $provider = match (config('ai.provider')) {
                'openai' => app(OpenAiProvider::class),
                default => throw new InvalidArgumentException('Unsupported AI provider: '.config('ai.provider')),
            };

            $trackedProvider = new TrackingAiProvider($provider, app(UsageRecorder::class));

            $budgetedProvider = new BudgetGuardAiProvider($trackedProvider, app(BudgetService::class));

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
