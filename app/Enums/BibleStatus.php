<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BibleStatus: string implements HasColor, HasLabel
{
    case Current = 'current';
    case Superseded = 'superseded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Current => '当前版本',
            self::Superseded => '历史版本',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Current => 'success',
            self::Superseded => 'gray',
        };
    }
}
