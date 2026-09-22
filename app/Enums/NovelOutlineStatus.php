<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum NovelOutlineStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Current = 'current';
    case Superseded = 'superseded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => '草稿',
            self::Current => '当前版本',
            self::Superseded => '历史版本',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Current => 'success',
            self::Superseded => 'gray',
        };
    }
}
