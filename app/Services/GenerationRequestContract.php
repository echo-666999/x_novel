<?php

namespace App\Services;

use App\AI\AiSettingsService;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\Models\AIModelPrice;
use App\Models\AIProviderConnection;
use App\Models\GenerationRun;

/** 新 Run 建立前冻结真实请求；能力、价格和 Prompt 各保留其依据，不保存秘密。 */
final class GenerationRequestContract
{
    public function freeze(AiRequest $request, int $reasoningReserve = 0): array
    {
        app(AiSettingsService::class)->assertProviderAvailable((string) $request->provider);
        if (blank($request->promptVersion)) {
            throw new AiProviderException('prompt_version_missing', '新链请求必须提供 Prompt 版本。', false);
        }
        $connection = AIProviderConnection::where('provider', $request->provider)->firstOrFail();
        $currency = app(GenerationSettingsService::class)->currency();
        $profile = AIModelPrice::findEnabledForRoute((string) $request->provider, $request->model);
        if (! $profile) {
            throw new AiProviderException('model_capacity_unknown', '缺少已核实的模型能力，请配置上下文和输出容量后重试。', false);
        }
        $capacity = $profile->capacitySnapshot();
        // 序列化后的字节上界包含 System、Schema；另留协议封装余量，不靠删硬约束过检。
        $input = strlen(json_encode(['messages' => $request->resolvedMessages(), 'schema' => $request->responseSchema], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $margin = (int) config('generation.execution.protocol_margin_tokens', 256);
        $required = $input + $margin + $request->maxTokens;
        if ($request->maxTokens < 1 || $reasoningReserve < 0 || $reasoningReserve >= $request->maxTokens
            || $capacity['max_output_tokens'] < $request->maxTokens || $capacity['context_window_tokens'] < $required) {
            throw new AiProviderException('request_capacity_exceeded', "容量不足：输入上界 {$input}、封装余量 {$margin}、总输出 {$request->maxTokens}，合计 {$required} Token；请调整模型或分段。", false);
        }
        if (($request->responseSchema !== null && ! $capacity['supports_structured_output'])
            || ($request->reasoningEffort !== null && ! $capacity['supports_reasoning_effort'])) {
            throw new AiProviderException('model_capability_mismatch', '模型未声明支持本请求的结构化输出或推理参数。', false);
        }

        return ['schema_version' => 1, 'provider' => $request->provider, 'model' => $request->model,
            'connection_id' => $connection->id, 'connection' => $connection->only(['base_url', 'credential_ref', 'connect_timeout', 'timeout']),
            'configuration_source' => $request->metadata['configuration_source'] ?? 'resolved_request',
            'prompt_version' => $request->promptVersion, 'prompt' => app(PromptExecutionContract::class)->make($request),
            'capacity' => $capacity, 'input_upper_bound' => $input, 'protocol_margin_tokens' => $margin,
            'output_tokens' => $request->maxTokens - $reasoningReserve, 'reasoning_reserve_tokens' => $reasoningReserve,
            'currency' => $currency,
            // 能力记录可以使用其他币种；费用不得静默换汇，缺同币种价格时保持未知。
            'price' => $profile->currency === $currency ? $profile->only(['id', 'provider', 'model', 'currency', 'billing_unit', 'input_price', 'cached_input_price', 'output_price', 'updated_at']) : [],
        ];
    }

    public function assertMatches(GenerationRun $run, AiRequest $request): void
    {
        $contract = $run->execution_contract;
        if (($contract['provider'] ?? null) !== $request->provider || ($contract['model'] ?? null) !== $request->model
            || ! hash_equals((string) data_get($contract, 'prompt.checksum', ''), app(PromptExecutionContract::class)->make($request)['checksum'])) {
            throw new AiProviderException('request_contract_changed', '请求与冻结合同不一致；修改模型或提示词必须建立新尝试。', false);
        }
        // 凭据撤销立即生效，连接地址变化也不能把旧任务发往未经原合同批准的端点。
        app(AiSettingsService::class)->assertProviderAvailable((string) $request->provider);
        $connection = AIProviderConnection::find($run->connection_id);
        if (! $connection || app(GenerationInputIdentity::class)->hash($connection->only(['base_url', 'credential_ref', 'connect_timeout', 'timeout'])) !== app(GenerationInputIdentity::class)->hash($contract['connection'] ?? [])) {
            throw new AiProviderException('connection_contract_changed', '连接配置已变化，请按新配置建立尝试。', false);
        }
    }
}
