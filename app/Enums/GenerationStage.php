<?php

namespace App\Enums;

enum GenerationStage: string
{
    case ChapterPlanning = 'chapter_planning';
    case SceneGeneration = 'scene_generation';
    case ChapterAssembly = 'chapter_assembly';
    case EventExtraction = 'event_extraction';
    case Review = 'review';
    case Rewrite = 'rewrite';
    case Commit = 'commit';
    case MemorySummary = 'memory_summary';
    case Embedding = 'embedding';
    case EndingAudit = 'ending_audit';

    public function getLabel(): string
    {
        return match ($this) {
            self::ChapterPlanning => '章节规划',
            self::SceneGeneration => '场景生成',
            self::ChapterAssembly => '章节组装',
            self::EventExtraction => '事件提取',
            self::Review => '审校',
            self::Rewrite => '重写',
            self::Commit => '正式提交',
            self::MemorySummary => '记忆摘要',
            self::Embedding => '向量化',
            self::EndingAudit => '结局审计',
        };
    }
}
