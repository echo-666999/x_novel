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
use App\Services\ChapterDraftService;
use App\Services\GenerationInputIdentity;
use App\Services\GenerationRunCoordinator;
use App\Services\NovelBookWorkflow;
use App\Services\NovelChapterPipeline;
use App\Services\NovelStageContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 仅恢复保存审校或事件候选的唯一逐字定位；原内容不变，完整门禁重新执行。 */
final class RepairBookReviewEvidenceAction
{
    public function source(Chapter $chapter): GenerationRun
    {
        $source = app(ReviseBookChapterEventsAction::class)->source($chapter);
        if ($source->status !== RunStatus::Failed || $source->error_code !== 'book_evidence_invalid'
            || ! in_array(data_get($source->execution_contract, 'book_stage'), ['review', 'extract'], true)) {
            throw new \LogicException('仅当前审校或事件提取的证据定位失败可执行此修正。');
        }

        return $source;
    }

    public function candidate(GenerationRun $source): GenerationArtifact
    {
        return $source->artifacts()->where('type', $this->artifactType($source))->where('validation_status', 'rejected')->latest('id')->firstOrFail();
    }

    private function artifactType(GenerationRun $source): ArtifactType
    {
        return data_get($source->execution_contract, 'book_stage') === 'extract' ? ArtifactType::EventCandidate : ArtifactType::ReviewResult;
    }

    /** @return array{data: array, changes: array} */
    public function corrected(GenerationRun $source, GenerationArtifact $candidate): array
    {
        $data = $candidate->data;
        $phase = data_get($source->execution_contract, 'book_stage');
        if (! in_array($phase, ['review', 'extract'], true)
            || $candidate->generation_run_id !== $source->id || $candidate->type !== $this->artifactType($source)
            || ! app(JsonSchemaValidator::class)->matches($data, app(NovelStageContract::class)->schema($phase))) {
            throw new \LogicException('保存响应结构不完整，不能仅修正偏移。');
        }
        $draft = GenerationArtifact::findOrFail($source->execution_contract['draft_artifact_id']);
        $changes = [];
        foreach ($phase === 'extract' ? ['events'] : ['goal_checks', 'findings'] as $group) {
            foreach ($data[$group] as $index => $item) {
                foreach ($item['evidence'] as $position => $reference) {
                    $paragraph = collect($draft->data['paragraph_index'])->firstWhere('key', $reference['paragraph_key']);
                    $quote = $reference['quote'];
                    if (! $paragraph || trim($quote) === '') {
                        throw new \LogicException('证据段落或引文无效，拒绝猜测位置。');
                    }
                    $text = mb_substr($draft->content, $paragraph['offset'], $paragraph['length']);
                    if ($reference['offset'] >= 0 && mb_substr($text, $reference['offset'], mb_strlen($quote)) === $quote) {
                        continue;
                    }
                    $offset = mb_strpos($text, $quote);
                    if ($offset === false || mb_strpos($text, $quote, $offset + 1) !== false) {
                        throw new \LogicException('引文未在指定段落唯一逐字匹配，拒绝修正。');
                    }
                    $data[$group][$index]['evidence'][$position]['offset'] = $offset;
                    $changes[] = ['path' => "$group.$index.evidence.$position.offset", 'before' => $reference['offset'], 'after' => $offset,
                        'paragraph_key' => $reference['paragraph_key'], 'quote' => $quote];
                    app(ChapterDraftService::class)->evidence($draft, [...$reference, 'offset' => $offset]);
                }
            }
        }
        if ($changes === []) {
            throw new \LogicException('没有可修正的偏移，不重复处理。');
        }
        // 覆盖、硬规则、原判断等所有校验继续执行，不把定位成功当作 PASS。
        app(NovelChapterPipeline::class)->validateOutput($source, $data);

        return ['data' => $data, 'changes' => $changes];
    }

