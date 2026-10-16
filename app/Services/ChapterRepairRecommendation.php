<?php

namespace App\Services;

use App\Enums\GenerationStage;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Review;

/**
 * 将已持久化的失败与 Finding 映射为确定性的人工修复入口，不调用 Provider。
 */
final class ChapterRepairRecommendation
{
    /** @return array<int, array<string, mixed>> */
    public function forChapter(Chapter $chapter): array
    {
        $planningBoundary = (int) $chapter->generationRuns()
            ->where('stage', GenerationStage::ChapterPlanning)
            ->where('status', RunStatus::Succeeded)
            ->latest('id')
            ->value('id');
        $review = Review::query()
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('id', '>', $planningBoundary))
            ->with('artifact')
            ->latest('id')
            ->first();

        // PASS 已经表示当前审校结论允许提交；其中保留的非阻塞 Finding 只用于审计，不能继续显示成待修复事项。
        $reviewRequiresRepair = $review !== null && $review->decision !== ReviewDecision::Pass;
        $recommendations = $reviewRequiresRepair
            ? collect($review->findings ?? [])
                ->filter(fn (mixed $finding): bool => is_array($finding))
                ->map(fn (array $finding): array => $this->fromFinding($finding))
                ->values()
            : collect();

        $failedRun = $chapter->generationRuns()
            ->where('id', '>', $planningBoundary)
            ->whereNotNull('error_code')
            ->latest('id')
            ->get()
            ->first(fn (GenerationRun $run): bool => ! $this->deferredRunWasCompleted($chapter, $run));

        if ($failedRun !== null && ($review === null || $failedRun->getKey() > $review->generation_run_id)) {
            $recommendations->push($this->fromRun($failedRun));
        }

        if ($recommendations->isEmpty() && $reviewRequiresRepair) {
            $recommendations->push($this->workflowFallback($review));
        }

        $sceneSequences = $chapter->scenes()->pluck('sequence', 'id');

