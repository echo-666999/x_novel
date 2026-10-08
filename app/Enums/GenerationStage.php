<?php

namespace App\Enums;

/** 章节生成的持久化阶段；每个需要 Provider 的阶段都必须有独立 Run。 */
enum GenerationStage: string
{
    case ChapterPlanning = 'chapter_planning';
    case ChapterRecovery = 'chapter_recovery';
    case SceneGeneration = 'scene_generation';
    case ChapterAssembly = 'chapter_assembly';
    case EventExtraction = 'event_extraction';
    case CoverageJudgment = 'coverage_judgment';
    case Review = 'review';
    case Rewrite = 'rewrite';
    case Commit = 'commit';
    case MemorySummary = 'memory_summary';
    case Embedding = 'embedding';
    case EndingAudit = 'ending_audit';

    /** 返回运行记录和操作页面使用的中文阶段名称。 */
    public function getLabel(): string
    {
        return match ($this) {
            self::ChapterPlanning => '章节规划',
            self::ChapterRecovery => '旧 Admission 章节恢复',
            self::SceneGeneration => '场景生成',
            self::ChapterAssembly => '章节组装',
            self::EventExtraction => '事件提取',
            self::CoverageJudgment => 'Coverage 语义复核',
            self::Review => '审校',
            self::Rewrite => '重写',
            self::Commit => '正式提交',
            self::MemorySummary => '记忆摘要',
            self::Embedding => '向量化',
            self::EndingAudit => '结局审计',
        };
    }
}
