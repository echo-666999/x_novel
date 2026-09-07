<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StateFindingSeverity: string implements HasColor, HasLabel
{
    case Hard = 'hard';
    case Soft = 'soft';
    case Ambiguous = 'ambiguous';
    case Intentional = 'intentional';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hard => 'BLOCK',
            self::Soft => '警告',
            self::Ambiguous => '需确认',
            self::Intentional => '计划内例外',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Hard => 'danger',
            self::Soft, self::Ambiguous => 'warning',
            self::Intentional => 'info',
        };
    }
}
