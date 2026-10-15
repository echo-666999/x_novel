<?php

namespace App\Services;

use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\NarrativeProsePolicy;

/**
 * 统一构造 Reviewer 的模型输入并估算其容量。
 *
 * Plan Admission 使用投影上下文，ChapterReviewer 使用实际上下文；两者最终都必须经过
 * 同一套版本化裁剪、Prompt 拼装、响应 Schema 和 TokenBudget 估算规则。
 */
final class ReviewPromptCapacityEstimator
{
    public const VERSION = 'review-prompt-capacity-v1';

    private const USER_INSTRUCTION = '请根据章节计划和确定性状态检查结果审校以下章节草稿，并确保所有面向用户的说明均使用简体中文：';

    /** v16 必须供已冻结的历史 Chapter Plan 继续使用，不能用新版文本冒充。 */
    private const SYSTEM_PROMPT_V16 = '你是 XNovel 语义叙事审校器。Laravel 已完成长度、来源链、版本、Coverage、State 与 Locked Fact 等确定性校验；不得重复报告这些结论。只判断七维叙事质量、冻结 Outline/Plan 条件、候选人物与世界实体、伏笔动作的语义是否由正文支持。所有审计数组必须按输入冻结契约的原顺序逐项返回，但不得复述数据库 ID、键、条件文本、Scene ID 或汇总状态；Laravel 会按位置恢复身份并计算汇总。fulfilled、introduced 或 contradicted 必须引用当前正文中的连续逐字证据；missing、not_met 与 not_applicable 的 evidence 必须为 null。Findings 只报告有明确正文证据的语义问题，相同根因与修复动作必须合并；不得返回推荐 Decision。scope=paragraph 仅用于可由一处唯一原文替换的问题，scope=scene 仅用于单场景完整替换，章节功能、Milestone、Handoff、剧情结果、跨 Scene 结构与整体节奏问题必须使用 scope=chapter，由 Laravel 转入 Plan/Scene 重建。';

    private const SYSTEM_PROMPT_V17 = '你是 XNovel 语义叙事审校器。Laravel 已完成长度、来源链、版本、Coverage、State 与 Locked Fact 等确定性校验；不得重复报告这些结论。只判断七维叙事质量、冻结 Outline/Plan 条件、候选人物与世界实体、伏笔动作的语义是否由正文支持。所有审计数组必须按输入冻结契约的原顺序逐项返回，但不得复述数据库 ID、键、条件文本、Scene ID 或汇总状态；Laravel 会按位置恢复身份并计算汇总。fulfilled、introduced 或 contradicted 必须引用当前正文中的连续逐字证据；missing、not_met 与 not_applicable 的 evidence 必须为 null。Findings 只报告有明确正文证据的语义问题，相同根因与修复动作必须合并；不得返回推荐 Decision。scope=paragraph 仅用于可由一处唯一原文替换的问题，scope=scene 仅用于单场景完整替换。若只是一处原文或单一 Scene 的表达与 Milestone 验收条件冲突，且不需要改变冻结目标或关键剧情结果，应使用 paragraph 或 scene。章节功能、Milestone/Handoff 目标本身、关键剧情结果、跨 Scene 结构与整体节奏问题必须使用 scope=chapter，由 Laravel 转入 Plan/Scene 重建。';

