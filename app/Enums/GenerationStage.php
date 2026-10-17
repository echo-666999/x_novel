<?php

namespace App\Enums;

/** 章节生成的持久化阶段；每个需要 Provider 的阶段都必须有独立 Run。 */
enum GenerationStage: string
{
    case Intake = 'intake';
    case Preflight = 'preflight';
    case FoundationBlueprint = 'foundation_blueprint';
    case GoalWindow = 'goal_window';
    case ChapterWrite = 'chapter_write';
    case FragmentWrite = 'fragment_write';
    case Export = 'export';
    case ChapterPlanning = 'chapter_planning';
    case ChapterRecovery = 'chapter_recovery';
    case ContentRepair = 'content_repair';
    case SceneGeneration = 'scene_generation';
    case ChapterAssembly = 'chapter_assembly';
    case EventExtraction = 'event_extraction';
    case CoverageJudgment = 'coverage_judgment';
    case Review = 'review';
    case ReviewRepair = 'review_repair';
    case Rewrite = 'rewrite';
    case Commit = 'commit';
    case MemorySummary = 'memory_summary';
    case Embedding = 'embedding';
    case EndingAudit = 'ending_audit';

    /** 返回运行记录和操作页面使用的中文阶段名称。 */
    public function getLabel(): string
    {
        return match ($this) {
            self::Intake => '接收创作概述',
            self::Preflight => '运行预检',
            self::FoundationBlueprint => '全书方向与设定',
            self::GoalWindow => '近期章节目标',
            self::ChapterWrite => '章节写作',
            self::FragmentWrite => '分段写作',
            self::Export => '全文导出',
            self::ChapterPlanning => '章节规划',
            self::ChapterRecovery => '旧 Admission 章节恢复',
            self::ContentRepair => '内容自愈',
            self::SceneGeneration => '场景生成',
            self::ChapterAssembly => '章节组装',
            self::EventExtraction => '事件提取',
            self::CoverageJudgment => 'Coverage 语义复核',
            self::Review => '审校',
            self::ReviewRepair => '审校合同修复',
            self::Rewrite => '重写',
            self::Commit => '正式提交',
            self::MemorySummary => '记忆摘要',
            self::Embedding => '向量化',
            self::EndingAudit => '结局审计',
        };
    }
}
