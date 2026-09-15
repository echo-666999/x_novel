<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ForeshadowingStatus: string implements HasColor, HasLabel
{
    case Idea = 'idea';
    case Planted = 'planted';
    case Reinforced = 'reinforced';
    /** @deprecated Historical compatibility only. Timing is computed separately. */
    case Due = 'due';
    case PaidOff = 'paid_off';
    case Abandoned = 'abandoned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Idea => '构思中',
            self::Planted => '已铺设',
            self::Reinforced => '已强化',
            self::Due => '待迁移（旧 due）',
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

    /** @return array<string, string> */
    public static function contentOptions(): array
    {
        return collect(self::cases())
            ->reject(fn (self $status): bool => $status === self::Due)
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->getLabel()])
            ->all();
    }

    public function isLegacyDue(): bool
    {
        return $this === self::Due;
    }
}
