<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReviewDecision: string implements HasColor, HasLabel
{
    case Pass = 'PASS';
    case Rewrite = 'REWRITE';
    case NeedsAttention = 'NEEDS_ATTENTION';
    case Block = 'BLOCK';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pass => '通过',
            self::Rewrite => '需要重写',
            self::NeedsAttention => '需要人工处理',
            self::Block => '阻塞',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pass => 'success',
            self::Rewrite, self::NeedsAttention => 'warning',
            self::Block => 'danger',
        };
    }
}
