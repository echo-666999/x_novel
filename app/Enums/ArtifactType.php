<?php

namespace App\Enums;

/** 不可变生成产物类型，新增项对应三层规划与请求证据。 */
enum ArtifactType: string
{
    case Intent = 'intent';
    case FoundationBlueprint = 'foundation_blueprint';
    case GoalWindow = 'goal_window';
    case ChapterFragment = 'chapter_fragment';
    case RepairProposal = 'repair_proposal';
    case ExportManifest = 'export_manifest';
    case OperationRecord = 'operation_record';
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
    case OutlineFoundation = 'outline_foundation';
    case OutlineStructure = 'outline_structure';
    case OutlineArcBeats = 'outline_arc_beats';
    case OutlineSkeleton = 'outline_skeleton';
    case OutlineBeatDetail = 'outline_beat_detail';
    case OutlineBlueprint = 'outline_blueprint';

    public function getLabel(): string
    {
        return match ($this) {
            self::EndingAudit => '结局审计',
            default => str($this->value)->headline()->toString(),
        };
    }
}
