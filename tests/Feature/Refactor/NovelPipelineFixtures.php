<?php

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\UsageRecorder;
use App\Enums\AiStage;
use App\Jobs\ExecuteGenerationRunJob;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\NovelGenerationTask;
use App\Services\GenerationRunCoordinator;
use App\Services\NovelBookWorkflow;
use App\Services\NovelFoundationContract;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** 完整固定响应使用真实执行器与请求账本，绝不发送收费请求。 */
function pipelineTask(array $options = []): NovelGenerationTask
{
    createAiProviderConnectionForTest();
    foreach ([AiStage::OutlineFoundation, AiStage::Planner, AiStage::Writer, AiStage::Extractor, AiStage::Reviewer, AiStage::Rewrite, AiStage::Summary] as $route) {
        createAiModelRouteForTest($route, 'pipeline-fixture');
    }
    config(['horizon.defaults.supervisor-1.timeout' => 350, 'generation.execution.lease_seconds' => 390, 'queue.connections.redis.retry_after' => 480]);
    Queue::fake([ExecuteGenerationRunJob::class]);
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '雨季邮差林舟寻找未来来信，主角不会游泳。', ['target_words' => 5000, 'generation_chapter_target_words' => 500, 'mode' => 'manual_review', ...$options]);
    $run = app(NovelBookWorkflow::class)->advance($task->id, false);
    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));

    return $task->fresh();
}

/** 每次只执行当前待办，前一步输出采用后才能知道下一步真实来源。 */
function pipelineStep(NovelGenerationTask $task, ?array $override = null, array $metadata = []): GenerationRun
{
    $task->refresh();
    $run = GenerationRun::findOrFail($task->pending_transition['run_id']);
    $phase = $run->execution_contract['book_stage'] ?? 'foundation';
    $input = $run->execution_contract['input'] ?? [];
    $data = $override ?? match ($phase) {
        'foundation' => (function () use ($task) {
            $intent = GenerationArtifact::findOrFail($task->intent_artifact_id)->data;
            $fields = [];
            foreach (NovelFoundationContract::FIELDS as $field) {
                $fields[$field] = ['value' => '设定'.$field, 'source' => 'system', 'evidence' => []];
            }

            return ['fields' => $fields, 'length' => ['target_words' => 5000, 'source' => 'user', 'evidence' => []], 'requirements' => array_map(fn ($r) => [...$r, 'treatment' => 'retained'], $intent['requirements']),
                'promises' => ['找到来信者'], 'characters' => [['name' => '林舟', 'role' => '主角', 'description' => '邮差']], 'world_entities' => [['name' => '旧港', 'description' => '雨季港城']], 'conflicts' => []];
        })(),
        'blueprint' => ['mainline' => '追查来信', 'ending' => '改变洪水结局', 'turning_points' => ['收到信', '揭开身份', '救下港城'], 'reader_promises' => ['找到来信者'], 'character_arcs' => ['从逃避到承担'], 'foreshadowing_goals' => ['字迹秘密'], 'optional_volume_groups' => []],
        'window' => ['goals' => array_fill(0, $input['count'], ['objective' => '追查线索', 'expected_change' => '找到线索', 'promise_refs' => ['promise-1'], 'required_facts' => ['不能游泳'], 'forbidden_conflicts' => ['不能下水救人']])],
        'plan' => ['chapter_title' => '雨中的未来来信', 'chapter_function' => '引入线索', 'character_change' => '决定追查', 'pov' => '第三人称林舟', 'time_anchor' => '雨季首日', 'tone' => '悬疑', 'ending_point' => '发现字迹', 'must_reveal' => ['未来来信'], 'must_not_reveal' => ['寄信人'], 'required_facts' => ['不会游泳'], 'forbidden_conflicts' => ['不能下水'], 'segments' => []],
        'write', 'fragment' => ['body' => "林舟站在港口，雨打湿了信封。他决定沿岸寻找来信者。\n\n钟声响起，远处有人叫他的名字。他把信收进衣袋，转身走向邮局。", 'complete' => true, 'temporary_state' => ['已收到信']],
        'extract' => ['events' => []],
        'review' => ['findings' => [], 'goal_checks' => [['goal_key' => $run->execution_contract['goal_refs'][0]['key'], 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]],
        default => throw new RuntimeException('未提供夹具 '.$phase),
    };
    $data = $override ?? $data;
    $fake = (new FakeAiProvider)->enqueue(new AiResponse(json_encode($data, JSON_UNESCAPED_UNICODE), $data, 100, 200, 0, 10, 'pipeline-'.$run->id, 'pipeline-fixture', $metadata));
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));
    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));
    expect($fake->requests())->toHaveCount(1);

    return $run->fresh();
}

/** 到指定请求前停住，测试失败分支仍经过真实入口和租约。 */
function pipelineUntil(NovelGenerationTask $task, string $phase): GenerationRun
{
    for ($i = 0; $i < 12; $i++) {
        $task->refresh();
        $run = GenerationRun::findOrFail($task->pending_transition['run_id']);
        if (($run->execution_contract['book_stage'] ?? '') === $phase) {
            return $run;
        }
        pipelineStep($task);
    }
    throw new RuntimeException('未到达请求阶段 '.$phase);
}
