<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PlanStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Superseded = 'superseded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => '草稿',
            self::Ready => '可执行',
            self::Superseded => '已替代',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft, self::Superseded => 'gray',
            self::Ready => 'success',
        };
    }
}
