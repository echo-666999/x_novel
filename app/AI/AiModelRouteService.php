<?php

namespace App\AI;

use App\Enums\AiReasoningEffort;
use App\Enums\AiStage;
use App\Models\AIModelPrice;
use App\Models\AIModelRoute;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AiModelRouteService
{
    private const EXISTING_NOVEL_OVERRIDE_PREFIX = 'existing:';

    public function __construct(private readonly AiSettingsService $settings) {}

    public function find(AiStage $stage): ?AIModelRoute
    {
        if (! Schema::hasTable('ai_model_routes')) {
            return null;
        }

        $route = AIModelRoute::query()->where('role', $stage->value)->first();
        $fallbackStage = $stage->fallbackStage();

        return $route ?? ($fallbackStage === null
            ? null
            : AIModelRoute::query()->where('role', $fallbackStage->value)->first());
    }

    /** @return array<string, array{model_price_id: int|null, reasoning_effort: string|null}> */
    public function formState(): array
    {
        $routes = Schema::hasTable('ai_model_routes')
            ? AIModelRoute::query()->get()->keyBy(fn (AIModelRoute $route): string => $route->role->value)
            : collect();

        return collect(AiStage::cases())->mapWithKeys(function (AiStage $stage) use ($routes): array {
            // 新增专用 Outline 路由前继续显示 Planner 的有效配置，避免升级后表单出现空白必填项。
            $route = $routes->get($stage->value)
                ?? ($stage->fallbackStage() === null ? null : $routes->get($stage->fallbackStage()->value));
            if ($route === null) {
                return [$stage->value => ['model_price_id' => null, 'reasoning_effort' => null]];
            }

            $priceId = AIModelPrice::query()
                ->where('provider', $route->provider)
                ->where('model', $route->model)
                ->where('is_enabled', true)
                ->orderByRaw('currency = ? desc', [strtoupper((string) config('ai.cost.currency', 'USD'))])
                ->value('id');

            return [$stage->value => [
                'model_price_id' => is_numeric($priceId) ? (int) $priceId : null,
                'reasoning_effort' => $route->reasoning_effort?->value,
            ]];
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

    /** @return array<int|string, string> */
    public function novelOverrideOptions(?Novel $novel, AiStage $stage): array
    {
        $options = $this->modelOptions();
        if ($novel === null) {
            return $options;
        }

        $selection = $this->novelOverrideSelection($novel, $stage);
        if (! is_string($selection) || ! str_starts_with($selection, self::EXISTING_NOVEL_OVERRIDE_PREFIX)) {
            return $options;
        }

        $route = $this->explicitNovelOverride($novel, $stage);
        if ($route !== null) {
            $options[$selection] = '当前旧配置 · '.strtoupper($route['provider']).' · '.$route['model'];
        }

        return $options;
    }

    /** @return array<string, int|string|null> */
    public function novelOverrideFormState(Novel $novel): array
    {
        return collect($this->settings->stages())
            ->mapWithKeys(fn (AiStage $stage): array => [$stage->value => $this->novelOverrideSelection($novel, $stage)])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $selections
     * @return array<string, mixed>
     */
    public function applyNovelOverrides(array $settings, array $selections, ?Novel $novel = null): array
    {
        $resolved = [];
        $errors = [];

        foreach ($this->settings->stages() as $stage) {
            $selection = $selections[$stage->value] ?? null;
            if (blank($selection)) {
                continue;
            }

            $price = is_numeric($selection)
                ? AIModelPrice::query()->where('is_enabled', true)->find((int) $selection)
                : null;
            if ($price !== null) {
                $resolved[$stage->value] = [
                    'provider' => strtolower(trim($price->provider)),
                    'model' => trim($price->model),
                ];

                continue;
            }

            $existingToken = self::EXISTING_NOVEL_OVERRIDE_PREFIX.$stage->value;
            $existing = $novel === null || $selection !== $existingToken
                ? null
                : $this->explicitNovelOverride($novel, $stage);
            if ($existing !== null) {
                $resolved[$stage->value] = $existing;

                continue;
            }

            $errors["ai_model_overrides.{$stage->value}"][] = '请选择一个已启用且已配置价格的模型。';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $ai = is_array($settings['ai'] ?? null) ? $settings['ai'] : [];
        $legacyModels = is_array($ai['models'] ?? null) ? $ai['models'] : [];
        $stageSettings = is_array($ai['stages'] ?? null) ? $ai['stages'] : [];
        foreach ($this->settings->stages() as $stage) {
            unset($legacyModels[$stage->value], $stageSettings[$stage->value]);
        }
        if ($legacyModels === []) {
            unset($ai['models']);
        } else {
            $ai['models'] = $legacyModels;
        }
        $stageSettings = [...$stageSettings, ...$resolved];
        if ($stageSettings === []) {
            unset($ai['stages']);
        } else {
            $ai['stages'] = $stageSettings;
        }
        if ($ai === []) {
            unset($settings['ai']);
        } else {
            $settings['ai'] = $ai;
        }

        return $settings;
    }

    private function novelOverrideSelection(Novel $novel, AiStage $stage): int|string|null
    {
        $route = $this->explicitNovelOverride($novel, $stage);
        if ($route === null) {
            return null;
        }

        $priceId = AIModelPrice::query()
            ->where('provider', $route['provider'])
            ->where('model', $route['model'])
            ->where('is_enabled', true)
            ->orderByRaw('currency = ? desc', [strtoupper((string) config('ai.cost.currency', 'USD'))])
            ->value('id');

        return is_numeric($priceId)
            ? (int) $priceId
            : self::EXISTING_NOVEL_OVERRIDE_PREFIX.$stage->value;
    }

    /** @return array{provider: string, model: string}|null */
    private function explicitNovelOverride(Novel $novel, AiStage $stage): ?array
    {
        $stageOverride = data_get($novel->settings, "ai.stages.{$stage->value}");
        if (is_array($stageOverride)
            && filled($stageOverride['provider'] ?? null)
            && filled($stageOverride['model'] ?? null)) {
            return [
                'provider' => strtolower(trim((string) $stageOverride['provider'])),
                'model' => trim((string) $stageOverride['model']),
            ];
        }

        $model = data_get($novel->settings, "ai.models.{$stage->value}");
        if (! is_string($model) || trim($model) === '') {
            return null;
        }

        return [
            'provider' => $this->baseProvider($stage),
            'model' => trim($model),
        ];
    }

    private function baseProvider(AiStage $stage): string
    {
        $route = $this->find($stage);
        if ($route !== null) {
            return strtolower(trim($route->provider));
        }

        $settings = $this->settings->current()['settings'];
        $provider = data_get($settings, "stages.{$stage->value}.provider");
        $fallbackStage = $stage->fallbackStage();
        if ((! is_string($provider) || trim($provider) === '') && $fallbackStage !== null) {
            $provider = data_get($settings, "stages.{$fallbackStage->value}.provider");
        }

        return strtolower(trim(is_string($provider) && $provider !== ''
            ? $provider
            : (string) data_get($settings, 'default_provider', config('ai.provider'))));
    }

    /** @param array<string, mixed> $routes */
    public function save(array $routes, ?int $actorId): void
    {
        $resolved = [];
        $errors = [];

        foreach (AiStage::cases() as $stage) {
            $routeInput = $routes[$stage->value] ?? null;
            // 兼容旧调用方直接传入 price id，新后台表单传入模型与推理程度的组合。
            $priceId = is_array($routeInput) ? ($routeInput['model_price_id'] ?? null) : $routeInput;
            $reasoningEffort = is_array($routeInput) ? ($routeInput['reasoning_effort'] ?? null) : null;
            $reasoningEffort = is_string($reasoningEffort) && trim($reasoningEffort) !== ''
                ? trim($reasoningEffort)
                : null;
            $price = is_numeric($priceId)
                ? AIModelPrice::query()->where('is_enabled', true)->find((int) $priceId)
                : null;

            if ($price === null) {
                $errors["routes.{$stage->value}.model_price_id"][] = '请选择一个已启用且已配置价格的模型。';

                continue;
            }

            if ($reasoningEffort !== null && AiReasoningEffort::tryFrom($reasoningEffort) === null) {
                $errors["routes.{$stage->value}.reasoning_effort"][] = '推理程度必须是低、中、高或使用 Provider 默认值。';

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
                'reasoning_effort' => $reasoningEffort,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($resolved, $actorId): void {
            $before = AIModelRoute::query()->get()->mapWithKeys(fn (AIModelRoute $route): array => [
                $route->role->value => [
                    'provider' => $route->provider,
                    'model' => $route->model,
                    'reasoning_effort' => $route->reasoning_effort?->value,
                ],
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
