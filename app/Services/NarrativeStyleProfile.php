<?php

namespace App\Services;

use App\Models\Novel;

class NarrativeStyleProfile
{
    /** @return array<string, mixed> */
    public function forNovel(Novel $novel): array
    {
        $editorial = data_get($novel->settings, 'editorial', []);
        $styles = config('narrative.styles', []);
        $primaryCode = (string) data_get($editorial, 'primary_style', 'accessible_brisk');
        $primary = $styles[$primaryCode] ?? $styles['accessible_brisk'];
        $secondaryCodes = collect(data_get($editorial, 'secondary_styles', []))
            ->filter(fn (mixed $code): bool => is_string($code) && $code !== $primaryCode && isset($styles[$code]))
            ->values();
        $parameterKeys = config('narrative.parameter_keys', []);
        $presetParameters = array_combine($parameterKeys, $primary['parameters']);
        $overrides = collect(data_get($editorial, 'style_parameters', []))
            ->only($parameterKeys)
            ->filter(fn (mixed $value): bool => is_numeric($value) && $value >= 1 && $value <= 5)
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        return [
            'subgenre' => data_get($editorial, 'subgenre'),
            'target_platform' => $this->label('platforms', data_get($editorial, 'target_platform', 'general')),
            'story_tone' => $this->label('tones', data_get($editorial, 'story_tone', 'serious')),
            'primary_style' => $primary['name'],
            'secondary_styles' => $secondaryCodes->map(fn (string $code): string => $styles[$code]['name'])->all(),
            'language_era' => $this->label('language_eras', data_get($editorial, 'language_era', 'modern_spoken')),
            'pacing' => $this->label('paces', data_get($editorial, 'pacing', 'balanced')),
            'narrative_pov' => $this->label('povs', data_get($editorial, 'narrative_pov', 'third_limited')),
            'parameters' => array_replace($presetParameters, $overrides),
            'instructions' => $this->instructions($novel, $primary, $secondaryCodes->all(), $styles),
        ];
    }

    /** @param array<string, mixed> $primary @param array<int, string> $secondaryCodes @param array<string, mixed> $styles */
    private function instructions(Novel $novel, array $primary, array $secondaryCodes, array $styles): array
    {
        $instructions = [$primary['instruction']];
        foreach ($secondaryCodes as $code) {
            $instructions[] = '辅助文风（'.$styles[$code]['name'].'）：'.$styles[$code]['instruction'];
        }

        $legacy = trim((string) data_get($novel->settings, 'generation.narrative_style', ''));
        if ($legacy !== '' && data_get($novel->settings, 'editorial') === null) {
            $instructions[] = '已有自定义要求：'.$legacy;
        }

        return $instructions;
    }

    private function label(string $group, mixed $code): string
    {
        return (string) data_get(config("narrative.{$group}", []), (string) $code, $code);
    }
}
