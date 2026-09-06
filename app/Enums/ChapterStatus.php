<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ChapterStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case Generating = 'generating';
    case Review = 'review';
    case Rewrite = 'rewrite';
    case Blocked = 'blocked';
    case Canonical = 'canonical';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => '已规划',
            self::Generating => '生成中',
            self::Review => '待审校',
            self::Rewrite => '重写中',
            self::Blocked => '已阻塞',
            self::Canonical => '正式章节',
            self::Void => '已废弃',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned, self::Void => 'gray',
            self::Generating => 'info',
            self::Review, self::Rewrite => 'warning',
            self::Blocked => 'danger',
            self::Canonical => 'success',
        };
    }
}
