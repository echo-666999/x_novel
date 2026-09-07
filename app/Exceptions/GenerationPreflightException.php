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
}
