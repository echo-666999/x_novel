<?php

namespace App\Services;

use App\Actions\Novels\CompleteNovelAction;
use App\AI\Exceptions\AiProviderException;
use App\Data\GenerationFailure;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\NovelOutline;
use Illuminate\Support\Facades\DB;

/** 结局语义核验与确定性债务分开；字数和成本均不是完结证明。 */
final class BookEnding
{
    /** 用户明确授权有限补章；只调整未来安排，现有债务直到新审计通过才关闭。 */
    public function extend(int $taskId, int $version, int $chapters, string $reason): void
    {
        if ($chapters < 1 || $chapters > 3 || trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw new \LogicException('请选择补写 1 至 3 章并填写具体收束方向。');
        }
        $run = DB::transaction(function () use ($taskId, $version, $chapters, $reason): GenerationRun {
            $initial = NovelGenerationTask::findOrFail($taskId);
            Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($taskId)->lockForUpdate()->firstOrFail();
            if ($task->control_version !== $version || $task->status !== 'needs_attention' || $task->stop_reason !== 'book_closure_debt') {
                throw new \LogicException('完结处理预览已过期，请核对当前清单。');
            }
            $issue = GenerationIssue::where('generation_task_id', $taskId)->where('error_code', 'book_closure_debt')->where('status', '!=', 'resolved')->latest('id')->lockForUpdate()->firstOrFail();
            if (app(GenerationIssueService::class)->hasOtherBlockers($issue)) {
                throw new \LogicException('仍有其他阻塞问题，请先处理来源、事实或请求故障。');
            }
            $progress = $task->progress_snapshot;
            $progress['closing_extension_limit'] = (int) ($progress['closing_extensions'] ?? 0) + $chapters;
            $progress['closing_recovery_issue_id'] = $issue->id;
            $progress['closing_instructions'] = trim($reason);
            $progress['closing_authorizations'][] = ['actor_id' => auth()->id(), 'chapters' => $chapters, 'reason' => trim($reason), 'at' => now()->toISOString(), 'state_version_id' => $task->novel->canonical_state_version_id];
            // 现在安排第一章，后续仍逐章审校、提交和审计，不一次预排全部正文。
            $progress['closing_extensions'] = (int) ($progress['closing_extensions'] ?? 0) + 1;
            $task->update(['status' => 'running', 'stop_reason' => null, 'stop_detail' => null, 'control_version' => $version + 1, 'progress_snapshot' => $progress]);
            $issue->update(['status' => 'repairing']);

            return app(NovelPlanningService::class)->window($task->fresh(), NovelOutline::findOrFail($task->novel->current_blueprint_id));
        });
        app(GenerationJobDispatcher::class)->dispatchRun($run->id);
    }

    public function schema(): array
    {
        $text = ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000];
        $e = ['type' => 'object', 'properties' => ['chapter_id' => ['type' => 'integer'], 'paragraph_key' => $text, 'quote' => $text, 'offset' => ['type' => 'integer', 'minimum' => 0]], 'required' => ['chapter_id', 'paragraph_key', 'quote', 'offset'], 'additionalProperties' => false];

