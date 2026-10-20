<?php

namespace App\Actions\Chapters;

use App\AI\JsonSchemaValidator;
use App\Enums\ArtifactType;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Services\BookLifecycle;
use App\Services\ChapterDraftService;
use App\Services\GenerationInputIdentity;
use App\Services\GenerationRunCoordinator;
use App\Services\NovelBookWorkflow;
use App\Services\NovelStageContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 人工仅修订摘要证据，摘要条目与正式正文锁定，保留失败及费用。 */
final class ReviseBookSummaryEvidenceAction
{
    public function source(Chapter $chapter): GenerationRun
    {
        $task = NovelGenerationTask::findOrFail($chapter->novel->current_generation_task_id);
        $source = GenerationRun::where('generation_task_id', $task->id)->where('chapter_id', $chapter->id)
            ->where('execution_contract->book_stage', 'summary')->latest('id')->firstOrFail();
        $issue = GenerationIssue::findOrFail($source->issue_id);
        if ($source->status !== RunStatus::Failed || $source->error_code !== 'book_evidence_invalid'
            || $issue->latest_run_id !== $source->id || $issue->status === 'resolved'
            || ! $chapter->canonical_artifact_id || $chapter->summary_artifact_id
            || ! in_array($task->status, ['needs_attention', 'running'], true)
            || GenerationRun::where('generation_task_id', $task->id)->where('pipeline_version', 'pipeline-v1')->whereIn('status', ['queued', 'running'])->exists()) {
            throw new \LogicException('仅当前已停止且尚未采用的摘要证据失败可人工修订。');
        }
        app(GenerationRunCoordinator::class)->assertSources($task, $source->source_manifest, $source->execution_contract);

        return $source;
    }

    public function candidate(GenerationRun $source): GenerationArtifact
    {
        return $source->artifacts()->where('type', ArtifactType::Summary)->where('validation_status', 'rejected')->latest('id')->firstOrFail();
    }

    public function rows(GenerationArtifact $candidate): array
    {
        return array_map(fn (array $point): array => ['evidence' => $point['evidence'], 'reason' => '', 'supported' => false], $candidate->data['points']);
    }

    public function corrected(GenerationRun $source, GenerationArtifact $candidate, array $rows): array
    {
        $data = $candidate->data;
        if (! app(JsonSchemaValidator::class)->matches($data, app(NovelStageContract::class)->schema('summary'))
            || ! array_is_list($rows) || count($rows) !== count($data['points'])) {
            throw new \LogicException('摘要结构或条目数量不匹配。');
        }
        $draft = GenerationArtifact::findOrFail($source->execution_contract['canonical_artifact_id']);
        $changes = [];
        foreach ($data['points'] as $index => $point) {
            $row = $rows[$index];
            if (app(GenerationInputIdentity::class)->hash($point['evidence']) !== app(GenerationInputIdentity::class)->hash($row['evidence'])) {
                if (blank($row['reason']) || ! $row['supported']) {
                    throw new \LogicException('每项修改须填写理由并确认真实引文支持原摘要；缺乏依据不能采用。');
                }
                app(ChapterDraftService::class)->evidence($draft, $row['evidence']);
                $data['points'][$index]['evidence'] = $row['evidence'];
                $changes[] = ['point_index' => $index, 'before' => $point['evidence'], 'after' => $row['evidence'], 'reason' => $row['reason'], 'supported' => true];
            }
        }
        if ($changes === []) {
            throw new \LogicException('没有证据修改，不重复处理。');
        }
        if (! app(JsonSchemaValidator::class)->matches($data, app(NovelStageContract::class)->schema('summary'))) {
            throw new \LogicException('证据结构不合法。');
        }
        app(BookLifecycle::class)->validateSummary($source, $data);

        return ['data' => $data, 'changes' => $changes];
    }

