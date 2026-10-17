<?php

namespace App\Actions\Novels;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Services\GenerationCostPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 单次提交原子创建意图和任务；不调用模型、不建立旧规划树、不产生正式事件。 */
final class CreateNovelGenerationTaskAction
{
    public function handle(string $submissionKey, string $premise, array $options = []): NovelGenerationTask
    {
        Validator::make(['submission' => $submissionKey, 'premise' => $premise, ...$options], [
            'submission' => ['required', 'uuid'], 'premise' => ['required', 'string', 'max:50000'],
            'target_words' => ['sometimes', 'integer', 'min:1'],
            'mode' => ['sometimes', 'in:full_book,opening,manual_review'],
            'opening_chapter_count' => ['sometimes', 'integer', 'min:1'],
        ])->validate();
        $intent = ['premise' => $premise, 'target_words' => $options['target_words'] ?? 100000,
            'mode' => $options['mode'] ?? 'full_book', 'opening_chapter_count' => $options['opening_chapter_count'] ?? 3];
        $policy = app(GenerationCostPolicy::class)->normalize((array) ($options['budget_limits'] ?? []));
        $intent['budget'] = $policy;
        $hash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $existing = NovelGenerationTask::where('submission_key', $submissionKey)->first();
        if ($existing) {
            return $this->sameIntent($existing, $hash);
        }
        try {
            return DB::transaction(function () use ($submissionKey, $premise, $intent, $hash, $policy): NovelGenerationTask {
                $novel = Novel::create(['title' => '未命名小说', 'genre' => '待解析', 'premise' => $premise,
                    'target_words' => $intent['target_words'], 'settings' => app(GenerationCostPolicy::class)->apply(['schema_version' => 1], $policy, auth()->id())]);
                $run = GenerationRun::create(['novel_id' => $novel->id, 'scope_type' => 'novel', 'scope_id' => $novel->id,
                    'scope_key' => 'book', 'stage' => GenerationStage::Intake, 'status' => RunStatus::Succeeded,
                    'attempt' => 1, 'idempotency_key' => 'intake:'.$submissionKey, 'input_hash' => $hash,
                    'logical_step_key' => $novel->id.':intake:'.$hash, 'pipeline_version' => 'pipeline-v1',
                    'started_at' => now(), 'finished_at' => now()]);
                $artifact = GenerationArtifact::create(['generation_run_id' => $run->id,
                    'type' => ArtifactType::Intent, 'data' => $intent, 'checksum' => $hash, 'validation_status' => 'validated']);
                $task = NovelGenerationTask::create(['novel_id' => $novel->id, 'submission_key' => $submissionKey,
                    'intent_artifact_id' => $artifact->id, 'target_words' => $intent['target_words'],
                    'mode' => $intent['mode'], 'opening_chapter_count' => $intent['opening_chapter_count'],
                    'policy_snapshot' => ['schema_version' => 1, 'intent_hash' => $hash, 'cost_caps' => $policy, 'models' => []],
                    'pending_transition' => ['stage' => 'preflight', 'scope' => 'book', 'source_identity' => $hash]]);
                $run->update(['generation_task_id' => $task->id, 'result_artifact_id' => $artifact->id]);
                $novel->update(['current_generation_task_id' => $task->id]);

                return $task;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // 双连接同时点击只返回已提交身份，不留下另一部半初始化小说。
            $task = NovelGenerationTask::where('submission_key', $submissionKey)->first();
            if (! $task) {
                throw $exception;
            }

            return $this->sameIntent($task, $hash);
        }
    }

    /** 幂等键不能被用于偷偷替换用户原始概述。 */
    private function sameIntent(NovelGenerationTask $task, string $hash): NovelGenerationTask
    {
        if (data_get($task->policy_snapshot, 'intent_hash') !== $hash) {
            throw new \LogicException('该提交标识已用于不同概述或目标，请重新提交。');
        }

        return $task;
    }
}
