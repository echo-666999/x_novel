<?php

namespace App\Actions\Chapters;

use App\Models\Chapter;
use App\Models\GenerationRun;

/**
 * 兼容旧调用名称，并强制转入完整 Admission v1 章节恢复。
 *
 * 单独创建 Event Extraction 恢复 Run 会让 Coverage、Review、Rewrite 和 Summary 再次读取残缺的 v1 快照，
 * 因此旧入口不得继续保留局部恢复行为。
 */
final class RecoverLegacyEventExtractionAction
{
    public function __construct(
        private readonly RecoverLegacyChapterPipelineAction $completeRecovery,
    ) {}

    /** 旧调用方也只能创建完整恢复合同，避免产生新的半恢复来源链。 */
    public function handle(Chapter $chapter): GenerationRun
    {
        return $this->completeRecovery->handle($chapter);
    }
}
