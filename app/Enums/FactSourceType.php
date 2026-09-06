<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FactSourceType: string implements HasColor, HasLabel
{
    case Manual = 'manual';
    case StoryEvent = 'story_event';
    case Bible = 'bible';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => '手工',
            self::StoryEvent => '故事事件',
            self::Bible => '小说圣经',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'primary',
            self::StoryEvent => 'info',
            self::Bible => 'warning',
        };
    }
}
