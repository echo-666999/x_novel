<?php

namespace App\Enums;

enum AiStage: string
{
    case Planner = 'planner';
    case Writer = 'writer';
    case Assembler = 'assembler';
    case Extractor = 'extractor';
    case Reviewer = 'reviewer';
    case Rewrite = 'rewrite';
    case Summary = 'summary';
    case Embedding = 'embedding';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planner => '章节规划',
            self::Writer => '场景写作',
            self::Assembler => '章节组装',
            self::Extractor => '事件提取',
            self::Reviewer => '叙事审校',
            self::Rewrite => '章节重写',
            self::Summary => '摘要生成',
            self::Embedding => '向量生成',
        };
    }
}
