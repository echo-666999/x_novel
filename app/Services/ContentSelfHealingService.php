<?php

namespace App\Services;

use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\Enums\ArtifactType;
use App\Enums\ContentRepairType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\AIModelPrice;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\UsageRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 为内容修复建立可追踪的 Run/Artifact，并在每次 Provider 请求前执行次数、费用和时间硬限制。
 */
final class ContentSelfHealingService
{
    public const CONTRACT_VERSION = 'content-self-healing-v1';

    public function __construct(
        private readonly TokenBudget $tokenBudget,
        private readonly GenerationSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $diagnostics
     */
    public function start(Chapter $chapter, ContentRepairType $type, array $source, array $diagnostics = []): GenerationRun
    {
        $run = DB::transaction(function () use ($chapter, $type, $source, $diagnostics): GenerationRun {
            $chapter = Chapter::query()->lockForUpdate()->with(['novel.canonicalStateVersion'])->findOrFail($chapter->getKey());
            $active = $this->activeForChapter($chapter);
            if ($active !== null) {
                if (data_get($active->context_snapshot, 'repair_type') === $type->value
                    && data_get($active->context_snapshot, 'source') === $source) {
                    return $active;
                }

                $this->fail($active, 'content_repair_escalated', '修复过程中出现更高层级问题，旧修复已结束并保留诊断。', [
                    'next_repair_type' => $type->value,
                ]);
            }

            $limits = $this->limits($type);
            $attempts = GenerationRun::query()
                ->where('chapter_id', $chapter->getKey())
                ->where('stage', GenerationStage::ContentRepair)
                ->where('scope_type', 'content_self_healing')
                ->where('context_snapshot->repair_type', $type->value)
                ->count();

            if ($attempts >= $limits['max_attempts']) {
                $message = "{$type->getLabel()}已达到 {$limits['max_attempts']} 次上限。";

                return $this->recordRefusal($chapter, $type, $source, $diagnostics, $limits, $message);
            }

            $attempt = $attempts + 1;
            $context = [
                'contract_version' => self::CONTRACT_VERSION,
                'repair_type' => $type->value,
                'repair_label' => $type->getLabel(),
                'repair_attempt' => $attempt,
                'limits' => $limits,
                'source' => $source,
                'diagnostics' => $diagnostics,
                'baseline_chapter_cost' => $this->chapterCost($chapter),
                'currency' => $this->settings->currency(),
            ];
            $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $key = 'content-repair:'.$type->value.':'.$chapter->getKey().':'.$attempt.':'.hash('sha256', $encoded);
            $run = GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scope_type' => 'content_self_healing',
                'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::ContentRepair,
                'status' => RunStatus::Running,
                'attempt' => $attempt,
                'idempotency_key' => $key,
                'input_hash' => hash('sha256', $encoded),
                'state_version' => $chapter->novel->canonicalStateVersion?->version,
                'bible_version' => $chapter->novel->currentBible()->value('version'),
                'prompt_version' => self::CONTRACT_VERSION,
                'provider' => 'deterministic',
                'model_policy' => 'deterministic',
                'context_snapshot' => $context,
                'started_at' => now(),
            ]);
            $this->createContextArtifact($run, $context);

            return $run;
        });

        if ($run->scope_type === 'content_self_healing_limit') {
            throw new AiProviderException(
                'content_repair_attempt_limit_exceeded',
                (string) $run->error_message,
                false,
            );
        }

