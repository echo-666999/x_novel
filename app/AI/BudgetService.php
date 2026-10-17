<?php

namespace App\AI;

use App\AI\Data\AiRequest;
use App\AI\Data\BudgetUsage;
use App\AI\Exceptions\BudgetExceededException;
use App\Models\AIModelPrice;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\SystemSetting;
use App\Models\UsageRecord;
use App\Services\GenerationSettingsService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BudgetService
{
    public function __construct(private readonly GenerationSettingsService $settings) {}

    public function assertWithinNovelLimits(Novel $novel): void
    {
        $this->assertAvailable($this->dailyUsage());
        $this->assertAvailable($this->novelUsage($novel));
    }

    public function assertWithinChapterLimits(Chapter $chapter): void
    {
        $this->assertWithinNovelLimits($chapter->novel);
        $this->assertAvailable($this->chapterUsage($chapter));
    }

    public function assertCanRequest(AiRequest $request): void
    {
        $projected = $this->projectedCost($request);
        $this->assertAvailable($this->dailyUsage(), $projected, $this->reservedCost());

        [$novel, $chapter] = $this->resolveContext($request);

        if ($novel !== null) {
            $this->assertAvailable($this->novelUsage($novel), $projected, $this->reservedCost($novel->getKey()));
        }

        if ($chapter !== null) {
            $this->assertAvailable($this->chapterUsage($chapter, $novel), $projected, $this->reservedCost($novel?->getKey(), $chapter->getKey()));
        }
    }

    /** 原子写入本次请求的最大费用预留；返回需要在 finally 中释放的 Run ID。 */
    public function reserve(AiRequest $request): ?int
    {
        $runId = $request->metadata['generation_run_id'] ?? null;
        if (! is_numeric($runId)) {
            $this->assertCanRequest($request);

            return null;
        }

        return DB::transaction(function () use ($request, $runId): int {
            // 所有费用预留先锁同一全局设置行，使每日限额在多小说并发时也不会透支。
            SystemSetting::query()->lockForUpdate()->findOrFail(SystemSetting::GENERATION);
            $run = GenerationRun::query()->lockForUpdate()->find((int) $runId);
            if ($run === null) {
                // Route 层负责返回稳定的 provider_run_missing；预算装饰器不能掩盖该诊断。
                return 0;
            }
            [$novel, $chapter] = $this->resolveContext($request);
            $projected = $this->projectedCost($request, requirePrice: true);

            $this->assertAvailable($this->dailyUsage(), $projected, $this->reservedCost(excludeRunId: $run->getKey()));
            if ($novel !== null) {
                $this->assertAvailable($this->novelUsage($novel), $projected, $this->reservedCost($novel->getKey(), excludeRunId: $run->getKey()));
            }
            if ($chapter !== null) {
                $this->assertAvailable(
                    $this->chapterUsage($chapter, $novel),
                    $projected,
                    $this->reservedCost($novel?->getKey(), $chapter->getKey(), $run->getKey()),
                );
            }

            $run->update(['reserved_cost' => $projected]);

            return $run->getKey();
        }, 3) ?: null;
    }

    public function release(?int $runId): void
    {
        if ($runId !== null) {
            GenerationRun::query()->whereKey($runId)->update(['reserved_cost' => 0]);
        }
    }

    public function dailyUsage(): BudgetUsage
    {
        return new BudgetUsage(
            scope: 'daily',
            used: (float) UsageRecord::query()->whereDate('created_at', today())->sum('estimated_cost'),
            limit: data_get($this->settings->budgetSettings(), 'daily_hard_limit_enabled', false) ? $this->limit(data_get($this->settings->budgetSettings(), 'daily_hard_limit')) : null,
        );
    }

    public function novelUsage(Novel $novel): BudgetUsage
    {
        return new BudgetUsage(
            scope: 'novel',
            used: (float) UsageRecord::query()->where('novel_id', $novel->getKey())->sum('estimated_cost'),
            limit: $this->novelLimit($novel, 'novel_total_limit'),
        );
    }

    public function chapterUsage(Chapter $chapter, ?Novel $novel = null): BudgetUsage
    {
        $novel ??= $chapter->novel;

        return new BudgetUsage(
            scope: 'chapter',
            used: (float) UsageRecord::query()->where('chapter_id', $chapter->getKey())->sum('estimated_cost'),
            limit: $this->novelLimit($novel, 'chapter_max_cost'),
        );
    }

    private function assertAvailable(BudgetUsage $usage, float $projected = 0, float $reserved = 0): void
    {
        $committed = $usage->used + max(0, $reserved);
        if ($usage->reached() || ($usage->limit !== null && $committed + max(0, $projected) > $usage->limit)) {
            throw new BudgetExceededException($usage->scope, round($committed + max(0, $projected), 6), $usage->limit);
        }
    }

    public function projectedCost(AiRequest $request, bool $requirePrice = false): float
    {
        $provider = strtolower(trim((string) $request->provider));
        $price = $provider === '' ? null : AIModelPrice::query()
            ->where('provider', $provider)
            ->where('model', $request->model)
            ->where('currency', $this->settings->currency())
            ->where('is_enabled', true)
            ->first();
        if ($price === null) {
            if ($requirePrice && collect($this->settings->budgetSettings())->contains(fn (mixed $limit): bool => $this->limit($limit) !== null)) {
                throw new InvalidArgumentException('启用费用 Hard Limit 时，Provider Route 必须存在同币种的已启用模型价格。');
            }

            return 0.0;
        }

        // UTF-8 字节数是无需 Provider tokenizer 的保守输入 Token 上界；输出使用请求允许的最大值。
        $inputUpperBound = strlen(json_encode($request->resolvedMessages(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $unit = max(1, (int) $price->billing_unit);

        return round((
            $inputUpperBound * (float) ($price->input_price ?? 0)
            + max(0, $request->maxTokens) * (float) ($price->output_price ?? 0)
        ) / $unit, 6);
    }

    private function reservedCost(?int $novelId = null, ?int $chapterId = null, ?int $excludeRunId = null): float
    {
        return (float) GenerationRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->when($novelId !== null, fn ($query) => $query->where('novel_id', $novelId))
            ->when($chapterId !== null, fn ($query) => $query->where('chapter_id', $chapterId))
            ->when($excludeRunId !== null, fn ($query) => $query->whereKeyNot($excludeRunId))
            ->sum('reserved_cost');
    }

    /** @return array{0: Novel|null, 1: Chapter|null} */
    private function resolveContext(AiRequest $request): array
    {
        $novelId = $request->metadata['novel_id'] ?? null;
        $chapterId = $request->metadata['chapter_id'] ?? null;
        $novel = is_numeric($novelId) ? Novel::query()->find((int) $novelId) : null;
        $chapter = is_numeric($chapterId) ? Chapter::query()->find((int) $chapterId) : null;

        if (is_numeric($novelId) && $novel === null) {
            throw new InvalidArgumentException('AI request Novel context does not exist.');
        }

        if (is_numeric($chapterId) && $chapter === null) {
            throw new InvalidArgumentException('AI request Chapter context does not exist.');
        }

        if ($chapter !== null) {
            if ($novel !== null && $chapter->novel_id !== $novel->getKey()) {
                throw new InvalidArgumentException('AI request Chapter does not belong to the Novel context.');
            }

            $novel ??= $chapter->novel;
        }

        return [$novel, $chapter];
    }

    private function novelLimit(Novel $novel, string $key): ?float
    {
        // 显式开关是金额门禁的必要条件，旧数值不隐式启动限制。
        $policy = (array) data_get($novel->settings, 'budget', []);
        $global = $this->settings->budgetSettings();
        $limits = [];
        foreach ([$policy, $global] as $candidate) {
            if (($candidate[$key.'_enabled'] ?? false) && isset($candidate[$key])) {
                $limits[] = (float) $candidate[$key];
            }
        }

        return $limits === [] ? null : min($limits);
    }

    private function limit(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }
}
