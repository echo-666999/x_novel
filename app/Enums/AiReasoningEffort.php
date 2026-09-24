<?php

namespace App\Enums;

enum AiReasoningEffort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function getLabel(): string
    {
        return match ($this) {
            self::Low => '低',
            self::Medium => '中',
            self::High => '高',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $effort): array => [$effort->value => $effort->getLabel()])
            ->all();
    }
}
