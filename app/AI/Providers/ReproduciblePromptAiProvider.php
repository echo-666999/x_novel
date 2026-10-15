<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\Models\GenerationRun;
use App\Services\GenerationSubstagePromptVersions;
use App\Services\PromptExecutionContract;
use Illuminate\Support\Facades\DB;

/** 在发出请求前把完整版本化 Prompt 冻结进对应 Generation Run。 */
final class ReproduciblePromptAiProvider implements AiProvider
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly PromptExecutionContract $contracts,
        private readonly GenerationSubstagePromptVersions $substagePromptVersions,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        // 非版本化诊断请求不属于可恢复生成链；Route 仍先完成自身身份校验。
        if (blank($request->promptVersion)) {
            return $this->provider->generate($request);
        }

        $runId = $request->metadata['generation_run_id'] ?? null;
        if (is_numeric($runId)) {
            $this->freeze((int) $runId, $request);
        }

        return $this->provider->generate($request);
    }

    private function freeze(int $runId, AiRequest $request): void
    {
        DB::transaction(function () use ($runId, $request): void {
            $run = GenerationRun::query()->lockForUpdate()->findOrFail($runId);
            $expectedPromptVersion = $this->substagePromptVersions->expectedFor($run, $request);
            if (blank($request->promptVersion)
                || blank($expectedPromptVersion)
                || ! hash_equals((string) $expectedPromptVersion, (string) $request->promptVersion)) {
                throw new AiProviderException(
                    'prompt_version_contract_mismatch',
                    'Provider 请求的 Prompt Version 与冻结 Generation Run 不一致。',
                    false,
                );
            }
            $routeKey = trim((string) ($request->metadata['route_key'] ?? ''));
            $substageRoute = $routeKey === ''
                ? null
                : data_get($run->context_snapshot, "generation_preferences.substage_routes.{$routeKey}");
            if ($routeKey !== '' && ! is_array($substageRoute)) {
                throw new AiProviderException(
                    'provider_run_route_missing',
                    "Generation Run 未冻结子阶段路由 [{$routeKey}]。",
                    false,
                );
            }
            $expectedProvider = is_array($substageRoute)
                ? ($substageRoute['provider'] ?? null)
                : $run->provider;
            $expectedModel = is_array($substageRoute)
                ? ($substageRoute['model'] ?? null)
                : $run->model_policy;
            if (filled($request->provider) && filled($expectedProvider)
                && ! hash_equals((string) $expectedProvider, (string) $request->provider)) {
                throw new AiProviderException('prompt_route_contract_mismatch', 'Prompt 请求 Provider 与冻结 Route 不一致。', false);
            }
            if (filled($expectedModel) && ! hash_equals((string) $expectedModel, $request->model)) {
                throw new AiProviderException('prompt_route_contract_mismatch', 'Prompt 请求 Model 与冻结 Route 不一致。', false);
            }

            $contract = $this->contracts->make($request);
            $snapshot = $run->context_snapshot ?? [];
            $existing = data_get($snapshot, 'prompt_execution');
            if (is_array($existing)
                && ! hash_equals((string) ($existing['checksum'] ?? ''), $contract['checksum'])) {
                throw new AiProviderException(
                    'prompt_execution_contract_mismatch',
                    '同一 Generation Run 的 Prompt 内容发生变化，必须建立新的 Run 和 Input Hash。',
                    false,
                );
            }

            $snapshot['prompt_execution'] = $contract;
            $run->update(['context_snapshot' => $snapshot]);
        }, 3);
    }
}
