<?php

namespace App\Actions\Novels;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Services\GenerationCostPolicy;
use App\Services\GenerationInputIdentity;
use App\Services\NovelIntentContract;
use Illuminate\Support\Facades\DB;

/** 编辑只产生新输入版本；已发生费用、历史设定与正式章节不回写。 */
final class ReviseNovelIntentAction
{
    public function handle(int $taskId, int $expectedVersion, string $premise, array $options, array $clarifications = []): Novel
    {
        $initial = NovelGenerationTask::findOrFail($taskId);

        return DB::transaction(function () use ($initial, $expectedVersion, $premise, $options, $clarifications): Novel {
            $novel = Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            if ($novel->current_generation_task_id !== $task->id || $task->control_version !== $expectedVersion || in_array($task->status, ['cancelled', 'completed', 'failed'], true)) {
                throw new \LogicException('任务控制版本已改变，请刷新后重新查看影响。');
            }
            $intent = app(NovelIntentContract::class)->make($premise, $options);
            $intent['clarifications'] = $clarifications;
            $old = GenerationArtifact::findOrFail($task->intent_artifact_id);
            if ($clarifications === [] && $premise === $old->data['premise']) {
                $intent['clarifications'] = $old->data['clarifications'] ?? [];
            }
            foreach ($intent['clarifications'] as $answer) {
                // 篇幅冲突只接受领域选择中已列出的数值；模型不能通过文本修改目标。
                if ($answer['id'] === 'target_words' && preg_match('/^采用 (\d+) 字$/u', $answer['answer'], $match)) {
                    $selected = (int) $match[1];
                    if (! in_array($selected, [...$intent['premise_targets'], $intent['target_words']], true)) {
                        throw new \LogicException('篇幅选择已失效，请重新核对目标。');
                    }
                    $intent['target_words'] = $selected;
                    $intent['target_source'] = 'user_choice';
                    $intent['target_conflict'] = false;
                }
            }
            $before = $old->data + ['clarifications' => []];
            $hash = app(GenerationInputIdentity::class)->hash($intent);
            if (app(GenerationInputIdentity::class)->hash($before) === $hash) {
                return $novel;
            }
            // 在途模型允许返回隔离候选；排队尚未发送的旧输入立即取消。
            GenerationRun::where('generation_task_id', $task->id)->where('status', RunStatus::Queued)->update(['status' => RunStatus::Cancelled, 'finished_at' => now()]);
            $run = GenerationRun::create(['novel_id' => $novel->id, 'generation_task_id' => $task->id, 'scope_type' => 'novel', 'scope_id' => $novel->id,
                'scope_key' => 'book', 'stage' => GenerationStage::Intake, 'status' => RunStatus::Succeeded, 'attempt' => 1,
                'pipeline_version' => 'pipeline-v1', 'input_hash' => $hash, 'logical_step_key' => $novel->id.':intent:'.$old->id.':'.$hash,
                'idempotency_key' => hash('sha256', $task->id.':'.$old->id.':'.$hash), 'started_at' => now(), 'finished_at' => now()]);
            $artifact = GenerationArtifact::create(['generation_run_id' => $run->id, 'type' => ArtifactType::Intent, 'data' => $intent, 'checksum' => $hash, 'validation_status' => 'validated',
                'source_manifest' => [['artifact_id' => $old->id, 'checksum' => $old->checksum]]]);
            $run->update(['result_artifact_id' => $artifact->id]);
            // 输入被用户替换时关闭旧输入问题，记录为来源失效而非内容复验通过。
            GenerationIssue::where('generation_task_id', $task->id)->where('status', '!=', 'resolved')->get()->each(function ($issue) use ($old, $artifact, $run): void {
                if (collect(data_get($issue->source_identity, 'sources', []))->contains('artifact_id', $old->id)) {
                    $issue->update(['status' => 'resolved', 'resolved_by_run_id' => $run->id, 'resolved_at' => now(), 'resolution_reason' => '用户创建新意图，原问题来源已失效；不表示旧内容通过校验。',
                        'verification' => ['kind' => 'source_superseded', 'old_intent_id' => $old->id, 'new_intent_id' => $artifact->id]]);
                }
            });
            $policy = $task->policy_snapshot;
            $policy['intent_hash'] = $hash;
            $policy['cost_caps'] = $intent['budget'];
            $policy['history'][] = ['previous_intent_id' => $old->id, 'mode' => $task->mode, 'actor_id' => auth()->id(), 'at' => now()->toISOString()];
            $task->update(['intent_artifact_id' => $artifact->id, 'mode' => $intent['mode'], 'target_words' => $intent['target_words'], 'opening_chapter_count' => $intent['opening_chapter_count'],
                'policy_snapshot' => $policy, 'policy_revision' => $task->policy_revision + 1, 'control_version' => $task->control_version + 1,
                'status' => 'paused', 'stop_reason' => $novel->canonical_state_version_id ? 'future_revision_not_ready' : 'intent_revised',
                'stop_detail' => ['message' => $novel->canonical_state_version_id ? '新概述已保存，正式历史保留。未来规划修订等待 R04 接入。' : '新概述版本已保存，点击继续将重新预检与解析。'],
                'pending_transition' => $novel->canonical_state_version_id ? null : ['stage' => 'preflight', 'scope' => 'book', 'contract' => ['book_workflow' => 1, 'intent_artifact_id' => $artifact->id], 'sources' => [['artifact_id' => $artifact->id, 'checksum' => $hash]]]]);
            $settings = app(GenerationCostPolicy::class)->apply($novel->settings ?? [], $intent['budget'], auth()->id());
            unset($settings['auto_generate'], $settings['auto_commit'], $settings['auto_commit_configured']);
            $settings['generation']['chapter_target_words'] = $intent['chapter_target_words'];
            $novel->update(['premise' => $premise, 'target_words' => $intent['target_words'], 'settings' => $settings,
                'title' => $options['title'] ?? $novel->title, 'genre' => $options['genre'] ?? $novel->genre]);

            return $novel->fresh();
        });
    }
}
