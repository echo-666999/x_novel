<?php

namespace App\AI;

use App\AI\Data\AiRequest;
use App\AI\Data\EmbeddingRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\Exceptions\BudgetExceededException;
use App\Enums\RunStatus;
use App\Models\AIModelPrice;
use App\Models\AIProviderConnection;
use App\Models\Chapter;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\SystemSetting;
use App\Models\UsageRecord;
use App\Services\EmergencyStopService;
use App\Services\GenerationCostPolicy;
use App\Services\GenerationInputIdentity;
use App\Services\GenerationRequestContract;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 请求前建账、原子预留与未知结果对账，是全部收费路径的唯一账本边界。 */
final class RequestUsageLedger
{
    public function prepare(AiRequest|EmbeddingRequest $request): UsageRecord
    {
        return DB::transaction(function () use ($request): UsageRecord {
            $global = SystemSetting::whereKey(SystemSetting::GENERATION)->lockForUpdate()->firstOrFail();
            $metadata = $request->metadata;
            $run = isset($metadata['generation_run_id']) ? GenerationRun::findOrFail($metadata['generation_run_id']) : null;
            $novelId = $run?->novel_id ?? ($metadata['novel_id'] ?? null);
            $chapterId = $run?->chapter_id ?? ($metadata['chapter_id'] ?? null);
            $chapter = $chapterId ? Chapter::findOrFail($chapterId) : null;
            $novelId ??= $chapter?->novel_id;
            $novel = $novelId ? Novel::whereKey($novelId)->lockForUpdate()->firstOrFail() : null;
            $task = $run?->generation_task_id ? NovelGenerationTask::whereKey($run->generation_task_id)->lockForUpdate()->firstOrFail() : null;
            $issue = $run?->issue_id ? GenerationIssue::whereKey($run->issue_id)->lockForUpdate()->firstOrFail() : null;
            foreach (['novel_id' => $novelId, 'chapter_id' => $chapterId] as $key => $expected) {
                if (isset($metadata[$key]) && (int) $metadata[$key] !== (int) $expected) {
                    throw new \LogicException('请求来源归属与Run不一致。');
                }
            }
            if ($chapter && $chapter->novel_id !== $novelId) {
                throw new \LogicException('章节不属于请求小说。');
            }
            if ($task && (! in_array($task->status, ['running', 'queued'], true) || (int) $novel->current_generation_task_id !== $task->id)) {
                throw new AiProviderException('task_not_running', '任务已暂停、结束或失去推进权。', false);
            }
            if ($run && UsageRecord::where('generation_run_id', $run->id)->exists()) {
                throw new AiProviderException('request_already_recorded', '该Run已有实际请求记录；未知结果必须按新尝试恢复。', false);
            }
            $provider = $request instanceof AiRequest ? $request->provider : ($metadata['provider'] ?? 'openai');
            if (! $provider) {
                throw new \LogicException('请求必须先解析实际Provider。');
            }
            $currency = data_get($global->value, 'currency', 'USD');
            $price = AIModelPrice::where('provider', $provider)->where('model', $request->model)->where('currency', $currency)->where('is_enabled', true)->first();
            $snapshot = $price ? $price->only(['id', 'provider', 'model', 'currency', 'billing_unit', 'input_price', 'cached_input_price', 'output_price', 'updated_at']) : [];
            if ($run?->pipeline_version === 'pipeline-v1') {
                if (! $request instanceof AiRequest) {
                    throw new \LogicException('新链 Embedding 需独立请求身份，不能借用正文 Run。');
                }
                app(GenerationRequestContract::class)->assertMatches($run, $request);
                $this->assertExecution($run, $metadata, $task);
                $snapshot = $run->execution_contract['price'] ?? [];
                $currency = $run->execution_contract['currency'];
            }
            // 包含Schema与System的完整序列化字节数作为保守输入上界，不删硬约束省费用。
            $input = $request instanceof AiRequest ? strlen(json_encode(['messages' => $request->resolvedMessages(), 'schema' => $request->responseSchema], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) : strlen($request->input);
            $upper = $this->cost($snapshot, $input + (int) config('generation.execution.protocol_margin_tokens', 256), $request instanceof AiRequest ? $request->maxTokens : 0, 0);
            $scopes = $this->scopes($global, $novel, $task, $chapter, $issue);
            if ($scopes !== [] && $currency !== data_get($global->value, 'currency', 'USD')) {
                throw new AiProviderException('cap_currency_changed', '上限币种与冻结价格不同，请建立新配置尝试，系统不自动换汇。', false);
            }
            $this->assertCaps($scopes, $currency, $upper);

            return UsageRecord::create(['request_key' => (string) Str::uuid(), 'generation_run_id' => $run?->id,
                'novel_id' => $novel?->id, 'chapter_id' => $chapter?->id, 'generation_task_id' => $task?->id, 'issue_id' => $issue?->id,
                'provider' => $provider, 'model' => $request->model, 'status' => 'prepared', 'cost_status' => 'unknown',
                'currency' => $currency, 'price_snapshot' => $snapshot, 'reserved_amount' => $upper, 'sent_at' => now(),
                'cap_snapshot' => $scopes, 'request_metadata' => ['kind' => $request instanceof AiRequest ? 'generation' : 'embedding', 'stage' => $metadata['stage'] ?? null, 'substage' => $metadata['substage'] ?? null, 'route_key' => $metadata['route_key'] ?? null, 'execution_token' => $metadata['lease_token'] ?? null, 'control_version' => $metadata['control_version'] ?? null],
                'input_tokens' => null, 'output_tokens' => null, 'cached_tokens' => null, 'reasoning_tokens' => null, 'latency_ms' => null, 'estimated_cost' => null]);
        });
    }

    /** 发送前再次检查暂停和连接授权；prepared阶段失败可确认未发送。 */
    public function send(UsageRecord $usage): void
    {
        DB::transaction(function () use ($usage): void {
            $global = SystemSetting::whereKey(SystemSetting::GENERATION)->lockForUpdate()->firstOrFail();
            $novel = $usage->novel_id ? Novel::whereKey($usage->novel_id)->lockForUpdate()->firstOrFail() : null;
            $task = $usage->generation_task_id ? NovelGenerationTask::whereKey($usage->generation_task_id)->lockForUpdate()->firstOrFail() : null;
            $issue = $usage->issue_id ? GenerationIssue::whereKey($usage->issue_id)->lockForUpdate()->firstOrFail() : null;
            $run = $usage->generation_run_id ? GenerationRun::whereKey($usage->generation_run_id)->lockForUpdate()->firstOrFail() : null;
            $usage = UsageRecord::whereKey($usage->id)->lockForUpdate()->firstOrFail();
            if ($task && (! in_array($task->status, ['queued', 'running'], true) || (int) $novel->current_generation_task_id !== $task->id)) {
                throw new AiProviderException('task_not_running', '请求尚未发送，任务已暂停或失去推进权。', false);
            }
            if ($run?->pipeline_version === 'pipeline-v1') {
                $this->assertExecution($run, ['lease_token' => $usage->request_metadata['execution_token'], 'control_version' => $usage->request_metadata['control_version']], $task);
                app(AiSettingsService::class)->assertProviderAvailable($usage->provider);
                $connection = AIProviderConnection::find($run->connection_id);
                if (! $connection || app(GenerationInputIdentity::class)->hash($connection->only(['base_url', 'credential_ref', 'connect_timeout', 'timeout'])) !== app(GenerationInputIdentity::class)->hash($run->execution_contract['connection'])) {
                    throw new AiProviderException('connection_contract_changed', '发送前连接已变化，请重新建立尝试。', false);
                }
            }
            if ($usage->status !== 'prepared') {
                throw new \LogicException('请求不是待发送状态。');
            }
            // 用户在排队期间调整的上限立即生效；复核排除本次预留，避免重复计费。
            $scopes = $this->scopes($global, $novel, $task, $usage->chapter_id ? Chapter::findOrFail($usage->chapter_id) : null, $issue);
            app(EmergencyStopService::class)->assertProviderRequestsAllowed();
            if ($scopes !== [] && $usage->currency !== data_get($global->value, 'currency', 'USD')) {
                throw new AiProviderException('cap_currency_changed', '金额授权币种已变化，本次请求未发送。', false);
            }
            $this->assertCaps($scopes, $usage->currency, $usage->reserved_amount, $usage->id);
            $usage->cap_snapshot = $scopes;
            $usage->update(['status' => 'in_flight', 'sent_at' => now()]);
        });
    }

    /** 响应丢失保留占用，只有能证明未发出的错误才能释放。 */
    public function failed(UsageRecord $usage, bool $notSent = false): void
    {
        UsageRecord::whereKey($usage->id)->whereIn('status', ['prepared', 'in_flight'])->update($notSent
            ? ['status' => 'not_sent', 'reserved_amount' => null]
            : ['status' => 'unknown']);
    }

    /** 有响应但没有用量证据，不能结算为零或释放未知占用。 */
    public function unknownResponse(UsageRecord $usage, ?string $requestId, string $model, int $latency): UsageRecord
    {
        return DB::transaction(function () use ($usage, $requestId, $model, $latency): UsageRecord {
            $entry = UsageRecord::whereKey($usage->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'settled') {
                $entry->update(['status' => 'unknown', 'cost_status' => 'unknown', 'request_id' => $requestId, 'latency_ms' => $latency,
                    'request_metadata' => [...$entry->request_metadata, 'actual_model' => $model, 'usage_available' => false]]);
            }

            return $entry;
        });
    }

    /** 依据冻结价格计算费用，未知保持null；使用十进制运算避免浮点累计透支。 */
    public function cost(array $price, int $input, int $output, int $cached): ?string
    {
        if (! isset($price['billing_unit'],$price['input_price'],$price['output_price'])) {
            return null;
        }

        return (string) BigDecimal::of((string) $price['input_price'])->multipliedBy(max(0, $input - $cached))
            ->plus(BigDecimal::of((string) ($price['cached_input_price'] ?? $price['input_price']))->multipliedBy($cached))
            ->plus(BigDecimal::of((string) ($price['output_price'] ?? 0))->multipliedBy($output))
            ->dividedBy(max(1, (int) $price['billing_unit']), 8, RoundingMode::Ceiling);
    }

    /** 结算一次请求；晚到响应可以补账，但不能因此推进任务或覆盖旧费用证据。 */
    public function settle(UsageRecord $usage, array $metrics): UsageRecord
    {
        return DB::transaction(function () use ($usage, $metrics): UsageRecord {
            $entry = UsageRecord::whereKey($usage->id)->lockForUpdate()->firstOrFail();
            if ($entry->status === 'settled') {
                return $entry;
            }
            $cost = $this->cost($entry->price_snapshot, $metrics['input_tokens'], $metrics['output_tokens'], $metrics['cached_tokens']);
            $entry->update([...$metrics, 'estimated_cost' => $cost, 'cost_status' => $cost === null ? 'unknown' : 'estimated',
                'status' => 'settled', 'reserved_amount' => null, 'settled_at' => now()]);

            return $entry;
        });
    }

    private function scopes(SystemSetting $global, ?Novel $novel, ?NovelGenerationTask $task, ?Chapter $chapter, ?GenerationIssue $issue): array
    {
        $globalPolicy = app(GenerationCostPolicy::class)->normalize((array) data_get($global->value, 'budget', []));
        $novelPolicy = app(GenerationCostPolicy::class)->normalize((array) data_get($novel?->settings, 'budget', []));
        $scopes = [];
        foreach ([$globalPolicy, $novelPolicy] as $index => $policy) {
            foreach (GenerationCostPolicy::LIMITS as $key) {
                if (! $policy[$key.'_enabled']) {
                    continue;
                }
                $filter = match ($key) {
                    'daily_hard_limit' => ['sent_day' => now('UTC')->toDateString(), ...($index === 1 && $novel ? ['novel_id' => $novel->id] : [])],
                    'novel_total_limit' => $task ? ['generation_task_id' => $task->id] : ($novel ? ['novel_id' => $novel->id] : null),
                    'chapter_max_cost' => $chapter ? ['chapter_id' => $chapter->id] : null,
                    'issue_max_cost' => $issue ? ['issue_id' => $issue->id] : null,
                };
                if ($filter === null || ($index === 1 && ! $novel)) {
                    continue;
                }
                $scopes[] = ['scope' => $key, 'amount' => $policy[$key], 'filter' => $filter];
            }
        }

        return $scopes;
    }

    private function assertCaps(array $scopes, string $currency, ?string $upper, ?int $excludeId = null): void
    {
        foreach ($scopes as $scope) {
            if ($upper === null) {
                throw new AiProviderException('price_required_for_cap', '已启用金额硬上限，但当前模型缺少可靠价格。', false);
            }
            $query = UsageRecord::where('currency', $currency)->where('status', '<>', 'not_sent')->when($excludeId, fn ($query) => $query->whereKeyNot($excludeId));
            foreach ($scope['filter'] as $key => $value) {
                if ($key === 'sent_day') {
                    $query->whereDate('sent_at', $value);
                } else {
                    $query->where($key, $value);
                }
            }
            $total = BigDecimal::of('0');
            foreach ($query->get() as $entry) {
                $amount = $entry->status === 'settled' ? ($entry->actual_cost ?? $entry->estimated_cost) : $entry->reserved_amount;
                if ($amount === null) {
                    throw new AiProviderException('unknown_cost_under_cap', '已启用上限范围内有未知费用，请补价格或核销后继续。', false);
                }
                $total = $total->plus($amount);
            }
            if ($total->plus($upper)->isGreaterThan($scope['amount'])) {
                throw new BudgetExceededException($scope['scope'], (float) (string) $total->plus($upper), (float) $scope['amount']);
            }
        }
    }

    /** Worker 身份和用户控制版本属于发送授权，不随冻结模型而过期失效。 */
    private function assertExecution(GenerationRun $run, array $metadata, ?NovelGenerationTask $task): void
    {
        if ($run->status !== RunStatus::Running || ! $run->lease_expires_at?->isFuture()
            || ! hash_equals((string) $run->lease_token, (string) ($metadata['lease_token'] ?? ''))
            || $task?->control_version !== ($metadata['control_version'] ?? null)) {
            throw new AiProviderException('execution_authority_lost', '执行租约或任务控制版本已变化，本次请求未发送。', false);
        }
    }

    /** 人工核销需给出依据并追加历史；重复核销键复用结论，不清空原始请求证据。 */
    public function reconcile(int $usageId, string $operationKey, string $actualCost, string $reason, int $actorId): UsageRecord
    {
        if (! Str::isUuid($operationKey) || trim($reason) === '' || ! is_numeric($actualCost) || BigDecimal::of($actualCost)->isNegative()) {
            throw new \InvalidArgumentException('核销需要合法操作ID、非负金额和明确依据。');
        }

        return DB::transaction(function () use ($usageId, $operationKey, $actualCost, $reason, $actorId): UsageRecord {
            SystemSetting::whereKey(SystemSetting::GENERATION)->lockForUpdate()->firstOrFail();
            $usage = UsageRecord::whereKey($usageId)->lockForUpdate()->firstOrFail();
            $history = $usage->reconciliation_history ?? [];
            foreach ($history as $item) {
                if ($item['operation_key'] === $operationKey) {
                    if ($item['amount'] !== $actualCost || $item['reason'] !== $reason) {
                        throw new \LogicException('同一核销操作的内容发生变化。');
                    }

                    return $usage;
                }
            }
            $history[] = ['operation_key' => $operationKey, 'amount' => $actualCost, 'previous' => $usage->actual_cost,
                'reason' => $reason, 'actor_id' => $actorId, 'at' => now()->toIso8601String()];
            $usage->update(['actual_cost' => $actualCost, 'cost_status' => 'actual', 'status' => 'settled',
                'reserved_amount' => null, 'reconciled_at' => now(), 'reconciliation_history' => $history]);

            return $usage;
        });
    }
}
