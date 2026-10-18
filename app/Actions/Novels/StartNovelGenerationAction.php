<?php

namespace App\Actions\Novels;

use App\Enums\NovelStatus;
use App\Models\Novel;
use App\Services\NovelBookWorkflow;
use App\Services\NovelGenerationReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartNovelGenerationAction
{
    public function __construct(private readonly NovelGenerationReadiness $readiness) {}

    public function handle(Novel $novel): Novel
    {
        // 新版整书统一入口不要求旧 Volume/Arc 准备度。
        if ($novel->current_generation_task_id) {
            app(NovelBookWorkflow::class)->advance($novel->current_generation_task_id);

            return $novel->fresh();
        }

        // 启动正文生成只切换生命周期状态，不在此处创建章节或调用模型。
        return DB::transaction(function () use ($novel): Novel {
            // 锁定 Novel，避免两个请求同时通过准备度检查并重复启动。
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if (! in_array($locked->status, [NovelStatus::Draft, NovelStatus::Planning], true)) {
                throw ValidationException::withMessages(['novel' => '只有草稿或规划中的小说可以开始正文生成。']);
            }

            // UI 与领域动作共享准备度事实；事务内仍要基于已锁定 Novel 重新检查。
            $readiness = $this->readiness->evaluate($locked);
            if (! $this->readiness->allReady($readiness)) {
                throw ValidationException::withMessages([
                    'novel' => $this->readiness->missingMessageFor($readiness),
                ]);
            }

            $locked->update(['status' => NovelStatus::Generating]);

            return $locked->refresh();
        });
    }
}
