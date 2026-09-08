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
    case EndingAudit = 'ending_audit';

    public function getLabel(): string
    {
        return match ($this) {
            self::EndingAudit => '结局审计',
            default => str($this->value)->headline()->toString(),
        };
    }
}
