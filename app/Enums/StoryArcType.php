<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StoryArcType: string implements HasColor, HasLabel
{
    case Main = 'main';
    case Subplot = 'subplot';

    public function getLabel(): string
    {
        return match ($this) {
            self::Main => '主线',
            self::Subplot => '支线',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Main => 'primary',
            self::Subplot => 'gray',
        };
    }
}
