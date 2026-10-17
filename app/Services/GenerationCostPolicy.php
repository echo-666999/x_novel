<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** 金额值与主动启用分离；仅预警、缺金额和旧值不能隐式开启停点。 */
final class GenerationCostPolicy
{
    public const LIMITS = ['daily_hard_limit', 'novel_total_limit', 'chapter_max_cost', 'issue_max_cost'];

    public function normalize(array $input): array
    {
        $rules = ['warning_amount' => ['nullable', 'numeric', 'min:0']];
        foreach (self::LIMITS as $key) {
            $rules[$key] = ['nullable', 'numeric', 'min:0'];
            $rules[$key.'_enabled'] = ['sometimes', 'boolean'];
        }
        Validator::make($input, $rules)->validate();
        $out = ['warning_amount' => isset($input['warning_amount']) ? (string) $input['warning_amount'] : null];
        foreach (self::LIMITS as $key) {
            $enabled = (bool) ($input[$key.'_enabled'] ?? false);
            $amount = isset($input[$key]) && $input[$key] !== '' ? (string) $input[$key] : null;
            if ($enabled && $amount === null) {
                throw ValidationException::withMessages([$key => '主动启用硬上限后必须填写金额。']);
            }
            $out[$key] = $amount;
            $out[$key.'_enabled'] = $enabled;
        }

        return $out;
    }

    /** 小说策略与创建/编辑共用，修改留下操作者和币种，不改变已发请求。 */
    public function apply(array $settings, array $input, ?int $actorId): array
    {
        $normalized = $this->normalize($input);
        if (! isset($settings['budget']) || app(GenerationInputIdentity::class)->hash($this->normalize($settings['budget'])) !== app(GenerationInputIdentity::class)->hash($normalized)) {
            $settings['budget_history'][] = ['before' => $settings['budget'] ?? null, 'after' => $normalized,
                'actor_id' => $actorId, 'at' => now()->toIso8601String(),
                'currency' => app(GenerationSettingsService::class)->currency()];
        }
        $settings['budget'] = $normalized;

        return $settings;
    }
}
