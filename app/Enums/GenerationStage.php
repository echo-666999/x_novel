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

    public function getLabel(): string
    {
        return str($this->value)->headline()->toString();
    }
}
