<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WorldEntityType: string implements HasColor, HasLabel
{
    case Location = 'location';
    case Item = 'item';
    case Faction = 'faction';
    case Organization = 'organization';
    case Rule = 'rule';
    case Concept = 'concept';

    public function getLabel(): string
    {
        return match ($this) {
            self::Location => '地点',
            self::Item => '物品',
            self::Faction => '阵营',
            self::Organization => '组织',
            self::Rule => '规则',
            self::Concept => '概念',
        };
    }

    public function getColor(): string
    {
        return 'gray';
    }
}
