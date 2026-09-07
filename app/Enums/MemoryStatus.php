<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MemoryStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Invalid = 'invalid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => '有效',
            self::Invalid => '已失效',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Invalid => 'gray',
        };
    }
}
