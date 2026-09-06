<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FactHardness: string implements HasColor, HasLabel
{
    case Hard = 'hard';
    case Soft = 'soft';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hard => '硬事实',
            self::Soft => '软事实',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Hard => 'danger',
            self::Soft => 'gray',
        };
    }
}
