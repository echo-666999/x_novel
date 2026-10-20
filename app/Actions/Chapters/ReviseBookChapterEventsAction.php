<?php

namespace App\Actions\Chapters;

use App\AI\JsonSchemaValidator;
use App\Enums\ArtifactType;
use App\Enums\ArtifactValidationStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Services\GenerationInputIdentity;
use App\Services\GenerationRunCoordinator;
use App\Services\NovelBookWorkflow;
use App\Services\NovelChapterPipeline;
use App\Services\NovelStageContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 人工修订已有事件或据当前正文补漏；保存审计后必须重新独立审校。 */
final class ReviseBookChapterEventsAction
{
    public function source(Chapter $chapter): GenerationRun
    {
        $task = NovelGenerationTask::findOrFail($chapter->novel->current_generation_task_id);
        $run = GenerationRun::where('generation_task_id', $task->id)->where('chapter_id', $chapter->id)
            ->whereIn('execution_contract->book_stage', ['review', 'extract'])->latest('id')->firstOrFail();
        if (data_get($run->execution_contract, 'book_stage') === 'extract'
            && ($run->status !== RunStatus::Failed || ! in_array($run->error_code, ['book_schema_invalid', 'book_evidence_invalid', 'book_entity_reference_invalid'], true))) {
            throw new \LogicException('仅已停止并保存候选的提取失败可人工修订。');
        }
        $issue = GenerationIssue::findOrFail($run->issue_id);
        if ($chapter->canonical_artifact_id || $issue->status === 'resolved' || $issue->latest_run_id !== $run->id
            || $issue->generation_task_id !== $task->id || $issue->chapter_id !== $chapter->id
            || ! in_array($run->status, [RunStatus::Succeeded, RunStatus::Failed], true)
            || in_array($task->status, ['paused', 'cancelled', 'completed', 'failed'], true)) {
            throw new \LogicException('仅当前未正式采用、已停止的提取或审校问题可人工修订事件。');
        }
        if (GenerationRun::where('generation_task_id', $task->id)->where('pipeline_version', 'pipeline-v1')->whereIn('status', ['queued', 'running'])->exists()) {
            throw new \LogicException('仍有执行在途，请等待停止后再修订事件。');
        }
        app(GenerationRunCoordinator::class)->assertSources($task, $run->source_manifest, $run->execution_contract);

        return $run;
    }

    public function candidate(GenerationRun $source): GenerationArtifact
    {
        if (data_get($source->execution_contract, 'book_stage') === 'extract') {
            $candidate = $source->artifacts()->where('type', ArtifactType::EventCandidate)->latest('id')->firstOrFail();
            if (! app(JsonSchemaValidator::class)->matches($candidate->data, app(NovelStageContract::class)->schema('extract'))) {
                throw new \LogicException('保存的提取响应不是完整事件结构，不能逐项编辑。');
            }

            return $candidate;
        }

        return GenerationArtifact::findOrFail($source->execution_contract['event_artifact_id']);
    }

