<?php

namespace App\Enums;

/** 单次执行状态；Deferred 仅用于已保存断点的未完成工作。 */
enum RunStatus: string
{
    case Deferred = 'deferred';
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Deferred => '等待断点续跑',
            self::Queued => '排队中',
            self::Running => '运行中',
            self::Succeeded => '已成功',
            self::Failed => '失败',
            self::Cancelled => '已取消',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued, self::Cancelled, self::Deferred => 'gray',
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Failed => 'danger',
        };
    }
}
