<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StoryArcStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case Active = 'active';
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => '规划中',
            self::Active => '推进中',
            self::Completed => '已完成',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Active => 'info',
            self::Completed => 'success',
        };
    }
}
