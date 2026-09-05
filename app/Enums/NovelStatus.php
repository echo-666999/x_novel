<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum NovelStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Planning = 'planning';
    case Generating = 'generating';
    case Paused = 'paused';
    case Completing = 'completing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => '草稿',
            self::Planning => '规划中',
            self::Generating => '生成中',
            self::Paused => '已暂停',
            self::Completing => '收束中',
            self::Completed => '已完成',
            self::Failed => '失败',
            self::Archived => '已归档',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft, self::Planning, self::Paused, self::Archived => 'gray',
            self::Generating => 'info',
            self::Completing => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
