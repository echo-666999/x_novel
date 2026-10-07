<?php

namespace App\Models;

use App\Enums\AiStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider',
    'model',
    'currency',
    'billing_unit',
    'context_window_tokens',
    'max_output_tokens',
    'supports_structured_output',
    'supports_reasoning_effort',
    'input_price',
    'cached_input_price',
    'output_price',
    'is_enabled',
])]
class AIModelPrice extends Model
{
    protected $table = 'ai_model_prices';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_unit' => 'integer',
            'context_window_tokens' => 'integer',
            'max_output_tokens' => 'integer',
            'supports_structured_output' => 'boolean',
            'supports_reasoning_effort' => 'boolean',
            'input_price' => 'decimal:12',
            'cached_input_price' => 'decimal:12',
            'output_price' => 'decimal:12',
            'is_enabled' => 'boolean',
        ];
    }

    public static function findEnabledForRoute(string $provider, string $model): ?self
    {
        $currency = strtoupper((string) config('ai.cost.currency', 'USD'));

        // 路由身份是 Provider + Model；多币种价格存在时优先采用系统计价币种的模型资料。
        return self::query()
            ->where('provider', strtolower(trim($provider)))
            ->where('model', trim($model))
            ->where('is_enabled', true)
            ->orderByRaw('currency = ? desc', [$currency])
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{capacity?: string, structured_output?: string, reasoning_effort?: string}
     */
    public function outlineSuitabilityErrors(?string $reasoningEffort = null): array
    {
        return $this->generationSuitabilityErrors(AiStage::OutlineFoundation, $reasoningEffort);
    }

    /**
     * @return array{capacity?: string, structured_output?: string, reasoning_effort?: string}
     */
    public function generationSuitabilityErrors(AiStage $stage, ?string $reasoningEffort = null): array
    {
        $errors = [];
        if ($stage === AiStage::Embedding) {
            return $errors;
        }

        // 所有文本生成阶段都会提交结构化响应，不能只在 Outline 保存时核实容量与能力。
        if (($this->context_window_tokens ?? 0) < 1 || ($this->max_output_tokens ?? 0) < 1) {
            $errors['capacity'] = '该模型缺少已核实的上下文窗口或最大输出 Token 容量。';
        }
        if (! $this->supports_structured_output) {
            $errors['structured_output'] = "该模型未声明支持结构化输出，不能用于{$stage->getLabel()}。";
        }
        if ($reasoningEffort !== null && ! $this->supports_reasoning_effort) {
            $errors['reasoning_effort'] = '该模型未声明支持 reasoning_effort，不能配置推理程度。';
        }

        return $errors;
    }

    /** @return array<string, int|string|bool> */
    public function outlineCapacitySnapshot(): array
    {
        return $this->capacitySnapshot();
    }

    /** @return array<string, int|string|bool> */
    public function capacitySnapshot(): array
    {
        return [
            'model_price_id' => (int) $this->getKey(),
            'provider' => strtolower(trim($this->provider)),
            'model' => trim($this->model),
            'context_window_tokens' => (int) $this->context_window_tokens,
            'max_output_tokens' => (int) $this->max_output_tokens,
            'supports_structured_output' => (bool) $this->supports_structured_output,
            'supports_reasoning_effort' => (bool) $this->supports_reasoning_effort,
        ];
    }
}
