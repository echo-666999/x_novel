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
            self::Planner => 'Planner',
            self::Writer => 'Writer',
            self::Assembler => 'Assembler',
            self::Extractor => 'Extractor',
            self::Reviewer => 'Reviewer',
            self::Rewrite => 'Rewrite',
            self::Summary => 'Summary',
            self::Embedding => 'Embedding',
        };
    }
}
