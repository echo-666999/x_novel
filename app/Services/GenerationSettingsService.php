<?php

namespace App\Services;

use App\Enums\AiStage;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * 维护会影响新 Generation Run 的全局预算与向量维度。
 *
 * 已冻结到 Run / Plan / Batch 的值不会被这里的后续修改覆盖。
 */
final class GenerationSettingsService
{
    /** system_settings 中 generation 配置 JSON 的结构版本，用于拒绝读取未知格式。 */
    public const SCHEMA_VERSION = 1;

    /** @var array<string, array<int, string>> */
    private const CHAPTER_TIERS = [
        'planner' => ['initial', 'retry', 'final'],
        'writer' => ['initial', 'retry', 'final'],
        'extractor' => ['initial', 'retry', 'final'],
        'reviewer' => ['initial'],
        'rewrite' => ['initial', 'retry', 'final'],
        'summary' => ['initial', 'retry', 'final'],
    ];

    /** @var array<string, array<string, array<int, string>>> */
    private const REPAIR_TIERS = [
        'extractor' => [
            'scene_structure' => ['initial', 'retry'],
            'coverage_evidence' => ['initial', 'retry'],
            'foreshadowing_coverage' => ['initial', 'retry'],
            'event_evidence' => ['initial', 'retry'],
        ],
        'reviewer' => [
            'coverage_judgment' => ['initial', 'retry'],
            'review_schema' => ['initial'],
            'arc_completion' => ['initial'],
        ],
        'rewrite' => [
            'coverage_evidence' => ['initial', 'retry'],
            'length_repair' => ['initial', 'retry'],
        ],
    ];

