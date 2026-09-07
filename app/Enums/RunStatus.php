<?php

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
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
            self::Queued, self::Cancelled => 'gray',
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Failed => 'danger',
        };
    }
}
