<?php

namespace App\Services;

use App\Data\GenerationFailure;
use App\Enums\GenerationStage;

/** 新链错误目录：稳定码优先，旧规则仅作保守兼容，不靠中文报错猜业务。 */
final class GenerationIssueCatalog
{
    public const PROPOSAL_ACTIONS = ['retry_transient', 'repair_schema', 'repair_evidence', 'reextract_events', 'replan_current_chapter', 'rewrite_range', 'rebuild_context', 'reconcile_derived'];

    /** 普通界面显示动作含义，稳定动作码只出现在高级诊断中。 */
    public static function actionLabel(string $action): string
    {
        return match ($action) {
            'retry_transient' => '重试原断点', 'repair_schema' => '修复数据结构', 'repair_evidence' => '修复原文证据',
            'reextract_events' => '重新提取事件', 'replan_current_chapter' => '重建当前章节计划', 'rewrite_range' => '局部改写草稿',
            'rebuild_context' => '基于当前来源重建上下文', 'reconcile_derived' => '重建派生数据', default => '尚未支持的动作',
        };
    }

    /** 类别、中文原因及具体人工路径共同定义，UI 与调度使用同一结果。 */
    public function classify(GenerationFailure $failure, GenerationStage $stage): array
    {
        $code = strtolower($failure->code);
        $category = match (true) {
            in_array($code, ['cost_warning', 'budget_warning', 'cost_growth_warning']) => 'cost_warning',
            in_array($code, ['price_missing', 'cost_unknown']) => 'cost_unknown',
            str_starts_with($code, 'budget_'), in_array($code, ['cost_limit_exceeded', 'price_required_for_cap', 'unknown_cost_under_cap']) => 'cost_limit',
            in_array($code, ['provider_quota_exceeded', 'provider_insufficient_balance', 'insufficient_quota']) => 'provider_quota',
            in_array($code, ['provider_not_configured', 'provider_disabled', 'provider_authentication_failed', 'model_route_not_configured', 'outline_route_not_configured', 'connection_contract_changed', 'model_run_mismatch']) || in_array($failure->metadata['http_status'] ?? null, [401, 403]) => 'configuration',
            str_contains($code, 'capacity'), str_contains($code, 'budget_exhausted'), str_contains($code, 'output_truncated'), $code === 'model_capability_mismatch' => 'capacity',
            str_contains($code, 'evidence'), str_contains($code, 'audit_missing'), str_starts_with($code, 'review_contract_') => 'review_artifact',
            str_contains($code, 'locked_fact'), str_contains($code, 'hard_constraint'), str_contains($code, 'intent_conflict') => 'hard_constraint',
            str_contains($code, 'state_version'), str_starts_with($code, 'stale_'), str_contains($code, 'source_changed'), $code === 'request_contract_changed' => 'source_conflict',
            str_contains($code, 'plan_validation'), str_contains($code, 'admission') => 'plan',
            str_contains($code, 'schema'), str_contains($code, 'invalid_json'), str_contains($code, 'structured_output') => 'schema',
            str_contains($code, 'closure'), str_contains($code, 'ending'), str_contains($code, 'length_contract') => 'ending',
            str_contains($code, 'repair_exhausted'), str_contains($code, 'repair_deadline'), $code === 'repair_no_progress' => 'repair_limit',
            $stage === GenerationStage::Embedding => 'derived',
            $stage === GenerationStage::MemorySummary => 'summary',
            $stage === GenerationStage::Export => 'export',
            in_array($code, ['review_rewrite', 'quality_insufficient', 'rewrite_exhausted', 'content_quality_failed']) => 'content',
            in_array($code, ['worker_lost', 'worker_interrupted', 'scheduler_offline', 'dispatch_failed', 'database_unavailable']) || ($failure->metadata['category'] ?? '') === 'infrastructure_temporary' => 'infrastructure',
            in_array($code, ['provider_timeout', 'provider_connection_failed', 'provider_rate_limited', 'provider_server_error']) || ($failure->retryable && ($failure->metadata['category'] ?? '') === 'external_temporary') => 'transient',
            default => 'unknown',
        };
        [$message, $suggestion, $setting] = match ($category) {
            'transient' => ['模型服务暂时不可用，当前内容已保留。', '等待有限退避；服务恢复后重试同一断点。', null],
            'configuration' => ['模型连接、凭据或路由配置不可用。', '打开模型连接检查凭据引用、启用状态和权限，再检查模型路由。', 'connections'],
            'infrastructure' => ['执行服务或调度中断，结果可能尚未确认。', '检查 Horizon、Redis、数据库及 Scheduler；服务恢复后重新检查，勿重复提交正式章节。', 'health'],
            'schema' => ['模型返回的数据结构不符合合同。', '查看错误字段和预期结构；修改候选字段或选择支持结构化输出的模型。', 'routes'],
            'review_artifact' => ['评审或提取产物的审计条目、原文证据不完整。', '核对真实原文与证据位置；只修复评审依据或重新提取，不改正文凑引文。', null],
            'capacity' => ['当前模型容量或输出能力不足。', '查看输入、输出及推理预留差额，选择已核实的容量或缩小后续章节规模。', 'prices'],
            'plan' => ['章节计划未通过格式或业务准入检查。', '查看具体目标与字段差异，修改当前计划或拆分目标后重新准入。', null],
            'content' => ['当前草稿存在需要复验的内容质量问题。', '查看问题段落与理由，后续正文修复入口将创建新稿并重新提取、审校。', null],
            'hard_constraint' => ['草稿与锁定事实或明确创作要求冲突。', '优先修正草稿遵守原要求；改变锁定事实需专用显式授权，不能跳过审校。', null],
            'source_conflict' => ['正文、计划或正式状态版本已经变化。', '保留人工修改，对照当前版本后重建必要输入；不能只改版本号强行提交。', null],
            'ending' => ['篇幅安排或结局仍有未完成的义务。', '查看未闭合主线和伏笔，调整未来安排或明确延长篇幅。', null],
            'cost_warning' => ['费用达到预警值，生成仍可继续。', '查看费用明细和高耗费阶段；按需优化，不需要追加应用预算。', 'cost'],
            'cost_unknown' => ['部分费用尚未确认，默认继续生成。', '补充价格或核对供应商账单；未知金额不会记为免费。', 'prices'],
            'cost_limit' => ['用户主动启用的金额硬上限阻止了新请求。', '检查已花费用和在途占用；由用户在设置中明确提高、关闭上限或保持暂停。', 'cost'],
            'provider_quota' => ['供应商账户余额或配额不足。', '处理供应商账户后恢复，或选择已授权的可用路由；这不是应用费用预警。', 'connections'],
            'repair_limit' => ['自动修复次数、执行时限或无进展限制已触发。', '查看原问题与全部尝试，选择不同方案或人工修正；重试不会清空费用和次数。', null],
            'summary' => ['正式章节已保存，但必需摘要尚未生成。', '只补摘要并验证；正文无需重新提交，下一章等待必需上下文。', null],
            'derived' => ['向量或非关键派生数据暂未更新。', '保留正式正文，独立重建派生数据；不能因此回滚正式章节。', null],
            'export' => ['正文已保留，导出尚未完成。', '检查存储空间、目录权限和导出错误后仅重新导出。', 'health'],
            default => ['出现尚未识别的生成异常，受影响流程已保留断点。', '查看脱敏诊断与已有内容，保存处理说明并检查环境；不要执行模型提供的命令。', 'health'],
        };
        $blocking = ! in_array($category, ['cost_warning', 'cost_unknown', 'derived'], true);

        return ['category' => $category, 'severity' => $blocking ? 'error' : 'warning', 'blocking' => $blocking,
            'retryable' => $failure->retryable && in_array($category, ['transient', 'infrastructure'], true),
            'message' => $message, 'suggestion' => $suggestion, 'setting' => $setting,
            'fields' => (array) ($failure->metadata['fields'] ?? []),
            'rule_id' => (string) ($failure->metadata['rule_id'] ?? $failure->code)];
    }
}