    /** v18 明确紧凑审计数组与冻结输入的逐项映射，数量仍由动态 Schema 强制执行。 */
    private const SYSTEM_PROMPT_V18 = '你是 XNovel 语义叙事审校器。Laravel 已完成长度、来源链、版本、Coverage、State 与 Locked Fact 等确定性校验；不得重复报告这些结论。只判断七维叙事质量、冻结 Outline/Plan 条件、候选人物与世界实体、伏笔动作的语义是否由正文支持。所有审计数组必须严格按 audit_contract 中声明的 source、count 和输入原顺序逐项返回：milestone_completion 对应 outline_completion_contract.milestone_criteria，beat_exit 对应 beat_exit_criteria，handoff_readiness 对应 handoff_checks，foreshadowing_audits 对应 foreshadowing_contract.actions，arc_beat_audits 对应 chapter_plan.arc_contributions，arc_completion_audits 对应 arc_contributions 中首次出现的去重 Arc，人物和世界实体候选审计分别对应 chapter_plan 的同名 candidates。不得复述数据库 ID、键、条件文本、Scene ID 或汇总状态；Laravel 会按位置恢复身份并计算汇总。fulfilled、introduced 或 contradicted 必须引用当前正文中的连续逐字证据；missing、not_met 与 not_applicable 的 evidence 必须为 null。Findings 只报告有明确正文证据的语义问题，相同根因与修复动作必须合并；不得返回推荐 Decision。scope=paragraph 仅用于可由一处唯一原文替换的问题，scope=scene 仅用于单场景完整替换。若只是一处原文或单一 Scene 的表达与 Milestone 验收条件冲突，且不需要改变冻结目标或关键剧情结果，应使用 paragraph 或 scene。章节功能、Milestone/Handoff 目标本身、关键剧情结果、跨 Scene 结构与整体节奏问题必须使用 scope=chapter，由 Laravel 转入 Plan/Scene 重建。';

    /** v19 沿用 v18 的语义任务，但 Provider 输入不再携带完整 Event Candidate。 */
    private const SYSTEM_PROMPT_V19 = self::SYSTEM_PROMPT_V18;

    /** v20 只向模型提供一份 Outline 完成语义要求；精确数量继续由动态 Schema 约束。 */
    private const SYSTEM_PROMPT_V20 = '你是 XNovel 语义叙事审校器。Laravel 已完成长度、来源链、版本、Coverage、State 与 Locked Fact 等确定性校验；不得重复报告这些结论。只判断七维叙事质量、冻结 Outline/Plan 条件、候选人物与世界实体、伏笔动作的语义是否由正文支持。所有审计数组必须严格按输入原顺序逐项返回，数量由响应 Schema 强制执行：milestone_completion 对应 outline_completion_requirements.milestone_criteria，beat_exit 对应 beat_exit_criteria，handoff_readiness 对应 handoff_checks，foreshadowing_audits 对应 foreshadowing_contract.actions，arc_beat_audits 对应 chapter_plan.arc_contributions，arc_completion_audits 对应 arc_contributions 中首次出现的去重 Arc，人物和世界实体候选审计分别对应 chapter_plan 的同名 candidates。不得复述数据库 ID、键、条件文本、Scene ID 或汇总状态；Laravel 会按位置恢复身份并计算汇总。fulfilled、introduced 或 contradicted 必须引用当前正文中的连续逐字证据；missing、not_met 与 not_applicable 的 evidence 必须为 null。Findings 只报告有明确正文证据的语义问题，相同根因与修复动作必须合并；不得返回推荐 Decision。scope=paragraph 仅用于可由一处唯一原文替换的问题，scope=scene 仅用于单场景完整替换。若只是一处原文或单一 Scene 的表达与 Milestone 验收条件冲突，且不需要改变冻结目标或关键剧情结果，应使用 paragraph 或 scene。章节功能、Milestone/Handoff 目标本身、关键剧情结果、跨 Scene 结构与整体节奏问题必须使用 scope=chapter，由 Laravel 转入 Plan/Scene 重建。';

