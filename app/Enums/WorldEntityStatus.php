<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WorldEntityStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => '有效',
            self::Inactive => '停用',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'info',
            self::Inactive => 'gray',
        };
    }
}
