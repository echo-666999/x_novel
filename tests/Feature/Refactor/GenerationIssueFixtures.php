<?php

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\Enums\AiStage;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Services\GenerationIssueService;
use App\Services\GenerationRunCoordinator;
use Illuminate\Support\Str;

/** 使用真实任务与冻结来源，不导入旧小说；模型不会被请求。 */
function issueFixture(string $code = 'provider_timeout', bool $retryable = true): array
{
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '修复测试：夜行邮差在旧城寻找失落信件。');
    $run = app(GenerationRunCoordinator::class)->enqueue($task->id, GenerationStage::Preflight, 'book', ['state_version_id' => null]);
    $run->update(['status' => RunStatus::Failed, 'error_code' => $code, 'error_message' => 'fixture error', 'error_retryable' => $retryable, 'finished_at' => now()]);
    $issue = app(GenerationIssueService::class)->record($run);

    return [$task->fresh(), $run->fresh(), $issue];
}

/** 仅在测试中明确授权诊断模型，生产空白授权不会被自动扩大。 */
function issueDiagnosticFixture(): array
{
    [$task, $run, $issue] = issueFixture('unknown_error', false);
    createAiProviderConnectionForTest();
    createAiModelRouteForTest(AiStage::Reviewer, 'diagnostic-fixture');
    $policy = $task->policy_snapshot;
    $policy['models'] = [['provider' => 'openai', 'model' => 'diagnostic-fixture']];
    $task->update(['policy_snapshot' => $policy]);

    return [$task, $run, $issue];
}
function issueProposal(int $issueId): array
{
    return ['issue_id' => $issueId, 'explanation' => '需要核对服务状态。', 'action' => 'rebuild_context',
        'target' => '当前问题', 'impact' => '仅供分析', 'steps' => ['查看来源差异'], 'verification' => '按最新来源重验'];
}
