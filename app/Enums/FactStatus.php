<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FactStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Invalidated = 'invalidated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => '有效',
            self::Superseded => '已替代',
            self::Invalidated => '已失效',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Superseded, self::Invalidated => 'gray',
        };
    }
}
