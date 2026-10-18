<?php

namespace App\Console\Commands;

use App\Models\NovelGenerationTask;
use App\Services\NovelBookWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('novel:book {task : 整书任务 ID} {action=advance : advance/pause/resume/recheck} {--version= : 控制操作所需的当前 control_version}')]
#[Description('通过统一整书入口推进或控制任务，不绕过来源和暂停门禁')]
class ControlNovelBook extends Command
{
    /** CLI 与工作台使用同一控制版本，不隐式替用户确认旧操作。 */
    public function handle(NovelBookWorkflow $workflow): int
    {
        $task = NovelGenerationTask::findOrFail((int) $this->argument('task'));
        $action = $this->argument('action');
        try {
            if ($action === 'advance') {
                $workflow->advance($task->id);
            } elseif (in_array($action, ['pause', 'resume', 'recheck'], true) && $this->option('version') !== null) {
                $workflow->control($task->id, (int) $this->option('version'), $action);
            } else {
                $this->error('控制操作请指定 pause/resume/recheck 和 --version。');

                return self::FAILURE;
            }
        } catch (\LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $task->refresh();
        $this->info("任务 {$task->id}：{$task->status}，控制版本 {$task->control_version}");

        return self::SUCCESS;
    }
}
