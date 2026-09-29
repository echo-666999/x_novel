<?php

namespace App\Services;

use App\Enums\GenerationStage;
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

        $recommendations = collect($review?->findings ?? [])
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->map(fn (array $finding): array => $this->fromFinding($finding))
            ->values();

        $failedRun = $chapter->generationRuns()
            ->where('id', '>', $planningBoundary)
            ->whereNotNull('error_code')
            ->latest('id')
            ->first();

        if ($failedRun !== null && ($review === null || $failedRun->getKey() > $review->generation_run_id)) {
            $recommendations->push($this->fromRun($failedRun));
        }

        if ($recommendations->isEmpty() && $review !== null) {
            $recommendations->push($this->workflowFallback($review));
        }

        $sceneSequences = $chapter->scenes()->pluck('sequence', 'id');

        return $recommendations
            ->map(function (array $row) use ($sceneSequences): array {
                $sequence = $row['scene_id'] === null ? null : $sceneSequences->get($row['scene_id']);

                return [
                    ...$row,
                    'scene_reference' => $sequence === null ? '—' : "Scene {$sequence}",
                ];
            })
            ->unique(fn (array $row): string => implode('|', [
                $row['problem_layer'],
                $row['recommended_action'],
                $row['scene_id'] ?? '',
                $row['confirmed_evidence'],
            ]))
            ->values()
            ->all();
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

        if ($this->isPlanFinding($code, $scope, $dimension)) {
            return $this->row(
                layer: 'Chapter Plan',
                evidence: $evidence,
                action: '创建新的 Chapter Plan Version',
                fields: $sceneId === null
                    ? ['chapter_function', 'reader_promise', 'required_facts', 'scene_plans']
                    : ["scene_plans[Scene {$sceneId}]", 'goal', 'conflict', 'turn', 'outcome'],
                affected: ['Scene', 'Assembly', 'Event', 'Patch', 'Review'],
                entry: '章节列表 → 编辑计划；保存后同步 Scene 并从受影响 Scene 重建',
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
            layer: 'Chapter Plan',
            evidence: $evidence,
            action: '先调整计划并从最早受影响 Scene 重建',
            fields: ['chapter_function', 'scene_plans', 'required_facts', 'forbidden_conflicts'],
            affected: ['Scene', 'Assembly', 'Event', 'Patch', 'Review'],
            entry: '章节列表 → 编辑计划',
            recoveryKey: 'edit_plan',
            code: $code,
        );
    }

    /** @return array<string, mixed> */
    private function fromRun(GenerationRun $run): array
    {
        $stage = $run->stage;
        $sceneId = $run->scene_id;

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
    private function evidence(array $finding): string
    {
        $evidence = $finding['evidence'] ?? null;
        if (is_string($evidence) && trim($evidence) !== '') {
            return trim($evidence);
        }
        if (is_array($evidence) && $evidence !== []) {
            return json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '已有结构化证据。';
        }

        return (string) ($finding['message'] ?? '已持久化问题没有提供正文证据。');
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

    private function isPlanFinding(string $code, string $scope, string $dimension): bool
    {
        return $scope === 'chapter'
            || in_array($dimension, ['plan', 'progress'], true)
            || str_contains($code, 'PLAN')
            || str_contains($code, 'CANDIDATE')
            || str_contains($code, 'UNAPPROVED_')
            || str_starts_with($code, 'CHAPTER_LENGTH_');
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $affected
     * @return array<string, mixed>
     */
    private function row(string $layer, string $evidence, string $action, array $fields, array $affected, string $entry, string $recoveryKey, ?string $code, ?int $sceneId = null): array
    {
        return [
            'problem_layer' => $layer,
            'confirmed_evidence' => $evidence,
            'recommended_action' => $action,
            'target_fields' => $fields,
            'affected_stages' => $affected,
            'recovery_entry' => $entry,
            'recovery_key' => $recoveryKey,
            'error_code' => $code,
            'scene_id' => $sceneId,
        ];
    }
}
