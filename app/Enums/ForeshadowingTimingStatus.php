<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ForeshadowingTimingStatus: string implements HasColor, HasLabel
{
    case Upcoming = 'upcoming';
    case Due = 'due';
    case Overdue = 'overdue';

    public static function forTargetChapter(
        ForeshadowingStatus $contentStatus,
        int $dueFromChapter,
        int $dueToChapter,
        int $targetChapter,
    ): ?self {
        if ($contentStatus->isTerminal()) {
            return null;
        }

        if ($targetChapter < $dueFromChapter) {
            return self::Upcoming;
        }

        if ($targetChapter <= $dueToChapter) {
            return self::Due;
        }

        return self::Overdue;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Upcoming => '未到兑现窗口',
            self::Due => '兑现窗口',
            self::Overdue => '已逾期',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Upcoming => 'gray',
            self::Due => 'warning',
            self::Overdue => 'danger',
        };
    }
}
