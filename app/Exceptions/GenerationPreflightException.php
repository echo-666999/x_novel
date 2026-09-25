<?php

namespace App\Exceptions;

use RuntimeException;

class GenerationPreflightException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function paused(): self
    {
        return new self('novel_paused', '小说已暂停，无法生成下一章。');
    }

    public static function unavailableStatus(): self
    {
        return new self('novel_status_unavailable', '小说必须处于生成中或收束中。');
    }

    public static function stateUninitialized(): self
    {
        return new self('story_state_uninitialized', 'Story State 尚未初始化。');
    }

    public static function bibleIncomplete(string $detail): self
    {
        return new self(
            'current_bible_incomplete',
            '小说圣经尚未完成迁移：'.$detail.' 请先在“小说圣经”中创建完整的 Current Bible Version。',
        );
    }

    public static function currentVolumeMissing(): self
    {
        return new self('current_volume_missing', '没有进行中的卷，请先将一个卷设为进行中。');
    }

    public static function blockedReview(): self
    {
        return new self('blocked_review', '存在被审校阻塞的章节，请先处理后再继续。');
    }

    public static function activeWorkflowExists(): self
    {
        return new self('active_workflow_exists', '该小说已有另一个活跃章节工作流。');
    }

    public static function previousChapterSummaryMissing(int $sequence): self
    {
        return new self(
            'previous_chapter_summary_missing',
            "第 {$sequence} 章的正式摘要尚未生成，请先在生成恢复中心重试章节摘要。",
        );
    }

    /** @param array<int, string> $foreshadowings */
    public static function criticalForeshadowingOverdue(array $foreshadowings): self
    {
        return new self(
            'critical_foreshadowing_overdue',
            '存在已逾期的 Critical 伏笔：'.implode('、', $foreshadowings)
                .'。自动 Planner 已在调用模型前停止；请为当前章节人工建立包含兑现动作的修复计划，或先完成有原因记录的延期/放弃处理。',
        );
    }
}