    /** @return array{settings: array<string, mixed>, source: string} */
    public function current(): array
    {
        if (! Schema::hasTable('system_settings')) {
            throw new \RuntimeException('数据库尚未创建 system_settings 表，无法读取生成参数。');
        }

        $value = SystemSetting::query()->find(SystemSetting::GENERATION)?->value;
        if (! is_array($value)) {
            throw new \RuntimeException('数据库缺少 generation 全局生成参数；请执行数据库迁移后再启动生成任务。');
        }

        try {
            return [
                // 数据库记录一旦存在就必须完整有效，不能逐字段静默借用应用默认值。
                'settings' => $this->normalize($value, true),
                'source' => 'database',
            ];
        } catch (ValidationException $exception) {
            Log::error('Stored generation settings are invalid.', [
                'setting_key' => SystemSetting::GENERATION,
                'validation_fields' => array_keys($exception->errors()),
            ]);

            throw new \RuntimeException('数据库中的 generation 全局生成参数无效，请在修复配置后再启动生成任务。', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->current()['settings'];
    }

    /** @return array{settings: array<string, mixed>, changed: bool} */
    public function save(array $settings, ?int $actorId): array
    {
        return DB::transaction(function () use ($settings, $actorId): array {
            $normalized = $this->normalize($settings, true);
            $record = SystemSetting::query()->lockForUpdate()->find(SystemSetting::GENERATION);
            if ($record === null) {
                throw new \RuntimeException('数据库缺少 generation 全局生成参数；请执行数据库迁移后再保存。');
            }
            $before = is_array($record?->value) ? $record->value : null;

            if ($before === $normalized) {
                return ['settings' => $normalized, 'changed' => false];
            }

            // 金额授权单独留痕；日志不是唯一审计来源。
            $history = $record->policy_history ?? [];
            if (app(GenerationInputIdentity::class)->hash(app(GenerationCostPolicy::class)->normalize((array) data_get($before, 'budget', []))) !== app(GenerationInputIdentity::class)->hash($normalized['budget']) || data_get($before, 'currency') !== $normalized['currency']) {
                $history[] = ['actor_id' => $actorId, 'at' => now()->toIso8601String(),
                    'before' => ['budget' => data_get($before, 'budget'), 'currency' => data_get($before, 'currency')],
                    'after' => ['budget' => $normalized['budget'], 'currency' => $normalized['currency']]];
            }
            $record->update(['value' => $normalized, 'policy_history' => $history]);

            Log::info('Generation settings changed.', [
                'actor_id' => $actorId,
                'before' => $before,
                'after' => $normalized,
            ]);

            return ['settings' => $normalized, 'changed' => true];
        });
    }

    /** @return array<string, mixed> */
    public function chapterRequestBudgets(AiStage $stage): array
    {
        return (array) data_get($this->settings(), "chapter_request_budgets.{$stage->value}", []);
    }

    /** @return array<string, mixed> */
    public function chapterRepairRequestBudgets(AiStage $stage): array
    {
        return (array) data_get($this->settings(), "chapter_repair_request_budgets.{$stage->value}", []);
    }

    /** @return array<string, mixed> */
    public function outlineRequestBudget(AiStage $stage): array
    {
        return (array) data_get($this->settings(), "outline_request_budgets.{$stage->value}", []);
    }

    public function reviewContextTokenBudget(): int
    {
        return (int) data_get($this->settings(), 'review_context_token_budget');
    }

    public function embeddingDimensions(): int
    {
        return (int) data_get($this->settings(), 'embedding_dimensions');
    }

    public function currency(): string
    {
        return (string) data_get($this->settings(), 'currency', 'USD');
    }

    /** @return array{daily_hard_limit: float|null, novel_total_limit: float|null, chapter_max_cost: float|null} */
    public function budgetSettings(): array
    {
        return (array) data_get($this->settings(), 'budget', []);
    }

    public function databaseEmbeddingDimensions(): int
    {
        $fallback = 1_536;
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('memories')) {
            return $fallback;
        }

        $table = DB::getTablePrefix().'memories';
        $row = DB::selectOne(<<<'SQL'
            select a.atttypmod as dimensions
            from pg_attribute a
            join pg_class c on c.oid = a.attrelid
            join pg_namespace n on n.oid = c.relnamespace
            where c.relname = ?
              and a.attname = 'embedding'
              and not a.attisdropped
            order by (n.nspname = current_schema()) desc
            limit 1
            SQL, [$table]);

        $dimensions = (int) ($row?->dimensions ?? 0);

        return $dimensions > 0 ? $dimensions : $fallback;
    }

    /** @return array<string, mixed> */
    private function normalize(array $settings, bool $verifyDatabaseDimension = false): array
    {
        $errors = [];
        $allowed = [
            'schema_version',
            'currency',
            'budget',
            'review_context_token_budget',
            'embedding_dimensions',
            'outline_request_budgets',
            'chapter_request_budgets',
            'chapter_repair_request_budgets',
        ];
        foreach (array_diff(array_keys($settings), $allowed) as $key) {
            $errors[(string) $key][] = '生成配置包含未知字段。';
        }
        if (($settings['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors['schema_version'][] = '生成配置 Schema Version 不受支持。';
        }

        $currency = strtoupper(trim((string) ($settings['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors['currency'][] = '计价币种必须是三个英文字母。';
        }

        $budgetInput = $this->object($settings, 'budget', $errors);
        // 金额与主动启用独立验证，默认没有费用停点。
        $budget = app(GenerationCostPolicy::class)->normalize($budgetInput);

        $reviewContext = filter_var($settings['review_context_token_budget'] ?? null, FILTER_VALIDATE_INT);
        if ($reviewContext === false || $reviewContext < 1) {
            $errors['review_context_token_budget'][] = '审校上下文预算必须是正整数。';
        }

        $embeddingDimensions = filter_var($settings['embedding_dimensions'] ?? null, FILTER_VALIDATE_INT);
        if ($embeddingDimensions === false || $embeddingDimensions < 1) {
            $errors['embedding_dimensions'][] = '向量维度必须是正整数。';
        } elseif ($verifyDatabaseDimension
            && DB::getDriverName() === 'pgsql'
            && Schema::hasTable('memories')
            && $embeddingDimensions !== $this->databaseEmbeddingDimensions()) {
            $errors['embedding_dimensions'][] = '向量维度必须与 PostgreSQL memories.embedding 字段的实际维度一致。';
        }

        $outlineInput = $this->object($settings, 'outline_request_budgets', $errors);
        $outline = [];
        foreach ([AiStage::OutlineFoundation, AiStage::OutlineStructure, AiStage::OutlineArcBeats, AiStage::OutlineBeatDetail] as $stage) {
            $outline[$stage->value] = $this->budget(
                $outlineInput[$stage->value] ?? null,
                "outline_request_budgets.{$stage->value}",
                $errors,
            );
        }
        $this->rejectUnknownKeys($outlineInput, array_keys($outline), 'outline_request_budgets', $errors);

        $chapterInput = $this->object($settings, 'chapter_request_budgets', $errors);
        $chapter = [];
        foreach (self::CHAPTER_TIERS as $stage => $tiers) {
            $chapter[$stage] = $this->tieredBudgets(
                $chapterInput[$stage] ?? null,
                $tiers,
                "chapter_request_budgets.{$stage}",
                $errors,
            );
        }
        $this->rejectUnknownKeys($chapterInput, array_keys(self::CHAPTER_TIERS), 'chapter_request_budgets', $errors);

        $repairInput = $this->object($settings, 'chapter_repair_request_budgets', $errors);
        $repairs = [];
        foreach (self::REPAIR_TIERS as $stage => $substages) {
            $stageInput = is_array($repairInput[$stage] ?? null) ? $repairInput[$stage] : [];
            if (! is_array($repairInput[$stage] ?? null)) {
                $errors["chapter_repair_request_budgets.{$stage}"][] = '缺少修复阶段预算。';
            }
            foreach ($substages as $substage => $tiers) {
                $repairs[$stage][$substage] = $this->tieredBudgets(
                    $stageInput[$substage] ?? null,
                    $tiers,
                    "chapter_repair_request_budgets.{$stage}.{$substage}",
                    $errors,
                );
            }
            $this->rejectUnknownKeys($stageInput, array_keys($substages), "chapter_repair_request_budgets.{$stage}", $errors);
        }
        $this->rejectUnknownKeys($repairInput, array_keys(self::REPAIR_TIERS), 'chapter_repair_request_budgets', $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'currency' => $currency,
            'budget' => $budget,
            'review_context_token_budget' => $reviewContext,
            'embedding_dimensions' => $embeddingDimensions,
            'outline_request_budgets' => $outline,
            'chapter_request_budgets' => $chapter,
            'chapter_repair_request_budgets' => $repairs,
        ];
    }

    /** @param array<string, array<int, string>> $errors @return array<string, mixed> */
    private function object(array $settings, string $key, array &$errors): array
    {
        if (! is_array($settings[$key] ?? null)) {
            $errors[$key][] = '配置必须是对象。';

            return [];
        }

        return $settings[$key];
    }

    /** @param array<int, string> $tiers @param array<string, array<int, string>> $errors @return array<string, array<string, int>> */
    private function tieredBudgets(mixed $input, array $tiers, string $path, array &$errors): array
    {
        if (! is_array($input)) {
            $errors[$path][] = '缺少请求预算。';
            $input = [];
        }

        $result = [];
        $previousOutput = 0;
        $previousReserve = 0;
        foreach ($tiers as $tier) {
            $budget = $this->budget($input[$tier] ?? null, "{$path}.{$tier}", $errors);
            if ($budget['output_tokens'] < $previousOutput || $budget['reasoning_reserve_tokens'] < $previousReserve) {
                $errors["{$path}.{$tier}"][] = '可见输出额度和推理预留都不能低于前一档预算。';
            }
            $result[$tier] = $budget;
            $previousOutput = $budget['output_tokens'];
            $previousReserve = $budget['reasoning_reserve_tokens'];
        }
        $this->rejectUnknownKeys($input, $tiers, $path, $errors);

        return $result;
    }

    /** @param array<string, array<int, string>> $errors @return array{output_tokens: int, reasoning_reserve_tokens: int} */
    private function budget(mixed $input, string $path, array &$errors): array
    {
        if (! is_array($input)) {
            $errors[$path][] = '请求预算必须是对象。';
            $input = [];
        }
        $this->rejectUnknownKeys($input, ['output_tokens', 'reasoning_reserve_tokens'], $path, $errors);

        $output = filter_var($input['output_tokens'] ?? null, FILTER_VALIDATE_INT);
        $reserve = filter_var($input['reasoning_reserve_tokens'] ?? null, FILTER_VALIDATE_INT);
        if ($output === false || $output < 1) {
            $errors["{$path}.output_tokens"][] = '可见输出额度必须是正整数。';
        }
        if ($reserve === false || $reserve < 0) {
            $errors["{$path}.reasoning_reserve_tokens"][] = '推理预留必须是非负整数。';
        }

        return [
            'output_tokens' => $output === false ? 0 : $output,
            'reasoning_reserve_tokens' => $reserve === false ? -1 : $reserve,
        ];
    }

    /** @param array<string, mixed> $input @param array<int, string> $allowed @param array<string, array<int, string>> $errors */
    private function rejectUnknownKeys(array $input, array $allowed, string $path, array &$errors): void
    {
        foreach (array_diff(array_keys($input), $allowed) as $key) {
            $errors["{$path}.{$key}"][] = '配置包含未知字段。';
        }
    }
}