    /** @return array<int, array<string, mixed>> */
    private function events(GenerationArtifact $candidate): array
    {
        return array_map(fn (array $event): array => [...$event, 'payload' => is_string($event['payload'])
            ? json_decode($event['payload'], true, flags: JSON_THROW_ON_ERROR) : $event['payload']], $candidate->data['events']);
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(GenerationArtifact $candidate): array
    {
        return array_map(fn (array $event): array => [
            'payload' => json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'story_time' => $event['story_time'] ?? '', 'omit' => false, 'reason' => '',
        ], $this->events($candidate));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array{event: array<string, mixed>, reason: string}>  $additions
     */
    public function handle(int $chapterId, int $reviewRunId, int $candidateId, string $candidateHash, int $controlVersion, array $rows, string $operationKey, bool $dispatch = true, array $additions = []): GenerationArtifact
    {
        Validator::make(['rows' => $rows, 'key' => $operationKey], [
            'key' => 'required|uuid', 'rows' => 'present|array|max:40', 'rows.*.payload' => 'required|string|max:20000',
            'rows.*.story_time' => 'present|nullable|string|max:4000', 'rows.*.omit' => 'required|boolean', 'rows.*.reason' => 'present|nullable|string|max:4000',
        ])->validate();
        Validator::make(['additions' => $additions], [
            'additions' => 'array|max:40', 'additions.*.event' => 'required|array',
            'additions.*.reason' => 'required|string|max:4000',
        ])->validate();
        if (! array_is_list($additions)) {
            throw new \LogicException('补充事件必须是逐项列表。');
        }
        $initial = Chapter::findOrFail($chapterId);
        $artifact = DB::transaction(function () use ($initial, $reviewRunId, $candidateId, $candidateHash, $controlVersion, $rows, $operationKey, $additions): GenerationArtifact {
            $novel = Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($novel->current_generation_task_id)->lockForUpdate()->firstOrFail();
            $chapter = Chapter::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $identity = [$chapter->id, $reviewRunId, $candidateId, $candidateHash, $controlVersion, $rows];
            if ($additions !== []) {
                $identity[] = $additions;
            }
            $hash = app(GenerationInputIdentity::class)->hash($identity);
            $existing = GenerationRun::where('idempotency_key', 'book-events-edit:'.$operationKey)->first();
            if ($existing) {
                if ($existing->input_hash !== $hash) {
                    throw new \LogicException('相同操作标识不能保存不同事件修订。');
                }

                return GenerationArtifact::findOrFail($existing->result_artifact_id);
            }
            $source = $this->source($chapter);
            if ($task->control_version !== $controlVersion || $source->id !== $reviewRunId || $this->candidate($source)->id !== $candidateId) {
                throw new \LogicException('事件或审校来源已变化，输入保留；请读取最新事件后对照。');
            }
            $old = GenerationArtifact::findOrFail($candidateId);
            if ($old->type !== ArtifactType::EventCandidate || ! hash_equals($old->checksum, $candidateHash) || ! array_is_list($rows) || count($rows) !== count($old->data['events'])) {
                throw new \LogicException('原事件候选身份或事件数量不匹配。');
            }
            $draft = GenerationArtifact::findOrFail($chapter->current_draft_artifact_id);
            $events = [];
            $changes = [];
            foreach ($this->events($old) as $index => $event) {
                $row = $rows[$index];
                $payload = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($payload) || array_is_list($payload)) {
                    throw new \LogicException('事件状态必须是 JSON 对象。');
                }
                $time = $row['story_time'] ?: null;
                if ($row['omit'] || app(GenerationInputIdentity::class)->hash($payload) !== app(GenerationInputIdentity::class)->hash($event['payload']) || $time !== ($event['story_time'] ?? null)) {
                    if (blank($row['reason'])) {
                        throw new \LogicException('每项事件修改或剔除都必须填写原文依据与理由。');
                    }
                    $changes[] = ['event_index' => $index, 'before' => $event, 'after' => $row['omit'] ? null : [...$event, 'payload' => $payload, 'story_time' => $time], 'reason' => $row['reason']];
                }
                if ($row['omit']) {
                    continue;
                }
                $evidence = array_map(function (array $e) use ($draft): array {
                    if (isset($e['paragraph_key'])) {
                        return $e;
                    }
                    $p = collect($draft->data['paragraph_index'])->first(fn (array $p): bool => $e['start_offset'] >= $p['offset'] && $e['end_offset'] <= $p['offset'] + $p['length']);
                    if (! $p || $e['artifact_id'] !== $draft->id) {
                        throw new \LogicException('旧事件证据不属于当前稿的完整段落。');
                    }

                    return ['paragraph_key' => $p['key'], 'offset' => $e['start_offset'] - $p['offset'], 'quote' => $e['quote']];
                }, $event['evidence']);
                $events[] = [...$event, 'payload' => $payload, 'story_time' => $time, 'evidence' => $evidence];
            }
            foreach ($additions as $addition) {
                $event = $addition['event'];
                if (! app(JsonSchemaValidator::class)->matches(['events' => [$event]], app(NovelStageContract::class)->schema('extract'))) {
                    throw new \LogicException('补充事件必须满足提取合同，提供完整事件、状态与当前正文证据。');
                }
                $type = EventType::from($event['event_type']);
                if (in_array($type, [EventType::CharacterIntroduced, EventType::WorldEntityIntroduced], true) || $type->allowedSubjectTypes() === []) {
                    throw new \LogicException('补漏仅允许已有实体的故事事件，不能引入实体或直接修正正式事件。');
                }
                $event['payload'] = json_decode($event['payload'], true, flags: JSON_THROW_ON_ERROR);
                $changes[] = ['event_index' => count($events), 'before' => null, 'after' => $event, 'reason' => $addition['reason']];
                $events[] = $event;
            }
            if (count($events) > 40) {
                throw new \LogicException('修订和补漏后事件总数不能超过 40。');
            }
            if ($changes === []) {
                throw new \LogicException('事件没有变化，不重复请求独立审校。');
            }
            $issue = GenerationIssue::whereKey($source->issue_id)->lockForUpdate()->firstOrFail();
            $contract = [...array_intersect_key($source->execution_contract, array_flip(['book_workflow', 'intent_artifact_id', 'state_version_id', 'chapter_id', 'chapter_plan_id', 'draft_artifact_id', 'goal_refs'])),
                'book_stage' => 'extract', 'input' => $source->execution_contract['input'], 'manual_event_revision' => true,
                'repair_chain_issue_id' => $issue->id, 'actor_id' => auth()->id(), 'changes' => $changes,
                'manual_source_candidate' => ['artifact_id' => $old->id, 'checksum' => $old->checksum]];
            unset($contract['input']['event_candidate'], $contract['input']['state_patch'], $contract['input']['rule_findings']);
            $sources = $source->source_manifest;
            if ($old->validation_status === ArtifactValidationStatus::Validated) {
                $sources[] = ['artifact_id' => $old->id, 'checksum' => $old->checksum];
            }
            $run = GenerationRun::create(['novel_id' => $novel->id, 'generation_task_id' => $task->id, 'chapter_id' => $chapter->id, 'issue_id' => $issue->id,
                'stage' => GenerationStage::EventExtraction, 'scope_type' => 'chapter', 'scope_id' => $chapter->id, 'pipeline_version' => 'pipeline-v1',
                'scope_key' => 'chapter:'.$chapter->id, 'logical_step_key' => 'book-events-edit:'.$operationKey, 'idempotency_key' => 'book-events-edit:'.$operationKey,
                'input_hash' => $hash, 'execution_contract' => $contract, 'source_manifest' => $sources, 'state_version' => $source->state_version,
                'status' => RunStatus::Succeeded, 'attempt' => 1, 'started_at' => now(), 'finished_at' => now()]);
            $data = app(NovelChapterPipeline::class)->validateOutput($run, ['events' => $events]);
            $artifact = GenerationArtifact::create(['generation_run_id' => $run->id, 'type' => ArtifactType::EventCandidate,
                'version' => (int) GenerationArtifact::where('novel_id', $novel->id)->where('type', ArtifactType::EventCandidate)->max('version') + 1,
                'source_manifest' => $sources, 'data' => $data, 'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'checksum' => app(GenerationInputIdentity::class)->hash($data)]);
            $pending = ['stage' => 'book_control', 'completed_run_id' => $run->id];
            $run->update(['result_artifact_id' => $artifact->id, 'checkpoint' => ['next' => $pending]]);
            $evidence = $issue->evidence;
            $evidence['operations'][] = ['action' => 'manual_events', 'run_id' => $run->id, 'artifact_id' => $artifact->id, 'actor_id' => auth()->id(), 'changes' => $changes];
            $issue->update(['status' => 'repairing', 'evidence' => $evidence, 'latest_run_id' => $run->id]);
            $task->update(['status' => 'running', 'stop_reason' => null, 'control_version' => $controlVersion + 1, 'pending_transition' => $pending]);

            return $artifact;
        });
        try {
            app(NovelBookWorkflow::class)->advance($artifact->generationRun->generation_task_id, $dispatch);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $artifact;
    }
}
