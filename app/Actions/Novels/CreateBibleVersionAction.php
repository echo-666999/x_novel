<?php

namespace App\Actions\Novels;

use App\Enums\BibleStatus;
use App\Models\Novel;
use App\Models\NovelBible;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateBibleVersionAction
{
    private const CONTENT_ATTRIBUTES = [
        'logline',
        'themes',
        'tone',
        'pov',
        'tense',
        'taboos',
        'hard_constraints',
        'ending_contract',
        'style_profile',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Novel $novel, array $attributes): NovelBible
    {
        $attributes['ending_contract'] = $this->normalizeEndingContract($attributes['ending_contract'] ?? []);

        $attributes['style_profile'] = $this->validateStyleProfile($attributes['style_profile'] ?? null);

        return DB::transaction(function () use ($novel, $attributes): NovelBible {
            /** @var Novel $lockedNovel */
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            $currentBible = $lockedNovel->bibles()->first();
            $nextVersion = ($currentBible?->version ?? 0) + 1;

            if ($currentBible !== null) {
                $currentBible->update(['status' => BibleStatus::Superseded]);
            }

            return $lockedNovel->bibles()->create([
                ...Arr::only($attributes, self::CONTENT_ATTRIBUTES),
                'version' => $nextVersion,
                'status' => BibleStatus::Current,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private function normalizeEndingContract(array $contract): array
    {
        foreach (['required_foreshadowing_payoff', 'character_arc_requirements', 'allowed_open_endings'] as $key) {
            $value = $contract[$key] ?? [];
            $contract[$key] = collect(is_array($value) ? $value : [$value])
                ->filter(fn (mixed $item): bool => is_string($item) && filled($item))
                ->values()
                ->all();
        }

        return $contract;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStyleProfile(mixed $profile): array
    {
        $styleCodes = array_keys(config('narrative.styles', []));
        $platformCodes = array_keys(config('narrative.platforms', []));
        $languageEraCodes = array_keys(config('narrative.language_eras', []));
        $paceCodes = array_keys(config('narrative.paces', []));
        $parameterKeys = config('narrative.parameter_keys', []);

        $rules = [
            'style_profile' => ['required', 'array:subgenre,target_platform,primary_style,secondary_styles,language_era,pacing,parameters'],
            'style_profile.subgenre' => ['present', 'nullable', 'string', 'max:100'],
            'style_profile.target_platform' => ['required', 'string', Rule::in($platformCodes)],
            'style_profile.primary_style' => ['required', 'string', Rule::in($styleCodes)],
            'style_profile.secondary_styles' => ['present', 'array', 'max:2'],
            'style_profile.secondary_styles.*' => ['string', 'distinct:strict', Rule::in($styleCodes)],
            'style_profile.language_era' => ['required', 'string', Rule::in($languageEraCodes)],
            'style_profile.pacing' => ['required', 'string', Rule::in($paceCodes)],
            'style_profile.parameters' => ['required', 'array:'.implode(',', $parameterKeys)],
        ];

        foreach ($parameterKeys as $parameterKey) {
            $rules["style_profile.parameters.{$parameterKey}"] = ['required', 'integer', 'between:1,5'];
        }

        $validator = Validator::make(['style_profile' => $profile], $rules, [
            'style_profile.required' => '必须提供完整的文风设置。',
            'style_profile.array' => '文风设置必须是 JSON object。',
            'style_profile.subgenre.present' => '文风设置必须包含子题材字段。',
            'style_profile.subgenre.max' => '子题材不能超过 100 个字符。',
            'style_profile.target_platform.required' => '请选择目标平台。',
            'style_profile.target_platform.in' => '目标平台不是受支持的选项。',
            'style_profile.primary_style.required' => '请选择主文风。',
            'style_profile.primary_style.in' => '主文风不是受支持的选项。',
            'style_profile.secondary_styles.present' => '文风设置必须包含辅助文风字段。',
            'style_profile.secondary_styles.array' => '辅助文风格式无效。',
            'style_profile.secondary_styles.max' => '辅助文风最多选择两项。',
            'style_profile.secondary_styles.*.distinct' => '辅助文风不能重复。',
            'style_profile.secondary_styles.*.in' => '辅助文风包含不受支持的选项。',
            'style_profile.language_era.required' => '请选择语言时代感。',
            'style_profile.language_era.in' => '语言时代感不是受支持的选项。',
            'style_profile.pacing.required' => '请选择故事节奏。',
            'style_profile.pacing.in' => '故事节奏不是受支持的选项。',
            'style_profile.parameters.required' => '必须提供完整的文风高级设置。',
            'style_profile.parameters.array' => '文风高级设置格式无效。',
            'style_profile.parameters.*.required' => '六项文风高级设置都必须填写。',
            'style_profile.parameters.*.integer' => '文风高级设置必须是整数。',
            'style_profile.parameters.*.between' => '文风高级设置必须是 1 至 5 的整数。',
        ]);
        $validator->after(function ($validator) use ($profile): void {
            if (is_array($profile) && array_is_list($profile)) {
                $validator->errors()->add('style_profile', '文风设置必须是 JSON object。');

                return;
            }

            if (! is_array($profile)) {
                return;
            }

            $primaryStyle = $profile['primary_style'] ?? null;
            $secondaryStyles = $profile['secondary_styles'] ?? null;

            if (is_string($primaryStyle) && is_array($secondaryStyles) && in_array($primaryStyle, $secondaryStyles, true)) {
                $validator->errors()->add('style_profile.secondary_styles', '辅助文风不能与主文风重复。');
            }
        });

        /** @var array{style_profile: array<string, mixed>} $validated */
        $validated = $validator->validate();

        return $validated['style_profile'];
    }
}
