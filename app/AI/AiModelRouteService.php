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

    private const INCOMPLETE_OUTLINE_OVERRIDE_PREFIX = 'incomplete-outline:';

    public function __construct(private readonly AiSettingsService $settings) {}

    public function find(AiStage $stage): ?AIModelRoute
    {
        if (! Schema::hasTable('ai_model_routes')) {
            return null;
        }

        return AIModelRoute::query()->where('role', $stage->value)->first();
    }

    /** @return array<string, array{model_price_id: int|null, reasoning_effort: string|null}> */
    public function formState(): array
    {
        $routes = Schema::hasTable('ai_model_routes')
            ? AIModelRoute::query()->get()->keyBy(fn (AIModelRoute $route): string => $route->role->value)
            : collect();

        return collect(AiStage::cases())->mapWithKeys(function (AiStage $stage) use ($routes): array {
            // 空值必须真实反映该 Stage 尚未配置，不能用其他 Stage 的值伪装成独立路由。
            $route = $routes->get($stage->value);
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
        if (is_string($selection) && str_starts_with($selection, self::INCOMPLETE_OUTLINE_OVERRIDE_PREFIX)) {
            // 旧 Outline Override 只有 Model，不能替用户推断 Provider；保留警示选项供无关保存原样带回。
            $model = $this->legacyNovelModelOverride($novel, $stage);
            if ($model !== null) {
                $options[$selection] = '当前旧配置不完整 · '.$model.' · 请重新选择 Provider + Model';
            }

            return $options;
        }

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
     * @return array{selected: bool, ready: bool, provider: string|null, model: string|null, reasoning_effort: string|null, preserve_legacy: bool, error: string|null}
     */
    public function novelOverridePreview(?Novel $novel, AiStage $stage, mixed $selection): array
    {
        if (blank($selection)) {
            return [
                'selected' => false,
                'ready' => true,
                'provider' => null,
                'model' => null,
                'reasoning_effort' => null,
                'preserve_legacy' => false,
                'error' => null,
            ];
        }

        $price = is_numeric($selection)
            ? AIModelPrice::query()->where('is_enabled', true)->find((int) $selection)
            : null;
        if ($price !== null) {
            $reasoningEffort = $this->existingNovelReasoningEffort(
                $novel,
                $stage,
                strtolower(trim($price->provider)),
                trim($price->model),
            );
            $capabilityErrors = $this->modelCapabilityErrors($stage, $price, $reasoningEffort);

            return [
                'selected' => true,
                'ready' => $capabilityErrors === [],
                'provider' => strtolower(trim($price->provider)),
                'model' => trim($price->model),
                'reasoning_effort' => $reasoningEffort,
                'preserve_legacy' => false,
                'error' => $capabilityErrors === [] ? null : implode(' ', $capabilityErrors),
            ];
        }

        $incompleteToken = self::INCOMPLETE_OUTLINE_OVERRIDE_PREFIX.$stage->value;
        $legacyModel = $novel === null || $selection !== $incompleteToken
            ? null
            : $this->legacyNovelModelOverride($novel, $stage);
        if ($legacyModel !== null && $this->isOutlineStage($stage)) {
            return [
                'selected' => true,
                'ready' => false,
                'provider' => null,
                'model' => $legacyModel,
                'reasoning_effort' => null,
                'preserve_legacy' => true,
                'error' => '旧配置只有 Model，必须重新选择包含 Provider + Model 的完整路由。',
            ];
        }

        $existingToken = self::EXISTING_NOVEL_OVERRIDE_PREFIX.$stage->value;
        $existing = $novel === null || $selection !== $existingToken
            ? null
            : $this->explicitNovelOverride($novel, $stage);
        if ($existing !== null) {
            return [
                'selected' => true,
                'ready' => true,
                'provider' => $existing['provider'],
                'model' => $existing['model'],
                'reasoning_effort' => $this->existingNovelReasoningEffort(
                    $novel,
                    $stage,
                    $existing['provider'],
                    $existing['model'],
                ),
                'preserve_legacy' => false,
                'error' => null,
            ];
        }

        return [
            'selected' => true,
            'ready' => false,
            'provider' => null,
            'model' => null,
            'reasoning_effort' => null,
            'preserve_legacy' => false,
            'error' => '请选择一个已启用且已配置价格的模型。',
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $selections
     * @return array<string, mixed>
     */
    public function applyNovelOverrides(array $settings, array $selections, ?Novel $novel = null): array
    {
        $resolved = [];
        $preservedLegacyModels = [];
        $errors = [];

        foreach ($this->settings->stages() as $stage) {
            $selection = $selections[$stage->value] ?? null;
            $preview = $this->novelOverridePreview($novel, $stage, $selection);
            if (! $preview['selected']) {
                continue;
            }

            if ($preview['preserve_legacy'] && $preview['model'] !== null) {
                // 用户只做无关编辑时保留不完整旧值，但仍由 Batch 1 路由合同阻止其启动 Outline。
                $preservedLegacyModels[$stage->value] = $preview['model'];

                continue;
            }

            if ($preview['ready'] && $preview['provider'] !== null && $preview['model'] !== null) {
                $resolved[$stage->value] = [
                    'provider' => $preview['provider'],
                    'model' => $preview['model'],
                ];
                if ($preview['reasoning_effort'] !== null) {
                    // 同一路由的既有推理策略属于小说配置；无关保存不得把它静默重置为 Provider 默认。
                    $resolved[$stage->value]['reasoning_effort'] = $preview['reasoning_effort'];
                }

                continue;
            }

            $errors["ai_model_overrides.{$stage->value}"][] = $preview['error'] ?? '模型覆盖配置无效。';
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
        // 仅把明确识别出的旧 Outline Model-only 值写回，避免其他空选择恢复已删除的 Override。
        $legacyModels = [...$legacyModels, ...$preservedLegacyModels];
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
        if ($this->isOutlineStage($stage)
            && $this->structuredNovelOverride($novel, $stage) === null
            && $this->legacyNovelModelOverride($novel, $stage) !== null) {
            return self::INCOMPLETE_OUTLINE_OVERRIDE_PREFIX.$stage->value;
        }

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
        $stageOverride = $this->structuredNovelOverride($novel, $stage);
        if ($stageOverride !== null) {
            return $stageOverride;
        }

        $model = $this->legacyNovelModelOverride($novel, $stage);
        if ($model === null) {
            return null;
        }

        return [
            'provider' => $this->baseProvider($stage),
            'model' => trim($model),
        ];
    }

    /** @return array{provider: string, model: string}|null */
    private function structuredNovelOverride(Novel $novel, AiStage $stage): ?array
    {
        $stageOverride = data_get($novel->settings, "ai.stages.{$stage->value}");
        if (! is_array($stageOverride)
            || blank($stageOverride['provider'] ?? null)
            || blank($stageOverride['model'] ?? null)) {
            return null;
        }

        return [
            'provider' => strtolower(trim((string) $stageOverride['provider'])),
            'model' => trim((string) $stageOverride['model']),
        ];
    }

    private function legacyNovelModelOverride(Novel $novel, AiStage $stage): ?string
    {
        $model = data_get($novel->settings, "ai.models.{$stage->value}");

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    private function existingNovelReasoningEffort(?Novel $novel, AiStage $stage, string $provider, string $model): ?string
    {
        if ($novel === null) {
            return null;
        }

        $settings = data_get($novel->settings, "ai.stages.{$stage->value}");
        if (! is_array($settings)
            || strtolower(trim((string) ($settings['provider'] ?? ''))) !== $provider
            || trim((string) ($settings['model'] ?? '')) !== $model) {
            return null;
        }

        $reasoningEffort = $settings['reasoning_effort'] ?? null;

        return is_string($reasoningEffort) && trim($reasoningEffort) !== ''
            ? trim($reasoningEffort)
            : null;
    }

    private function isOutlineStage(AiStage $stage): bool
    {
        return in_array($stage, [
            AiStage::OutlineFoundation,
            AiStage::OutlineStructure,
            AiStage::OutlineArcBeats,
            AiStage::OutlineBeatDetail,
        ], true);
    }

    private function baseProvider(AiStage $stage): string
    {
        $route = $this->find($stage);
        if ($route !== null) {
            return strtolower(trim($route->provider));
        }

        $settings = $this->settings->current()['settings'];
        $provider = data_get($settings, "stages.{$stage->value}.provider");

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

            $capabilityErrors = $this->modelCapabilityErrors($stage, $price, $reasoningEffort);
            foreach ($capabilityErrors as $key => $message) {
                $field = $key === 'reasoning_effort' ? 'reasoning_effort' : 'model_price_id';
                $errors["routes.{$stage->value}.{$field}"][] = $message;
            }
            if ($capabilityErrors !== []) {
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

    /** @return array<string, string> */
    private function modelCapabilityErrors(AiStage $stage, AIModelPrice $price, ?string $reasoningEffort): array
    {
        // 除 Embedding 外的 Stage 都会生成结构化结果，路由保存时统一拒绝未核实容量或能力的模型。
        return $price->generationSuitabilityErrors($stage, $reasoningEffort);
    }
}
