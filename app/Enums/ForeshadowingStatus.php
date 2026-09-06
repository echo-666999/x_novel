<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ForeshadowingStatus: string implements HasColor, HasLabel
{
    case Idea = 'idea';
    case Planted = 'planted';
    case Reinforced = 'reinforced';
    case Due = 'due';
    case PaidOff = 'paid_off';
    case Abandoned = 'abandoned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Idea => '构思中',
            self::Planted => '已铺设',
            self::Reinforced => '已强化',
            self::Due => '待兑现',
            self::PaidOff => '已兑现',
            self::Abandoned => '已放弃',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Idea, self::Abandoned => 'gray',
            self::Planted, self::Reinforced => 'info',
            self::Due => 'warning',
            self::PaidOff => 'success',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::PaidOff, self::Abandoned], true);
    }
}
