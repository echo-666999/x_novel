<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SceneStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case Generating = 'generating';
    case Draft = 'draft';
    case Accepted = 'accepted';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => '已规划',
            self::Generating => '生成中',
            self::Draft => '草稿',
            self::Accepted => '已接受',
            self::Failed => '生成失败',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned, self::Draft => 'gray',
            self::Generating => 'info',
            self::Accepted => 'success',
            self::Failed => 'danger',
        };
    }
}
