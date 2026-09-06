<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CharacterStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Deceased = 'deceased';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => '活跃',
            self::Inactive => '暂离',
            self::Deceased => '已死亡',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'info',
            self::Inactive => 'gray',
            self::Deceased => 'danger',
        };
    }
}