    public function __construct(private readonly TokenBudget $tokenBudget) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, int>  $contractCounts
     */
    public function request(
        array $context,
        array $contractCounts,
        string $provider,
        string $model,
        ?string $reasoningEffort,
        string $promptVersion,
        int $maxCompletionTokens,
        array $metadata = [],
    ): AiRequest {
        $providerContext = $this->providerContext($context, $promptVersion);

        return new AiRequest(
            model: $model,
            provider: $provider,
            reasoningEffort: $reasoningEffort,
            systemPrompt: $this->systemPrompt($promptVersion),
            prompt: self::USER_INSTRUCTION.json_encode(
                $providerContext,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
            temperature: .2,
            maxTokens: $maxCompletionTokens,
            responseSchema: CompactReviewPayload::schema($contractCounts),
            promptVersion: $promptVersion,
            metadata: $metadata,
        );
    }

    /**
     * @return array{
     *     estimator_version: string,
     *     estimated_input_tokens: int,
     *     input_checksum: string,
     *     input_breakdown: array{
     *         system_prompt_tokens: int,
     *         user_instruction_tokens: int,
     *         user_payload_tokens: int,
     *         serialized_messages_tokens: int,
     *         response_schema_tokens: int
     *     }
     * }
     */
    public function estimate(AiRequest $request): array
    {
        $messages = $request->resolvedMessages();
        $input = [
            'messages' => $messages,
            'response_schema' => $request->responseSchema,
        ];
        $prompt = (string) ($request->prompt ?? '');
        $hasReviewerInstruction = str_starts_with($prompt, self::USER_INSTRUCTION);
        $userPayload = $hasReviewerInstruction
            ? substr($prompt, strlen(self::USER_INSTRUCTION))
            : $prompt;

        return [
            'estimator_version' => self::VERSION,
            'estimated_input_tokens' => $this->tokenBudget->estimate($input),
            'input_checksum' => hash('sha256', json_encode(
                $input,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )),
            // 分项只用于解释输入构成；完整请求估算还包含消息与 Schema 的 JSON 包装开销，
            // 因此不能把这些分项简单相加代替 estimated_input_tokens。
            'input_breakdown' => [
                'system_prompt_tokens' => $this->estimateOptional($request->systemPrompt),
                'user_instruction_tokens' => $hasReviewerInstruction
                    ? $this->tokenBudget->estimate(self::USER_INSTRUCTION)
                    : 0,
                'user_payload_tokens' => $this->estimateOptional($userPayload),
                'serialized_messages_tokens' => $this->tokenBudget->estimate($messages),
                'response_schema_tokens' => $request->responseSchema === null
                    ? 0
                    : $this->tokenBudget->estimate($request->responseSchema),
            ],
        ];
    }

    private function estimateOptional(?string $content): int
    {
        return filled($content) ? $this->tokenBudget->estimate($content) : 0;
    }

    /**
     * Event Candidate、完整 Outline Contract 与 Audit Contract 属于 Laravel 的来源链和
     * 确定性验证输入。Reviewer 只接收一份 Outline 语义要求，避免重复条件与证据。
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function providerContext(array $context, string $promptVersion): array
    {
        $baseVersion = $this->basePromptVersion($promptVersion);
        if (! in_array($baseVersion, ['reviewer-v19', 'reviewer-v20'], true)) {
            return $context;
        }

        $providerContext = collect($context)->except('event_candidate')->all();
        if ($baseVersion === 'reviewer-v19') {
            return $providerContext;
        }

        $providerContext['outline_completion_requirements'] = collect(
            (array) ($context['outline_completion_contract'] ?? []),
        )->only(['milestone_criteria', 'beat_exit_criteria', 'handoff_checks'])->all();
        unset($providerContext['outline_completion_contract'], $providerContext['audit_contract']);

        return $providerContext;
    }

    /** 按 Chapter Plan 冻结的基础版本选择真实 Prompt，禁止用新文本冒充历史版本。 */
    public function systemPrompt(string $promptVersion): string
    {
        $prompt = match ($this->basePromptVersion($promptVersion)) {
            'reviewer-v16' => self::SYSTEM_PROMPT_V16,
            'reviewer-v17' => self::SYSTEM_PROMPT_V17,
            'reviewer-v18' => self::SYSTEM_PROMPT_V18,
            'reviewer-v19' => self::SYSTEM_PROMPT_V19,
            'reviewer-v20' => self::SYSTEM_PROMPT_V20,
            default => throw new AiProviderException(
                'review_prompt_version_unsupported',
                "Narrative Review 无法恢复冻结 Prompt 版本 [{$promptVersion}]。",
                false,
            ),
        };

        return $prompt.NarrativeProsePolicy::reviewing();
    }

    public function basePromptVersion(string $promptVersion): string
    {
        return explode('+', $promptVersion, 2)[0];
    }
}
