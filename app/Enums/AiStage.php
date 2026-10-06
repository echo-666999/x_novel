<?php

namespace App\Enums;

enum AiStage: string
{
    case OutlineFoundation = 'outline_foundation';
    case OutlineStructure = 'outline_structure';
    case OutlineArcBeats = 'outline_arc_beats';
    case OutlineBeatDetail = 'outline_beat_detail';
    case Planner = 'planner';
    case Writer = 'writer';
    case Extractor = 'extractor';
    case Reviewer = 'reviewer';
    case Rewrite = 'rewrite';
    case Summary = 'summary';
    case Embedding = 'embedding';

    public function getLabel(): string
    {
        return match ($this) {
            self::OutlineFoundation => '全书大纲 · Foundation',
            self::OutlineStructure => '全书大纲 · Structure',
            self::OutlineArcBeats => '全书大纲 · Arc Beats',
            self::OutlineBeatDetail => '全书大纲 · Beat Detail',
            self::Planner => '章节规划',
            self::Writer => '场景写作',
            self::Extractor => '事件提取',
            self::Reviewer => '叙事审校',
            self::Rewrite => '章节重写',
            self::Summary => '摘要生成',
            self::Embedding => '向量生成',
        };
    }
}