        return $recommendations
            ->map(function (array $row) use ($sceneSequences): array {
                $sequence = $row['scene_id'] === null ? null : $sceneSequences->get($row['scene_id']);
                $sceneReference = $sequence === null
                    ? match ($row['problem_layer']) {
                        'Chapter Plan' => '整章计划',
                        '章节正文' => '跨场景（需定位）',
                        default => '整章／未定位',
                    }
                : "Scene {$sequence}";

                return [
                    ...$row,
                    'scene_reference' => $sceneReference,
                    'target_fields' => $sequence === null ? $row['target_fields'] : array_map(
                        fn (string $field): string => str_replace("Scene {$row['scene_id']}", "Scene {$sequence}", $field),
                        $row['target_fields'],
                    ),
                ];
            })
            ->unique(fn (array $row): string => implode('|', [
                $row['problem_layer'],
                $row['recommended_action'],
                $row['scene_id'] ?? '',
                json_encode($row['confirmed_evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]))
            ->values()
            ->all();
    }

    /** Deferred 只是跨 Job 交接；同 Stage、同 Scene 后续成功后不再生成当前修复建议。 */
    private function deferredRunWasCompleted(Chapter $chapter, GenerationRun $run): bool
    {
        if (! in_array($run->error_code, ['scene_stage_deferred', 'generation_stage_deferred'], true)) {
            return false;
        }

        $continuation = $chapter->generationRuns()
            ->where('id', '>', $run->getKey())
            ->where('stage', $run->stage)
            ->where('status', RunStatus::Succeeded);

        $run->scene_id === null
            ? $continuation->whereNull('scene_id')
            : $continuation->where('scene_id', $run->scene_id);

        return $continuation->exists();
    }

    /** @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    public function fromFinding(array $finding): array
    {
        $code = strtoupper((string) ($finding['code'] ?? 'REVIEW_FINDING'));
        $scope = (string) ($finding['scope'] ?? 'chapter');
        $dimension = (string) ($finding['dimension'] ?? 'workflow');
        $sceneId = filter_var($finding['scene_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $evidence = $this->evidence($finding);

        if ($this->isCanonicalFinding($code, $finding)) {
            return $this->row(
                layer: 'Canonical Fact / State',
                evidence: $evidence,
                action: '修复正式事实或回滚最新正式章节',
                fields: array_values(array_filter([
                    data_get($finding, 'related_fact_id') ? 'Fact #'.data_get($finding, 'related_fact_id') : null,
                    data_get($finding, 'related_state_path') ?: 'Canonical State',
                ])),
                affected: ['Canonical State', 'Context', 'Scene', 'Assembly', 'Event', 'Patch', 'Review'],
                entry: '事实 / 状态受控修复；必要时使用“回滚最新正式章节”',
                recoveryKey: 'canonical_fact',
                code: $code,
            );
        }

        if ($this->isMilestoneFinding($code)) {
            return $this->row(
                layer: 'Outline / Milestone',
                evidence: $evidence,
                action: '修订未来 Outline 或从当前 Outline 重新规划本章',
                fields: ['Milestone 验收条件', 'Beat Handoff', 'must_include / must_not_include'],
                affected: ['Chapter Plan', 'Scene', 'Assembly', 'Event', 'Patch', 'Review'],
                entry: '规划预览 → 修订 Outline；或执行“从 Outline 重建章节”',
                recoveryKey: 'restart_from_outline',
                code: $code,
                sceneId: $sceneId,
            );
        }

        if ($this->isPlanFinding($code, $dimension)) {
            return $this->row(
                layer: 'Chapter Plan',
                evidence: $evidence,
                action: '创建新的 Chapter Plan Version',
                fields: $sceneId === null
                    ? ['chapter_function', 'reader_promise', 'required_facts', 'scene_plans']
                    : ["scene_plans[Scene {$sceneId}]", 'goal', 'conflict', 'turn', 'outcome'],
                affected: ['Scene', 'Assembly', 'Event', 'Patch', 'Review'],
                entry: '当前章节审校页 → 修订章节计划并重建场景；保存后同步 Scene 并从受影响 Scene 重建',
                recoveryKey: 'edit_plan',
                code: $code,
                sceneId: $sceneId,
            );
        }

        if ($scope === 'paragraph' && $sceneId !== null) {
            return $this->row(
                layer: '局部正文',
                evidence: $evidence,
                action: '人工修订当前 Scene 正文',
                fields: ["Scene {$sceneId} 正文", '逐字证据附近段落'],
                affected: ['当前 Scene Artifact', '后续 Scene', 'Assembly', 'Event', 'Patch', 'Review'],
                entry: '使用“人工修改局部正文”创建新的 Scene Rewrite Artifact',
                recoveryKey: 'manual_scene_edit',
                code: $code,
                sceneId: $sceneId,
            );
        }

        if ($sceneId !== null || $scope === 'scene') {
            return $this->row(
                layer: 'Scene',
                evidence: $evidence,
                action: '从最早受影响 Scene 级联重建',
                fields: [$sceneId === null ? '受影响 Scene' : "Scene {$sceneId}", 'goal', 'conflict', 'turn', 'outcome'],
                affected: ['当前及后续 Scene', 'Assembly', 'Event', 'Patch', 'Review'],
                entry: '场景页签 → 重新生成此场景及后续场景',
                recoveryKey: 'regenerate_scene',
                code: $code,
                sceneId: $sceneId,
            );
        }

        return $this->row(
            layer: '章节正文',
            evidence: $evidence,
            action: '定位重复或节奏问题后从最早受影响 Scene 级联重建',
            fields: ['受影响 Scene', '当前及后续 Scene'],
            affected: ['当前及后续 Scene', 'Assembly', 'Event', 'Patch', 'Review'],
            entry: '场景页签 → 从最早受影响 Scene 重新生成；若只需改措辞，先定位后人工修改局部正文',
            recoveryKey: 'regenerate_scene',
            code: $code,
        );
    }

    /** @return array<string, mixed> */
    private function fromRun(GenerationRun $run): array
    {
        $stage = $run->stage;
        $sceneId = $run->scene_id;

        if ($run->error_code === 'review_capacity_mismatch') {
            return $this->row(
                'Chapter Plan',
                $run->error_message ?: $run->error_code,
                '提高 Reviewer Context 或输出预算后创建新的 Plan Version',
                ['admission_snapshot.capacity.review'],
                ['Chapter Plan', 'Scene', 'Assembly', 'Event', 'Patch', 'Review'],
                '当前章节审校页 → 修订章节计划并重建场景；原样保存即可按当前配置重新 Admission',
                'edit_plan',
                $run->error_code,
            );
        }

        if (in_array($stage, [GenerationStage::SceneGeneration, GenerationStage::Rewrite], true)
            && $this->isFrozenBudgetExhausted((string) $run->error_code)) {
            $route = $stage === GenerationStage::SceneGeneration ? 'writer' : 'rewrite';

            return $this->row(
                'Chapter Plan',
                $run->error_message ?: $run->error_code,
                '按当前 Prompt 与请求预算创建新的 Plan Version',
                ["admission_snapshot.routes.{$route}.request_budgets"],
                ['Chapter Plan', 'Scene', 'Assembly', 'Event', 'Patch', 'Review'],
                '当前章节审校页 → 修订章节计划并重建场景；原样保存即可重新冻结 Route、Prompt 和预算并级联重建 Scene',
                'edit_plan',
                $run->error_code,
                $sceneId,
            );
        }

        if ($stage === GenerationStage::ChapterPlanning && $run->error_code === 'plan_validation_failed') {
            return $this->row(
                'Chapter Plan',
                $run->error_message ?: $run->error_code,
                '根据完整校验错误重新生成章节计划',
                ['Chapter Plan Candidate'],
                ['Chapter Plan', 'Scene', 'Assembly', 'Event', 'Patch', 'Review'],
                '当前章节页 → “重新生成章节计划”',
                'regenerate_plan',
                $run->error_code,
            );
        }

        return match ($stage) {
            GenerationStage::ChapterPlanning => $this->row('Chapter Plan', $run->error_message ?: $run->error_code, '修复计划输入后重新规划', ['Chapter Plan', 'Outline Target'], ['Chapter Plan', 'Scene', 'Assembly', 'Event', 'Patch', 'Review'], 'Generation → 恢复中心；或“从 Outline 重建章节”', 'restart_from_outline', $run->error_code),
            GenerationStage::SceneGeneration, GenerationStage::Rewrite => $this->row('Scene', $run->error_message ?: $run->error_code, '从失败 Scene 级联重建', [$sceneId === null ? '失败 Scene' : "Scene {$sceneId}"], ['Scene', 'Assembly', 'Event', 'Patch', 'Review'], 'Generation → 恢复中心；或场景页签重新生成', 'regenerate_scene', $run->error_code, $sceneId),
            default => $this->row('生成流程', $run->error_message ?: $run->error_code, '从失败阶段恢复', [$stage->getLabel()], array_map(fn (GenerationStage $stage): string => $stage->getLabel(), app(GenerationStageGraph::class)->downstream($stage)), 'Generation → 恢复中心', 'retry_run', $run->error_code),
        };
    }

    /** @return array<string, mixed> */
    private function workflowFallback(Review $review): array
    {
        return $this->row(
            '审校决策',
            'Review '.$review->decision->getLabel().'，但没有可定位 Finding。',
            '重新审校或检查 Review 产物完整性',
            ['Review Findings'],
            ['Review'],
            'Generation → 恢复中心',
            'retry_run',
            'REVIEW_FINDING_MISSING',
        );
    }

    /** @param array<string, mixed> $finding */
    private function evidence(array $finding): array
    {
        $result = [];
        $message = trim((string) ($finding['message'] ?? ''));
        if ($message !== '') {
            $result[] = '问题：'.$message;
        }

        $evidence = $finding['evidence'] ?? null;
        if (is_string($evidence) && trim($evidence) !== '') {
            $result[] = '证据：'.trim($evidence);
        }
        if (is_array($evidence) && $evidence !== []) {
            $encoded = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $result[] = '证据：'.($encoded ?: '已有结构化证据。');
        }

        return $result !== [] ? $result : ['问题：已持久化 Finding 没有提供问题说明或正文证据。'];
    }

    /** @param array<string, mixed> $finding */
    private function isCanonicalFinding(string $code, array $finding): bool
    {
        return str_contains($code, 'LOCKED_FACT')
            || str_contains($code, 'CANONICAL')
            || str_contains($code, 'STATE_VERSION')
            || str_contains($code, 'KNOWLEDGE_CONFLICT')
            || data_get($finding, 'related_fact_id') !== null
            || data_get($finding, 'related_state_path') !== null
            || ((string) ($finding['severity'] ?? '') === 'hard'
                && in_array((string) ($finding['dimension'] ?? 'state'), ['state', 'continuity'], true));
    }

    private function isMilestoneFinding(string $code): bool
    {
        return str_contains($code, 'MILESTONE')
            || str_contains($code, 'HANDOFF')
            || str_contains($code, 'ARC_BEAT')
            || str_contains($code, 'OUTLINE');
    }

    private function isPlanFinding(string $code, string $dimension): bool
    {
        return $dimension === 'plan'
            || str_contains($code, 'PLAN')
            || str_contains($code, 'CANDIDATE')
            || str_contains($code, 'UNAPPROVED_')
            || str_starts_with($code, 'CHAPTER_LENGTH_');
    }

    private function isFrozenBudgetExhausted(string $code): bool
    {
        return str_contains($code, '_reasoning_budget_exhausted')
            || str_contains($code, '_output_truncated')
            || str_contains($code, '_completion_budget_exhausted')
            || str_contains($code, '_output_budget_exhausted');
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $affected
     * @return array<string, mixed>
     */
    private function row(string $layer, string|array $evidence, string $action, array $fields, array $affected, string $entry, string $recoveryKey, ?string $code, ?int $sceneId = null): array
    {
        return [
            'problem_layer' => $layer,
            'confirmed_evidence' => is_array($evidence) ? $evidence : ['问题：'.trim($evidence)],
            'recommended_action' => $action,
            'target_fields' => array_map($this->describeTargetField(...), $fields),
            'affected_stages' => array_map($this->describeAffectedStage(...), $affected),
            'recovery_entry' => $entry,
            'recovery_key' => $recoveryKey,
            'error_code' => $code,
            'scene_id' => $sceneId,
        ];
    }

    private function describeTargetField(string $field): string
    {
        if (str_starts_with($field, 'Fact #')) {
            return $field.'（正式事实）';
        }
        if (str_starts_with($field, 'scene_plans[')) {
            return $field.'（目标场景计划）';
        }
        if (preg_match('/^Scene \d+ 正文$/', $field) === 1) {
            return $field.'（场景正文）';
        }

        return match ($field) {
            'Canonical State' => 'Canonical State（正式故事状态）',
            'Milestone 验收条件' => 'Milestone 验收条件（里程碑完成标准）',
            'Beat Handoff' => 'Beat Handoff（节拍交接条件）',
            'must_include / must_not_include' => 'must_include / must_not_include（必须包含 / 禁止包含）',
            'chapter_function' => 'chapter_function（章节功能）',
            'reader_promise' => 'reader_promise（读者承诺）',
            'required_facts' => 'required_facts（必需事实）',
            'forbidden_conflicts' => 'forbidden_conflicts（禁止冲突）',
            'scene_plans' => 'scene_plans（场景计划）',
            'goal' => 'goal（场景目标）',
            'conflict' => 'conflict（场景冲突）',
            'turn' => 'turn（场景转折）',
            'outcome' => 'outcome（场景结果）',
            'admission_snapshot.capacity.review' => 'admission_snapshot.capacity.review（审校容量冻结配置）',
            'admission_snapshot.routes.writer.request_budgets' => 'admission_snapshot.routes.writer.request_budgets（正文生成请求预算）',
            'admission_snapshot.routes.rewrite.request_budgets' => 'admission_snapshot.routes.rewrite.request_budgets（重写请求预算）',
            'Chapter Plan Candidate' => 'Chapter Plan Candidate（章节计划候选）',
            'Chapter Plan' => 'Chapter Plan（章节计划）',
            'Outline Target' => 'Outline Target（大纲目标）',
            'Review Findings' => 'Review Findings（审校问题清单）',
            '受影响 Scene' => '受影响 Scene（受影响场景）',
            '失败 Scene' => '失败 Scene（失败场景）',
            default => $this->describeStatePath($field),
        };
    }

    private function describeStatePath(string $field): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_.\[\]-]*$/', $field) === 1
            ? $field.'（正式状态字段路径）'
            : $field;
    }

    private function describeAffectedStage(string $stage): string
    {
        return match ($stage) {
            'Canonical State' => 'Canonical State（正式故事状态）',
            'Context' => 'Context（上下文构建）',
            'Chapter Plan' => 'Chapter Plan（章节计划）',
            'Scene' => 'Scene（场景正文生成）',
            'Assembly' => 'Assembly（章节组装）',
            'Event' => 'Event（故事事件提取）',
            'Patch' => 'Patch（状态补丁生成）',
            'Review' => 'Review（叙事审校）',
            '当前 Scene Artifact' => '当前 Scene Artifact（当前场景产物）',
            '后续 Scene' => '后续 Scene（后续场景）',
            '当前及后续 Scene' => '当前及后续 Scene（当前及后续场景）',
            default => $stage,
        };
    }
}
