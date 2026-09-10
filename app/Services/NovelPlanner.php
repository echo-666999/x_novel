<?php

namespace App\Services;

use App\AI\AiSettingsResolver;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class NovelPlanner
{
    public const PROMPT_VERSION = 'novel-planner-v4';

    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiSettingsResolver $settingsResolver,
    ) {}

    public function generate(Novel $novel, int $volumeCount = 5): GenerationArtifact
    {
        $novel->refresh();

        if (! in_array($novel->status, [NovelStatus::Draft, NovelStatus::Planning], true)) {
            throw new AiProviderException('novel_planning_unavailable', '只有草稿或规划中的小说可以生成初始规划。', false);
        }

        $settings = $this->settingsResolver->resolve(AiStage::Planner, $novel);
        $context = [
            'novel' => $novel->only(['id', 'title', 'genre', 'premise', 'target_words']),
            'generation_preferences' => [
                'chapter_target_words' => (int) data_get($novel->settings, 'generation.chapter_target_words', 3_000),
            ],
            'requested_volume_count' => $volumeCount,
        ];
        $inputHash = hash('sha256', json_encode([
            'context' => $context,
            'model' => $settings->model,
            'prompt_version' => self::PROMPT_VERSION,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $reusable = GenerationRun::query()
            ->where('novel_id', $novel->getKey())
            ->whereNull('chapter_id')
            ->where('scope_type', 'novel')
            ->where('stage', GenerationStage::ChapterPlanning)
            ->where('input_hash', $inputHash)
            ->where('status', RunStatus::Succeeded)
            ->latest('id')
            ->first();

        $artifact = $reusable?->artifacts()->where('type', ArtifactType::Context)->first();

        if ($artifact instanceof GenerationArtifact) {
            return $artifact;
        }

        $attempt = ((int) GenerationRun::query()
            ->where('novel_id', $novel->getKey())
            ->whereNull('chapter_id')
            ->where('scope_type', 'novel')
            ->where('stage', GenerationStage::ChapterPlanning)
            ->max('attempt')) + 1;

        $run = GenerationRun::query()->create([
            'novel_id' => $novel->getKey(),
            'scope_type' => 'novel',
            'scope_id' => $novel->getKey(),
            'stage' => GenerationStage::ChapterPlanning,
            'status' => RunStatus::Running,
            'attempt' => $attempt,
            'idempotency_key' => "novel-plan:{$novel->getKey()}:{$inputHash}:{$attempt}",
            'input_hash' => $inputHash,
            'prompt_version' => self::PROMPT_VERSION,
            'model_policy' => $settings->model,
            'context_snapshot' => $context,
            'started_at' => now(),
        ]);

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                systemPrompt: '你是 XNovel 小说规划器。只返回符合 Schema 的 JSON。生成连贯的中文长篇小说蓝图；除固定 JSON 字段、枚举值和本地引用键外，所有自然语言内容必须使用简体中文。bible.style_profile 必须使用 Schema 规定的稳定 code 和完整六项参数。分卷和故事线只能使用请求中规定的本地键相互引用。',
                prompt: '请根据以下小说信息生成初始小说圣经、角色、世界实体、分卷规划、故事线和伏笔：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.5,
                maxTokens: 8_000,
                responseSchema: $this->schema(),
                promptVersion: self::PROMPT_VERSION,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $novel->getKey(),
                    'stage' => AiStage::Planner->value,
                ],
            ));

            if ($response->structuredData === null) {
                throw new AiProviderException('novel_plan_schema_invalid', 'AI 未返回合法的小说规划。', false);
            }

            $blueprint = $this->validate($response->structuredData, $volumeCount);
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::Context,
                'version' => 1,
                'content' => $response->content,
                'data' => $blueprint,
                'checksum' => hash('sha256', json_encode($blueprint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            ]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);
            $novel->update(['status' => NovelStatus::Planning]);

            return $artifact;
        } catch (Throwable $exception) {
            $run->update([
                'status' => RunStatus::Failed,
                'error_code' => $exception instanceof AiProviderException ? $exception->errorCode : 'novel_planning_failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validate(array $data, int $volumeCount): array
    {
        $rules = [
            'bible' => ['required', 'array'],
            'bible.logline' => ['required', 'string'],
            'bible.themes' => ['required', 'array', 'min:1'],
            'bible.themes.*' => ['string'],
            'bible.tone' => ['required', 'string'],
            'bible.pov' => ['required', 'string'],
            'bible.tense' => ['required', 'string'],
            'bible.taboos' => ['array'],
            'bible.hard_constraints' => ['array'],
            'bible.ending_contract' => ['required', 'array'],
            'bible.style_profile' => ['required', 'array:subgenre,target_platform,primary_style,secondary_styles,language_era,pacing,parameters'],
            'bible.style_profile.subgenre' => ['present', 'nullable', 'string', 'max:100'],
            'bible.style_profile.target_platform' => ['required', 'string', Rule::in(array_keys(config('narrative.platforms', [])))],
            'bible.style_profile.primary_style' => ['required', 'string', Rule::in(array_keys(config('narrative.styles', [])))],
            'bible.style_profile.secondary_styles' => ['present', 'array', 'max:2'],
            'bible.style_profile.secondary_styles.*' => ['string', 'distinct:strict', Rule::in(array_keys(config('narrative.styles', [])))],
            'bible.style_profile.language_era' => ['required', 'string', Rule::in(array_keys(config('narrative.language_eras', [])))],
            'bible.style_profile.pacing' => ['required', 'string', Rule::in(array_keys(config('narrative.paces', [])))],
            'bible.style_profile.parameters' => ['required', 'array:'.implode(',', config('narrative.parameter_keys', []))],
            'characters' => ['required', 'array', 'min:1'],
            'characters.*.name' => ['required', 'string'],
            'characters.*.role' => ['required', Rule::in(['主角', '配角', '反派'])],
            'characters.*.motivation' => ['required', 'string'],
            'characters.*.profile' => ['array'],
            'characters.*.personality' => ['array'],
            'characters.*.abilities' => ['array'],
            'characters.*.knowledge' => ['array'],
            'characters.*.current_state' => ['array'],
            'world_entities' => ['required', 'array', 'min:1'],
            'world_entities.*.type' => ['required', Rule::in(['location', 'item', 'faction', 'organization', 'rule', 'concept'])],
            'world_entities.*.name' => ['required', 'string'],
            'world_entities.*.description' => ['required', 'string'],
            'world_entities.*.attributes' => ['array'],
            'world_entities.*.rules' => ['array'],
            'world_entities.*.current_state' => ['array'],
            'volumes' => ['required', 'array', 'size:'.$volumeCount],
            'volumes.*.key' => ['required', 'string', 'distinct'],
            'volumes.*.title' => ['required', 'string'],
            'volumes.*.goal' => ['required', 'string'],
            'volumes.*.climax' => ['required', 'string'],
            'volumes.*.target_words' => ['required', 'integer', 'min:1'],
            'story_arcs' => ['required', 'array', 'min:1'],
            'story_arcs.*.key' => ['required', 'string', 'distinct'],
            'story_arcs.*.volume_key' => ['required', 'string'],
            'story_arcs.*.type' => ['required', Rule::in(['main', 'subplot'])],
            'story_arcs.*.title' => ['required', 'string'],
            'story_arcs.*.goal' => ['required', 'string'],
            'story_arcs.*.stakes' => ['required', 'string'],
            'story_arcs.*.beats' => ['required', 'array', 'min:1'],
            'story_arcs.*.completion_conditions' => ['required', 'array', 'min:1'],
            'foreshadowings' => ['required', 'array'],
            'foreshadowings.*.title' => ['required', 'string'],
            'foreshadowings.*.description' => ['required', 'string'],
            'foreshadowings.*.promised_payoff' => ['required', 'string'],
            'foreshadowings.*.due_from_chapter' => ['required', 'integer', 'min:1'],
            'foreshadowings.*.due_to_chapter' => ['required', 'integer', 'min:1'],
            'foreshadowings.*.importance' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'foreshadowings.*.owner_arc_key' => ['nullable', 'string'],
        ];

        foreach (config('narrative.parameter_keys', []) as $parameterKey) {
            $rules["bible.style_profile.parameters.{$parameterKey}"] = ['required', 'integer', 'between:1,5'];
        }

        $validator = Validator::make($data, $rules, [
            'bible.style_profile.required' => 'AI 小说规划缺少完整文风设置。',
            'bible.style_profile.array' => 'AI 小说规划的文风设置必须是 JSON object。',
            'bible.style_profile.subgenre.present' => 'AI 小说规划的文风设置缺少子题材字段。',
            'bible.style_profile.target_platform.required' => 'AI 小说规划缺少目标平台。',
            'bible.style_profile.target_platform.in' => 'AI 小说规划包含不受支持的目标平台。',
            'bible.style_profile.primary_style.required' => 'AI 小说规划缺少主文风。',
            'bible.style_profile.primary_style.in' => 'AI 小说规划包含不受支持的主文风。',
            'bible.style_profile.secondary_styles.present' => 'AI 小说规划缺少辅助文风字段。',
            'bible.style_profile.secondary_styles.max' => 'AI 小说规划的辅助文风最多允许两项。',
            'bible.style_profile.secondary_styles.*.distinct' => 'AI 小说规划的辅助文风不能重复。',
            'bible.style_profile.secondary_styles.*.in' => 'AI 小说规划包含不受支持的辅助文风。',
            'bible.style_profile.language_era.required' => 'AI 小说规划缺少语言时代感。',
            'bible.style_profile.language_era.in' => 'AI 小说规划包含不受支持的语言时代感。',
            'bible.style_profile.pacing.required' => 'AI 小说规划缺少故事节奏。',
            'bible.style_profile.pacing.in' => 'AI 小说规划包含不受支持的故事节奏。',
            'bible.style_profile.parameters.required' => 'AI 小说规划缺少文风高级设置。',
            'bible.style_profile.parameters.*.required' => 'AI 小说规划必须包含全部六项文风高级设置。',
            'bible.style_profile.parameters.*.integer' => 'AI 小说规划的文风高级设置必须是整数。',
            'bible.style_profile.parameters.*.between' => 'AI 小说规划的文风高级设置必须是 1 至 5 的整数。',
        ]);

        $validator->after(function ($validator) use ($data): void {
            $primaryStyle = data_get($data, 'bible.style_profile.primary_style');
            $secondaryStyles = data_get($data, 'bible.style_profile.secondary_styles');

            if (is_string($primaryStyle) && is_array($secondaryStyles) && in_array($primaryStyle, $secondaryStyles, true)) {
                $validator->errors()->add('bible.style_profile.secondary_styles', 'AI 小说规划的辅助文风不能与主文风重复。');
            }
        });

        $valid = $validator->validate();

        $volumeKeys = collect($valid['volumes'])->pluck('key');
        $arcKeys = collect($valid['story_arcs'])->pluck('key');

        if (! collect($valid['characters'])->contains(fn (array $character): bool => $character['role'] === '主角')) {
            throw new AiProviderException('novel_plan_protagonist_missing', '小说规划必须包含至少一名主角。', false);
        }

        if (collect($valid['story_arcs'])->contains(fn (array $arc): bool => ! $volumeKeys->contains($arc['volume_key']))) {
            throw new AiProviderException('novel_plan_reference_invalid', '小说规划包含无效的分卷引用。', false);
        }

        if (collect($valid['foreshadowings'])->contains(fn (array $item): bool => filled($item['owner_arc_key'] ?? null) && ! $arcKeys->contains($item['owner_arc_key']))) {
            throw new AiProviderException('novel_plan_reference_invalid', '小说规划包含无效的故事线引用。', false);
        }

        if (collect($valid['foreshadowings'])->contains(fn (array $item): bool => $item['due_to_chapter'] < $item['due_from_chapter'])) {
            throw new AiProviderException('novel_plan_due_window_invalid', '小说规划包含无效的伏笔回收区间。', false);
        }

        return $valid;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string']];
        $currentState = ['type' => 'object', 'additionalProperties' => false, 'required' => ['location', 'summary'], 'properties' => [
            'location' => ['type' => ['string', 'null']],
            'summary' => ['type' => 'string'],
        ]];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['bible', 'characters', 'world_entities', 'volumes', 'story_arcs', 'foreshadowings'],
            'properties' => [
                'bible' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['logline', 'themes', 'tone', 'pov', 'tense', 'taboos', 'hard_constraints', 'ending_contract', 'style_profile'], 'properties' => [
                    'logline' => ['type' => 'string'], 'themes' => $stringArray, 'tone' => ['type' => 'string'], 'pov' => ['type' => 'string'], 'tense' => ['type' => 'string'], 'taboos' => $stringArray, 'hard_constraints' => $stringArray,
                    'ending_contract' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['final_protagonist_state', 'main_conflict_resolution', 'theme_payoff', 'required_foreshadowing_payoff', 'character_arc_requirements', 'allowed_open_endings'], 'properties' => [
                        'final_protagonist_state' => ['type' => 'string'], 'main_conflict_resolution' => ['type' => 'string'], 'theme_payoff' => ['type' => 'string'], 'required_foreshadowing_payoff' => $stringArray, 'character_arc_requirements' => $stringArray, 'allowed_open_endings' => $stringArray,
                    ]],
                    'style_profile' => $this->styleProfileSchema(),
                ]],
                'characters' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'role', 'motivation', 'profile', 'personality', 'abilities', 'knowledge', 'current_state'], 'properties' => [
                    'name' => ['type' => 'string'], 'role' => ['type' => 'string', 'enum' => ['主角', '配角', '反派']], 'motivation' => ['type' => 'string'], 'profile' => $stringArray, 'personality' => $stringArray, 'abilities' => $stringArray, 'knowledge' => $stringArray, 'current_state' => $currentState,
                ]]],
                'world_entities' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['type', 'name', 'description', 'attributes', 'rules', 'current_state'], 'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['location', 'item', 'faction', 'organization', 'rule', 'concept']], 'name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'attributes' => $stringArray, 'rules' => $stringArray, 'current_state' => $stringArray,
                ]]],
                'volumes' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'title', 'goal', 'climax', 'target_words'], 'properties' => [
                    'key' => ['type' => 'string'], 'title' => ['type' => 'string'], 'goal' => ['type' => 'string'], 'climax' => ['type' => 'string'], 'target_words' => ['type' => 'integer'],
                ]]],
                'story_arcs' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'volume_key', 'type', 'title', 'goal', 'stakes', 'beats', 'completion_conditions'], 'properties' => [
                    'key' => ['type' => 'string'], 'volume_key' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['main', 'subplot']], 'title' => ['type' => 'string'], 'goal' => ['type' => 'string'], 'stakes' => ['type' => 'string'], 'beats' => $stringArray, 'completion_conditions' => $stringArray,
                ]]],
                'foreshadowings' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'description', 'promised_payoff', 'due_from_chapter', 'due_to_chapter', 'importance', 'owner_arc_key'], 'properties' => [
                    'title' => ['type' => 'string'], 'description' => ['type' => 'string'], 'promised_payoff' => ['type' => 'string'], 'due_from_chapter' => ['type' => 'integer'], 'due_to_chapter' => ['type' => 'integer'], 'importance' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']], 'owner_arc_key' => ['type' => ['string', 'null']],
                ]]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function styleProfileSchema(): array
    {
        $parameterProperties = collect(config('narrative.parameter_keys', []))
            ->mapWithKeys(fn (string $key): array => [$key => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 5,
            ]])
            ->all();

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['subgenre', 'target_platform', 'primary_style', 'secondary_styles', 'language_era', 'pacing', 'parameters'],
            'properties' => [
                'subgenre' => ['type' => ['string', 'null']],
                'target_platform' => ['type' => 'string', 'enum' => array_keys(config('narrative.platforms', []))],
                'primary_style' => ['type' => 'string', 'enum' => array_keys(config('narrative.styles', []))],
                'secondary_styles' => [
                    'type' => 'array',
                    'maxItems' => 2,
                    'uniqueItems' => true,
                    'items' => ['type' => 'string', 'enum' => array_keys(config('narrative.styles', []))],
                ],
                'language_era' => ['type' => 'string', 'enum' => array_keys(config('narrative.language_eras', []))],
                'pacing' => ['type' => 'string', 'enum' => array_keys(config('narrative.paces', []))],
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => config('narrative.parameter_keys', []),
                    'properties' => $parameterProperties,
                ],
            ],
        ];
    }
}