    public function handle(int $chapterId, int $sourceId, int $candidateId, string $checksum, int $controlVersion, array $rows, string $operationKey, bool $dispatch = true): GenerationArtifact
    {
        Validator::make(['key' => $operationKey, 'rows' => $rows], ['key' => 'required|uuid', 'rows' => 'required|array|max:100', 'rows.*.evidence' => 'required|array', 'rows.*.reason' => 'present|string|max:4000', 'rows.*.supported' => 'required|boolean'])->validate();
        $initial = Chapter::findOrFail($chapterId);
        $artifact = DB::transaction(function () use ($initial, $sourceId, $candidateId, $checksum, $controlVersion, $rows, $operationKey): GenerationArtifact {
            $novel = Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($novel->current_generation_task_id)->lockForUpdate()->firstOrFail();
            $chapter = Chapter::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $hash = app(GenerationInputIdentity::class)->hash([$chapter->id, $sourceId, $candidateId, $checksum, $controlVersion, $rows]);
            $existing = GenerationRun::where('idempotency_key', 'book-summary-evidence-edit:'.$operationKey)->first();
            if ($existing) {
                if ($existing->input_hash !== $hash) {
                    throw new \LogicException('同一操作标识不能修正不同来源。');
                }

                return GenerationArtifact::findOrFail($existing->result_artifact_id);
            }
            $source = $this->source($chapter);
            $old = $this->candidate($source);
            if ($source->id !== $sourceId || $old->id !== $candidateId || ! hash_equals($old->checksum, $checksum) || $task->control_version !== $controlVersion) {
                throw new \LogicException('审校来源已变化，请刷新后重新核对。');
            }
            $fixed = $this->corrected($source, $old, $rows);
            $issue = GenerationIssue::whereKey($source->issue_id)->lockForUpdate()->firstOrFail();
            $contract = [...$source->execution_contract,
                'manual_summary_evidence_revision' => ['source_run_id' => $source->id, 'candidate_id' => $old->id, 'checksum' => $old->checksum,
                    'actor_id' => auth()->id(), 'changes' => $fixed['changes']]];
            unset($contract['issue_action'], $contract['repair_source_identity'], $contract['repair_chain_issue_id']);
            app(GenerationRunCoordinator::class)->assertSources($task, $source->source_manifest, $contract);
            $run = GenerationRun::create(['novel_id' => $novel->id, 'generation_task_id' => $task->id, 'chapter_id' => $chapter->id, 'issue_id' => $issue->id,
                'stage' => $source->stage, 'scope_type' => 'chapter', 'scope_id' => $chapter->id, 'scope_key' => $source->scope_key, 'pipeline_version' => 'pipeline-v1',
                'logical_step_key' => 'book-summary-evidence-edit:'.$operationKey, 'idempotency_key' => 'book-summary-evidence-edit:'.$operationKey,
                'input_hash' => $hash, 'execution_contract' => $contract, 'source_manifest' => $source->source_manifest, 'state_version' => $source->state_version,
                'status' => RunStatus::Succeeded, 'attempt' => 1, 'started_at' => now(), 'finished_at' => now()]);
            $data = app(BookLifecycle::class)->validateSummary($run, $fixed['data']);
            $artifact = $run->artifacts()->create(['type' => ArtifactType::Summary, 'data' => $data,
                'content' => json_encode($fixed['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'checksum' => app(GenerationInputIdentity::class)->hash($data), 'source_manifest' => $source->source_manifest]);
            $pending = ['stage' => 'book_control', 'completed_run_id' => $run->id];
            $run->update(['result_artifact_id' => $artifact->id, 'checkpoint' => ['next' => $pending]]);
            $evidence = $issue->evidence;
            $evidence['operations'][] = ['action' => 'manual_summary_evidence', 'run_id' => $run->id, ...$contract['manual_summary_evidence_revision']];
            $issue->update(['status' => 'resolved', 'latest_run_id' => $run->id, 'evidence' => $evidence, 'resolved_by_run_id' => $run->id,
                'resolved_at' => now(), 'resolution_reason' => '人工修订摘要证据，原条目保留，正式来源及完整摘要合同复验通过。',
                'verification' => ['artifact_id' => $artifact->id, 'checksum' => $artifact->checksum, 'source_run_id' => $source->id]]);
            $task->update(['status' => 'running', 'stop_reason' => null, 'control_version' => $controlVersion + 1, 'pending_transition' => $pending]);

            return $artifact;
        });
        app(NovelBookWorkflow::class)->advance($artifact->generationRun->generation_task_id, $dispatch);

        return $artifact;
    }
}
