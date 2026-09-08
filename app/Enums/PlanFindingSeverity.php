<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PlanFindingSeverity: string implements HasColor, HasLabel
{
    case Valid = 'valid';
    case Warning = 'warning';
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Valid => '有效',
            self::Warning => '警告',
            self::Blocked => '已阻塞',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Valid => 'success',
            self::Warning => 'warning',
            self::Blocked => 'danger',
        };
    }
}
