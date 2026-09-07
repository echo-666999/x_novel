<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StoryEventStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Invalidated = 'invalidated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => '有效',
            self::Invalidated => '已失效',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Invalidated => 'gray',
        };
    }
}