    public function handle(int $chapterId, int $sourceId, int $candidateId, string $checksum, int $controlVersion, string $operationKey, bool $dispatch = true): GenerationArtifact
    {
        Validator::make(['key' => $operationKey], ['key' => 'required|uuid'])->validate();
        $initial = Chapter::findOrFail($chapterId);
        $artifact = DB::transaction(function () use ($initial, $sourceId, $candidateId, $checksum, $controlVersion, $operationKey): GenerationArtifact {
            $novel = Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($novel->current_generation_task_id)->lockForUpdate()->firstOrFail();
            $chapter = Chapter::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $hash = app(GenerationInputIdentity::class)->hash([$chapter->id, $sourceId, $candidateId, $checksum, $controlVersion]);
            $existing = GenerationRun::where('idempotency_key', 'book-evidence-edit:'.$operationKey)->first();
            if ($existing) {
                if ($existing->input_hash !== $hash) {
                    throw new \LogicException('同一操作标识不能修正不同来源。');
                }

                return GenerationArtifact::findOrFail($existing->result_artifact_id);
            }
            $source = $this->source($chapter);
            $old = $this->candidate($source);
            if ($source->id !== $sourceId || $old->id !== $candidateId || ! hash_equals($old->checksum, $checksum) || $task->control_version !== $controlVersion) {
                throw new \LogicException('响应来源已变化，请刷新后重新核对。');
            }
            $fixed = $this->corrected($source, $old);
            $issue = GenerationIssue::whereKey($source->issue_id)->lockForUpdate()->firstOrFail();
            $contract = [...$source->execution_contract, 'repair_chain_issue_id' => $issue->id,
                'manual_evidence_revision' => ['source_run_id' => $source->id, 'candidate_id' => $old->id, 'checksum' => $old->checksum,
                    'actor_id' => auth()->id(), 'changes' => $fixed['changes']]];
            unset($contract['issue_action'], $contract['repair_source_identity']);
            app(GenerationRunCoordinator::class)->assertSources($task, $source->source_manifest, $contract);
            $run = GenerationRun::create(['novel_id' => $novel->id, 'generation_task_id' => $task->id, 'chapter_id' => $chapter->id, 'issue_id' => $issue->id,
                'stage' => $source->stage, 'scope_type' => 'chapter', 'scope_id' => $chapter->id, 'scope_key' => $source->scope_key, 'pipeline_version' => 'pipeline-v1',
                'logical_step_key' => 'book-evidence-edit:'.$operationKey, 'idempotency_key' => 'book-evidence-edit:'.$operationKey,
                'input_hash' => $hash, 'execution_contract' => $contract, 'source_manifest' => $source->source_manifest, 'state_version' => $source->state_version,
                'status' => RunStatus::Succeeded, 'attempt' => 1, 'started_at' => now(), 'finished_at' => now()]);
            $data = app(NovelChapterPipeline::class)->validateOutput($run, $fixed['data']);
            $artifact = $run->artifacts()->create(['type' => $this->artifactType($source), 'data' => $data,
                'content' => json_encode($fixed['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'checksum' => app(GenerationInputIdentity::class)->hash($data), 'source_manifest' => $source->source_manifest]);
            $pending = ['stage' => 'book_control', 'completed_run_id' => $run->id];
            $run->update(['result_artifact_id' => $artifact->id, 'checkpoint' => ['next' => $pending]]);
            $evidence = $issue->evidence;
            $evidence['operations'][] = ['action' => 'manual_evidence', 'run_id' => $run->id, ...$contract['manual_evidence_revision']];
            $issue->update(['status' => 'repairing', 'latest_run_id' => $run->id, 'evidence' => $evidence]);
            $task->update(['status' => 'running', 'stop_reason' => null, 'control_version' => $controlVersion + 1, 'pending_transition' => $pending]);

            return $artifact;
        });
        app(NovelBookWorkflow::class)->advance($artifact->generationRun->generation_task_id, $dispatch);

        return $artifact;
    }
}
