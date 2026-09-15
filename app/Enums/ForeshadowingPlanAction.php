<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ForeshadowingPlanAction: string implements HasLabel
{
    case Plant = 'plant';
    case Reinforce = 'reinforce';
    case PayOff = 'pay_off';
    case Defer = 'defer';
    case Abandon = 'abandon';

    public function getLabel(): string
    {
        return match ($this) {
            self::Plant => '铺设',
            self::Reinforce => '强化',
            self::PayOff => '兑现',
            self::Defer => '延期',
            self::Abandon => '放弃',
        };
    }

    public function requiresUserAuthorization(): bool
    {
        return in_array($this, [self::Defer, self::Abandon], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $action): array => [$action->value => $action->getLabel()])
            ->all();
    }

    /** @return array<int, string> */
    public static function modelValues(): array
    {
        return [self::Plant->value, self::Reinforce->value, self::PayOff->value];
    }

    /** @return array<int, string> */
    public static function modelValuesForStatus(ForeshadowingStatus $status): array
    {
        return match ($status) {
            ForeshadowingStatus::Idea => [self::Plant->value],
            ForeshadowingStatus::Planted, ForeshadowingStatus::Reinforced => [
                self::Reinforce->value,
                self::PayOff->value,
            ],
            default => [],
        };
    }
}
