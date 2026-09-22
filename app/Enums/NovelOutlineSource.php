<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum NovelOutlineSource: string implements HasColor, HasLabel
{
    case Ai = 'ai';
    case Manual = 'manual';
    case Revision = 'revision';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ai => 'AI 候选',
            self::Manual => '手工创建',
            self::Revision => '修订版本',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ai => 'info',
            self::Manual => 'primary',
            self::Revision => 'warning',
        };
    }
}
