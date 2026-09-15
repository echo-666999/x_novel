<?php

namespace App\Services;

use App\Enums\ForeshadowingPlanAction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ChapterPlanPayload
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'chapter_function', 'arc_contribution', 'reader_promise', 'target_words',
                'pov_character_id', 'tone', 'time_anchor', 'hook_type', 'must_reveal',
                'may_hint', 'must_not_reveal', 'required_facts', 'forbidden_conflicts',
                'foreshadowing_actions', 'scene_plans',
            ],
            'properties' => [
                'chapter_function' => ['type' => 'string'],
                'arc_contribution' => ['type' => 'string'],
                'reader_promise' => ['type' => 'string'],
                'target_words' => ['type' => 'integer', 'minimum' => 1],
                'pov_character_id' => ['type' => 'integer', 'minimum' => 1],
                'tone' => ['type' => 'string'],
                'time_anchor' => ['type' => 'string'],
                'hook_type' => ['type' => 'string'],
                'must_reveal' => $strings,
                'may_hint' => $strings,
                'must_not_reveal' => $strings,
                'required_facts' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'forbidden_conflicts' => $strings,
                'foreshadowing_actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'foreshadowing_id',
                            'action',
                            'target_scene_sequence',
                            'acceptance_criteria',
                            'reason',
                        ],
                        'properties' => [
                            'foreshadowing_id' => ['type' => 'integer', 'minimum' => 1],
                            'action' => ['type' => 'string', 'enum' => ForeshadowingPlanAction::modelValues()],
                            'target_scene_sequence' => ['type' => 'integer', 'minimum' => 1],
                            'acceptance_criteria' => ['type' => 'string'],
                            'reason' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
                'scene_plans' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'goal',
                            'conflict',
                            'turn',
                            'outcome',
                            'outcome_allowed',
                            'outcome_forbidden',
                            'pov_character_id',
                            'location',
                            'time_anchor',
                            'transition_from_previous',
                        ],
                        'properties' => [
                            'goal' => ['type' => 'string'],
                            'conflict' => ['type' => 'string'],
                            'turn' => ['type' => 'string'],
                            'outcome' => ['type' => 'string'],
                            'outcome_allowed' => $strings,
                            'outcome_forbidden' => $strings,
                            'pov_character_id' => ['type' => ['integer', 'null']],
                            'location' => ['type' => ['string', 'null']],
                            'time_anchor' => ['type' => ['string', 'null']],
                            'transition_from_previous' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function validate(array $payload): array
    {
        $allowed = array_keys(self::schema()['properties']);

        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw ValidationException::withMessages(['plan' => 'Chapter Plan 包含未声明字段。']);
        }

        $allowedSceneFields = array_keys(self::schema()['properties']['scene_plans']['items']['properties']);

        foreach ($payload['scene_plans'] ?? [] as $scenePlan) {
            if (! is_array($scenePlan) || array_diff(array_keys($scenePlan), $allowedSceneFields) !== []) {
                throw ValidationException::withMessages(['scene_plans' => 'Scene Plan 包含未声明字段。']);
            }
        }

        $allowedForeshadowingActionFields = array_keys(self::schema()['properties']['foreshadowing_actions']['items']['properties']);

        foreach ($payload['foreshadowing_actions'] ?? [] as $action) {
            if (! is_array($action) || array_diff(array_keys($action), $allowedForeshadowingActionFields) !== []) {
                throw ValidationException::withMessages(['foreshadowing_actions' => '伏笔动作包含未声明字段。']);
            }
        }

        return Validator::make($payload, [
            'chapter_function' => ['required', 'string'],
            'arc_contribution' => ['required', 'string'],
            'reader_promise' => ['required', 'string'],
            'target_words' => ['required', 'integer', 'min:1'],
            'pov_character_id' => ['required', 'integer', 'min:1'],
            'tone' => ['required', 'string'],
            'time_anchor' => ['required', 'string'],
            'hook_type' => ['required', 'string'],
            'must_reveal' => ['present', 'array'], 'must_reveal.*' => ['string'],
            'may_hint' => ['present', 'array'], 'may_hint.*' => ['string'],
            'must_not_reveal' => ['present', 'array'], 'must_not_reveal.*' => ['string'],
            'required_facts' => ['present', 'array'], 'required_facts.*' => ['integer'],
            'forbidden_conflicts' => ['present', 'array'], 'forbidden_conflicts.*' => ['string'],
            'foreshadowing_actions' => ['present', 'array'],
            'foreshadowing_actions.*.foreshadowing_id' => ['required', 'integer', 'min:1'],
            'foreshadowing_actions.*.action' => ['required', 'string', 'in:'.implode(',', ForeshadowingPlanAction::modelValues())],
            'foreshadowing_actions.*.target_scene_sequence' => ['required', 'integer', 'min:1'],
            'foreshadowing_actions.*.acceptance_criteria' => ['required', 'string'],
            'foreshadowing_actions.*.reason' => ['present', 'nullable', 'string'],
            'scene_plans' => ['required', 'array', 'min:1'],
            'scene_plans.*.goal' => ['required', 'string'],
            'scene_plans.*.conflict' => ['required', 'string'],
            'scene_plans.*.turn' => ['required', 'string'],
            'scene_plans.*.outcome' => ['required', 'string'],
            'scene_plans.*.outcome_allowed' => ['present', 'array'],
            'scene_plans.*.outcome_allowed.*' => ['string'],
            'scene_plans.*.outcome_forbidden' => ['present', 'array'],
            'scene_plans.*.outcome_forbidden.*' => ['string'],
            'scene_plans.*.pov_character_id' => ['nullable', 'integer'],
            'scene_plans.*.location' => ['nullable', 'string'],
            'scene_plans.*.time_anchor' => ['nullable', 'string'],
            'scene_plans.*.transition_from_previous' => ['nullable', 'string'],
        ])->validate();
    }
}
