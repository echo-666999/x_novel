<?php

namespace App\AI;

use App\Enums\AiStage;
use App\Models\AIModelPrice;
use App\Models\AIModelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AiModelRouteService
{
    public function __construct(private readonly AiSettingsService $settings) {}

    public function find(AiStage $stage): ?AIModelRoute
    {
        if (! Schema::hasTable('ai_model_routes')) {
            return null;
        }

        return AIModelRoute::query()->where('role', $stage->value)->first();
    }

    /** @return array<string, int|null> */
    public function formState(): array
    {
        $routes = Schema::hasTable('ai_model_routes')
            ? AIModelRoute::query()->get()->keyBy(fn (AIModelRoute $route): string => $route->role->value)
            : collect();

        return collect(AiStage::cases())->mapWithKeys(function (AiStage $stage) use ($routes): array {
            $route = $routes->get($stage->value);
            if ($route === null) {
                return [$stage->value => null];
            }

            $priceId = AIModelPrice::query()
                ->where('provider', $route->provider)
                ->where('model', $route->model)
                ->where('is_enabled', true)
                ->orderByRaw('currency = ? desc', [strtoupper((string) config('ai.cost.currency', 'USD'))])
                ->value('id');

            return [$stage->value => is_numeric($priceId) ? (int) $priceId : null];
        })->all();
    }

    /** @return array<int, string> */
    public function modelOptions(): array
    {
        if (! Schema::hasTable('ai_model_prices')) {
            return [];
        }

        return AIModelPrice::query()
            ->where('is_enabled', true)
            ->orderBy('provider')
            ->orderBy('model')
            ->get()
            ->mapWithKeys(fn (AIModelPrice $price): array => [
                $price->getKey() => strtoupper($price->provider).' · '.$price->model.' · '.$price->currency,
            ])->all();
    }

    /** @param array<string, mixed> $routes */
    public function save(array $routes, ?int $actorId): void
    {
        $resolved = [];
        $errors = [];

        foreach (AiStage::cases() as $stage) {
            $priceId = $routes[$stage->value] ?? null;
            $price = is_numeric($priceId)
                ? AIModelPrice::query()->where('is_enabled', true)->find((int) $priceId)
                : null;

            if ($price === null) {
                $errors["routes.{$stage->value}"][] = '请选择一个已启用且已配置价格的模型。';

                continue;
            }

            if ($stage === AiStage::Embedding && $price->provider !== 'openai') {
                $errors["routes.{$stage->value}"][] = '当前版本的向量生成只支持 OpenAI Provider。';

                continue;
            }

            try {
                $this->settings->assertProviderAvailable($price->provider);
            } catch (\Throwable $exception) {
                $errors["routes.{$stage->value}"][] = $exception->getMessage();

                continue;
            }

            // 路由值最终会原样发送给 Provider，保存前必须去除后台录入产生的首尾空白。
            $resolved[$stage->value] = [
                'provider' => strtolower(trim($price->provider)),
                'model' => trim($price->model),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($resolved, $actorId): void {
            $before = AIModelRoute::query()->get()->mapWithKeys(fn (AIModelRoute $route): array => [
                $route->role->value => ['provider' => $route->provider, 'model' => $route->model],
            ])->all();

            foreach ($resolved as $role => $route) {
                AIModelRoute::query()->updateOrCreate(['role' => $role], $route);
            }

            Log::info('AI model routes changed.', [
                'actor_id' => $actorId,
                'before' => $before,
                'after' => $resolved,
            ]);
        });
    }
}
