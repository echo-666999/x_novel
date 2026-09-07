<?php

namespace App\Enums;

enum ArtifactType: string
{
    case ChapterPlan = 'chapter_plan';
    case SceneDraft = 'scene_draft';
    case ChapterDraft = 'chapter_draft';
    case RewriteDraft = 'rewrite_draft';
    case ReviewResult = 'review_result';
    case EventCandidate = 'event_candidate';
    case StatePatch = 'state_patch';
    case Summary = 'summary';
    case Context = 'context';

    public function getLabel(): string
    {
        return str($this->value)->headline()->toString();
    }
}