        return $run;
    }

    public function activeForChapter(Chapter $chapter): ?GenerationRun
    {
        return GenerationRun::query()
            ->where('chapter_id', $chapter->getKey())
            ->where('stage', GenerationStage::ContentRepair)
            ->where('scope_type', 'content_self_healing')
            ->where('status', RunStatus::Running)
            ->latest('id')
            ->first();
    }

    public function type(GenerationRun $run): ContentRepairType
    {
        return ContentRepairType::from((string) data_get($run->context_snapshot, 'repair_type'));
    }

    /** 把修复诊断放入下一次规划上下文；模型只看到修复所需内容，不看到内部 Run 身份。 */
    public function planningContext(GenerationRun $run): array
    {
        return [
            'type' => $this->type($run)->value,
            'attempt' => (int) data_get($run->context_snapshot, 'repair_attempt', 1),
            'diagnostics' => (array) data_get($run->context_snapshot, 'diagnostics', []),
            'restriction' => $this->type($run) === ContentRepairType::CriticalForeshadowing
                ? '列出的 Critical 逾期伏笔只能规划 pay_off；其他到期伏笔仍按各自 allowed_model_actions 处理。不得延期、放弃、只强化 Critical 逾期项或新增无关故事义务。'
                : null,
        ];
    }

    public function assertCanRequest(AiRequest $request): void
    {
        $chapterId = $request->metadata['chapter_id'] ?? null;
        if (! is_numeric($chapterId)) {
            return;
        }

        $chapter = Chapter::query()->find((int) $chapterId);
        $run = $chapter === null ? null : $this->activeForChapter($chapter);
        if ($run === null) {
            return;
        }

        $this->assertWithinLimits($run);
        $limits = $this->limits($this->type($run));
        $price = AIModelPrice::query()
            ->where('provider', strtolower(trim((string) $request->provider)))
            ->where('model', trim($request->model))
            ->where('currency', $this->settings->currency())
            ->where('is_enabled', true)
            ->first();
        if ($price === null) {
            $this->failAndThrow($run, 'content_repair_cost_unverifiable', '当前修复请求缺少系统计价币种下的有效模型价格，无法执行费用硬限制。');
        }

        $inputTokens = $this->tokenBudget->estimate([
            'messages' => $request->resolvedMessages(),
            'response_schema' => $request->responseSchema,
        ]);
        $projected = (
            $inputTokens * (float) $price->input_price
            + max(0, $request->maxTokens) * (float) $price->output_price
        ) / max(1, (int) $price->billing_unit);
        $used = $this->costSince($run);
        if ($used + $projected > $limits['max_cost']) {
            $this->failAndThrow(
                $run,
                'content_repair_cost_limit_exceeded',
                sprintf('内容修复费用预检失败：已用 %.6f，本次最大可能费用 %.6f，上限 %.6f %s。', $used, $projected, $limits['max_cost'], $this->settings->currency()),
                ['used_cost' => $used, 'projected_request_cost' => $projected],
            );
        }
    }

    public function assertWithinLimits(GenerationRun $run): void
    {
        if ($run->status !== RunStatus::Running) {
            throw new AiProviderException('content_repair_not_active', '内容修复 Run 已不在运行状态。', false);
        }

        $limits = $this->limits($this->type($run));
        $elapsed = $run->started_at?->diffInSeconds(now()) ?? PHP_INT_MAX;
        if ($elapsed > $limits['max_elapsed_seconds']) {
            $this->failAndThrow($run, 'content_repair_time_limit_exceeded', "内容修复已超过 {$limits['max_elapsed_seconds']} 秒上限。", ['elapsed_seconds' => $elapsed]);
        }

        $used = $this->costSince($run);
        if ($used >= $limits['max_cost']) {
            $this->failAndThrow($run, 'content_repair_cost_limit_exceeded', "内容修复已达到 {$limits['max_cost']} {$this->settings->currency()} 费用上限。", ['used_cost' => $used]);
        }
    }

    public function completeIfPlanScoped(GenerationRun $run): void
    {
        if ($this->type($run)->completesWithPlan()) {
            $this->succeed($run, 'plan_validated');
        }
    }

    public function completeAfterReview(Chapter $chapter): void
    {
        $run = $this->activeForChapter($chapter);
        if ($run !== null) {
            $this->succeed($run, 'review_passed');
        }
    }

    /**
     * Scheduler 使用自愈合同本身的时间/费用上限收口无人继续推进的修复，不把协调 Run 误判成 Worker 丢失。
     *
     * @return Collection<int, GenerationRun>
     */
    public function expireExceeded(): Collection
    {
        $expired = collect();
        GenerationRun::query()
            ->where('stage', GenerationStage::ContentRepair)
            ->where('scope_type', 'content_self_healing')
            ->where('status', RunStatus::Running)
            ->orderBy('id')
            ->each(function (GenerationRun $run) use ($expired): void {
                try {
                    $this->assertWithinLimits($run);
                } catch (AiProviderException) {
                    $expired->push($run->fresh());
                }
            });

        return $expired;
    }

    public function fail(GenerationRun $run, string $code, string $message, array $metadata = []): void
    {
        if ($run->status !== RunStatus::Running) {
            return;
        }

        $run->update([
            'status' => RunStatus::Failed,
            'error_code' => $code,
            'error_message' => $message,
            'error_retryable' => false,
            'error_metadata' => $metadata + ['repair_type' => data_get($run->context_snapshot, 'repair_type')],
            'finished_at' => now(),
        ]);
    }

    private function succeed(GenerationRun $run, string $result): void
    {
        if ($run->status !== RunStatus::Running) {
            return;
        }

        $snapshot = $run->context_snapshot ?? [];
        $snapshot['result'] = $result;
        $snapshot['actual_cost'] = $this->costSince($run);
        $run->update(['status' => RunStatus::Succeeded, 'context_snapshot' => $snapshot, 'finished_at' => now()]);
    }

    /** @return array{max_attempts: int, max_cost: float, max_elapsed_seconds: int} */
    private function limits(ContentRepairType $type): array
    {
        $configured = (array) config("generation.content_self_healing.{$type->value}", []);
        $limits = [
            'max_attempts' => (int) ($configured['max_attempts'] ?? 0),
            'max_cost' => (float) ($configured['max_cost'] ?? 0),
            'max_elapsed_seconds' => (int) ($configured['max_elapsed_seconds'] ?? 0),
        ];
        if ($limits['max_attempts'] < 1 || $limits['max_cost'] <= 0 || $limits['max_elapsed_seconds'] < 1) {
            throw new AiProviderException('content_repair_limits_invalid', "{$type->getLabel()}缺少有效的次数、费用或时间上限。", false);
        }

        return $limits;
    }

    private function costSince(GenerationRun $run): float
    {
        $current = (float) UsageRecord::query()
            ->where('chapter_id', $run->chapter_id)
            ->sum('estimated_cost');
        $baseline = (float) data_get($run->context_snapshot, 'baseline_chapter_cost', 0);

        return max(0, $current - $baseline);
    }

    private function chapterCost(Chapter $chapter): float
    {
        return (float) UsageRecord::query()->where('chapter_id', $chapter->getKey())->sum('estimated_cost');
    }

    private function failAndThrow(GenerationRun $run, string $code, string $message, array $metadata = []): never
    {
        $this->fail($run, $code, $message, $metadata);

        throw new AiProviderException($code, $message, false);
    }

    /** @param array<string, mixed> $context */
    private function createContextArtifact(GenerationRun $run, array $context): void
    {
        $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $version = (int) GenerationArtifact::query()
            ->where('type', ArtifactType::Context)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $run->chapter_id))
            ->max('version') + 1;
        $run->artifacts()->create([
            'type' => ArtifactType::Context,
            'version' => $version,
            'content' => $encoded,
            'data' => $context,
            'checksum' => hash('sha256', $encoded),
        ]);
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $diagnostics @param array<string, mixed> $limits */
    private function recordRefusal(Chapter $chapter, ContentRepairType $type, array $source, array $diagnostics, array $limits, string $message): GenerationRun
    {
        $context = [
            'contract_version' => self::CONTRACT_VERSION,
            'repair_type' => $type->value,
            'source' => $source,
            'diagnostics' => $diagnostics,
            'limits' => $limits,
            'refused' => true,
        ];
        $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $key = 'content-repair-refused:'.$type->value.':'.$chapter->getKey().':'.hash('sha256', $encoded);
        $run = GenerationRun::query()->firstOrCreate(['idempotency_key' => $key], [
            'novel_id' => $chapter->novel_id,
            'chapter_id' => $chapter->getKey(),
            'scope_type' => 'content_self_healing_limit',
            'scope_id' => $chapter->getKey(),
            'stage' => GenerationStage::ContentRepair,
            'status' => RunStatus::Failed,
            'attempt' => $limits['max_attempts'] + 1,
            'input_hash' => hash('sha256', $encoded),
            'state_version' => $chapter->novel->canonicalStateVersion?->version,
            'bible_version' => $chapter->novel->currentBible()->value('version'),
            'prompt_version' => self::CONTRACT_VERSION,
            'provider' => 'deterministic',
            'model_policy' => 'deterministic',
            'context_snapshot' => $context,
            'error_code' => 'content_repair_attempt_limit_exceeded',
            'error_message' => $message,
            'error_retryable' => false,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        if ($run->wasRecentlyCreated) {
            $this->createContextArtifact($run, $context);
        }

        return $run;
    }
}
