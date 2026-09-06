<?php

namespace App\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Providers\OpenAiProvider;
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
            return match (config('ai.provider')) {
                'openai' => app(OpenAiProvider::class),
                default => throw new InvalidArgumentException('Unsupported AI provider: '.config('ai.provider')),
            };
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
