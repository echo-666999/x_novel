<?php

namespace App\Enums;

/** 内容自愈的稳定分类；每类单独计数、计费和计时，避免修复链彼此借用额度。 */
enum ContentRepairType: string
{
    case PlanValidation = 'plan_validation';
    case ChapterStructure = 'chapter_structure';
    case RewriteExhaustion = 'rewrite_exhaustion';
    case CriticalForeshadowing = 'critical_foreshadowing';

    public function getLabel(): string
    {
        return match ($this) {
            self::PlanValidation => 'Plan 校验失败重规划',
            self::ChapterStructure => '章节结构重建',
            self::RewriteExhaustion => 'Rewrite 耗尽后章节重建',
            self::CriticalForeshadowing => 'Critical 逾期伏笔受限兑现',
        };
    }

    /** 这两类修复在新 Plan 通过确定性校验后已经完成；其余类型继续覆盖后续 Scene 到 Review。 */
    public function completesWithPlan(): bool
    {
        return in_array($this, [self::PlanValidation, self::CriticalForeshadowing], true);
    }
}