        return ['type' => 'object', 'properties' => ['checks' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['key' => $text, 'fulfilled' => ['type' => 'boolean'], 'reason' => $text, 'evidence' => ['type' => 'array', 'items' => $e]], 'required' => ['key', 'fulfilled', 'reason', 'evidence'], 'additionalProperties' => false]]], 'required' => ['checks'], 'additionalProperties' => false];
    }

    /** 蓝图中的承诺必须逐项检查，不依赖旧 Arc 表的存在。 */
    public function requirements(Novel $novel): array
    {
        $b = NovelOutline::findOrFail($novel->current_blueprint_id)->sourceArtifact->data;
        $items = [['key' => 'mainline', 'description' => $b['mainline']], ['key' => 'ending', 'description' => $b['ending']]];
        foreach (['reader_promises', 'character_arcs', 'foreshadowing_goals'] as $type) {
            foreach ($b[$type] as $i => $value) {
                $items[] = ['key' => $type.'-'.$i, 'description' => is_array($value) ? $value['description'] : $value];
            }
        }

        return $items;
    }

    /** 最后阶段只发送正式摘要及可定位证据；大输入仍通过统一容量门禁。 */
    public function enqueue(NovelGenerationTask $task): GenerationRun
    {
        $novel = $task->novel->fresh();
        $novel->update(['status' => 'completing']);
        $formal = $novel->chapters()->where('status', 'canonical');
        if (! (clone $formal)->exists() || (clone $formal)->whereNull('summary_artifact_id')->exists()) {
            throw new \LogicException('正式章节和必需摘要尚未就绪，不能审计完结。');
        }
        // 百万字正文不能把全部摘要一次塞入模型；正式状态负责精确债务，抽取近期、开篇和重要记忆来源供逐项证据核验。
        $ids = (clone $formal)->latest('sequence')->limit((int) config('generation.book.ending_recent_chapters', 20))->pluck('id')
            ->merge((clone $formal)->oldest('sequence')->limit(3)->pluck('id'));
        $important = app(BookMemoryService::class)->valid($novel)->orderByDesc('salience')->limit(20)->pluck('valid_from_chapter');
        $ids = $ids->merge((clone $formal)->whereIn('sequence', $important)->pluck('id'))->unique();
        $chapters = (clone $formal)->whereIn('id', $ids)->with(['summaryArtifact', 'canonicalArtifact:id,checksum'])->orderBy('sequence')->get();
        $sources = $chapters->map(fn ($c) => ['artifact_id' => $c->canonical_artifact_id, 'checksum' => $c->canonicalArtifact->checksum, 'chapter_id' => $c->id, 'pointer' => 'canonical_artifact_id'])->all();
        $blueprint = NovelOutline::findOrFail($novel->current_blueprint_id)->sourceArtifact;
        $sources[] = ['artifact_id' => $blueprint->id, 'checksum' => $blueprint->checksum];

        return app(NovelStageContract::class)->enqueue($task, 'ending', ['requirements' => $this->requirements($novel),
            'hard_context_hash' => app(ContextBuilder::class)->bookSourceHash($novel), 'ending_contract' => $novel->currentBible->ending_contract,
            'state' => $novel->canonicalStateVersion->state,
            'evidence_scope' => ['canonical_chapter_count' => (clone $formal)->count(), 'selected_chapter_ids' => $ids->values()->all(), 'rule' => '摘要是检索证据；无法从正式引用确认兑现时必须 fulfilled=false，不得把缺少记录解释为已完成。'],
            'chapters' => $chapters->map(fn ($c) => ['chapter_id' => $c->id, 'title' => $c->title, 'summary_points' => $c->summaryArtifact->data['points']])->all()], $sources,
            ['hard_context_hash' => app(ContextBuilder::class)->bookSourceHash($novel), 'blueprint_id' => $novel->current_blueprint_id]);
    }

    /** 全量债务覆盖、原文位置和正式归属校验后，代码给出完结结论。 */
    public function validate(GenerationRun $run, array $data): array
    {
        $novel = $run->novel->fresh();
        $expected = array_column($run->execution_contract['input']['requirements'], 'key');
        $actual = array_column($data['checks'], 'key');
        if (count($actual) !== count(array_unique($actual)) || array_diff($expected, $actual) || array_diff($actual, $expected)) {
            throw new AiProviderException('book_ending_coverage_invalid', '完结检查遗漏或重复债务，请修正审计，不能跳过承诺。', false);
        }
        foreach ($data['checks'] as $check) {
            if ($check['fulfilled'] && ! $check['evidence']) {
                throw new AiProviderException('book_evidence_invalid', '已兑现债务缺少正式原文证据。', false);
            }
            foreach ($check['evidence'] as $e) {
                $chapter = $novel->chapters()->where('status', 'canonical')->findOrFail($e['chapter_id']);
                app(ChapterDraftService::class)->evidence($chapter->canonicalArtifact, $e);
            }
        }
        $hard = array_map(fn ($item) => $item->toArray(), app(ClosureDebtService::class)->calculate($novel)->items);
        $blocked = collect($hard)->contains('critical', true) || collect($data['checks'])->contains('fulfilled', false);

        return [...$data, 'decision' => $blocked ? 'BLOCK' : 'PASS', 'deterministic_debts' => $hard,
            'state_version_id' => $novel->canonical_state_version_id, 'blueprint_id' => $novel->current_blueprint_id];
    }

    /** 最多补有限章数；仍有债务则给人工明确停点，不无限加章。 */
    public function adopt(NovelGenerationTask $task, GenerationRun $run): ?GenerationRun
    {
        $artifact = GenerationArtifact::findOrFail($run->result_artifact_id);
        $data = $artifact->data;
        $progress = $task->progress_snapshot;
        $progress['ending_audit_id'] = $artifact->id;
        if ($data['decision'] === 'PASS') {
            if ($id = $progress['closing_recovery_issue_id'] ?? null) {
                GenerationIssue::whereKey($id)->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by_run_id' => $run->id, 'resolution_reason' => '新的正式完结审计及确定性债务核验通过。']);
                unset($progress['closing_recovery_issue_id']);
            }
            $task->update(['progress_snapshot' => $progress]);
            app(CompleteNovelAction::class)->handle($task->novel->fresh());

            return app(BookExportService::class)->enqueue($task->fresh());
        }
        $attempts = (int) ($progress['closing_extensions'] ?? 0);
        $progress['closing_debts'] = array_values(array_filter($data['checks'], fn ($c) => ! $c['fulfilled']));
        if ($attempts >= (int) ($progress['closing_extension_limit'] ?? config('generation.book.max_closing_extensions', 3))) {
            if ($id = $progress['closing_recovery_issue_id'] ?? null) {
                GenerationIssue::whereKey($id)->update(['status' => 'awaiting_user']);
                unset($progress['closing_recovery_issue_id']);
                $task->update(['status' => 'needs_attention', 'stop_reason' => 'book_closure_debt', 'progress_snapshot' => $progress]);

                return null;
            }
            $task->update(['progress_snapshot' => $progress]);
            app(GenerationIssueService::class)->record($run, new GenerationFailure('book_closure_debt', '有限补章后仍有未兑现承诺。请查看完结清单，调整未来收束方向；不应解除硬事实或假称完结。', false, ['checks' => $data['checks'], 'debts' => $data['deterministic_debts']], '调整未来收束目标'));

            return null;
        }
        $progress['closing_extensions'] = $attempts + 1;
        $progress['closing_debts'] = array_values(array_filter($data['checks'], fn ($c) => ! $c['fulfilled']));
        $task->update(['progress_snapshot' => $progress]);

        return app(NovelPlanningService::class)->window($task->fresh(), NovelOutline::findOrFail($task->novel->current_blueprint_id));
    }
}
