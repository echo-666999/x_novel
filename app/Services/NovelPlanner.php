<?php

namespace App\Services;

use App\Actions\Novels\CreateNovelOutlineVersionAction;
use App\AI\AiSettingsResolver;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\StructuredOutput;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelOutline;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class NovelPlanner
{
    public const PROMPT_VERSION = 'novel-planner-v5';

    public const REGENERATION_PROMPT_VERSION = 'novel-outline-node-v1';

    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiSettingsResolver $settingsResolver,
        private readonly NovelOutlineValidator $outlineValidator,
        private readonly NovelOutlineChecksum $outlineChecksum,
        private readonly CreateNovelOutlineVersionAction $createOutlineVersion,
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
            'provider' => $settings->provider,
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
            $this->ensureDraftOutline($novel, $artifact);

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
            'provider' => $settings->provider,
            'model_policy' => $settings->model,
            'context_snapshot' => $context,
            'started_at' => now(),
        ]);

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                provider: $settings->provider,
                systemPrompt: '你是 XNovel 小说规划器。只返回符合 Schema 的 JSON。生成连贯的中文长篇小说蓝图；除固定 JSON 字段、枚举值和稳定 key 外，所有自然语言内容必须使用简体中文。bible.style_profile 必须使用 Schema 规定的稳定 code 和完整六项参数。Outline 必须按 Volume → Arc → Beat 嵌套，所有节点使用全局唯一稳定 key 和从 1 连续的 sequence。每个 Main Arc 至少一个结构化 Beat；Beat 必须给出章节预算、验收条件和必须/禁止内容。未来才登场的人物或世界实体只放入对应 Beat Candidate，不能混入初始人物或世界资料。',
                prompt: '请根据以下小说信息生成初始小说圣经、初始角色、初始世界实体、全书 Outline 和伏笔候选：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
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

            $blueprint = $this->validate(
                StructuredOutput::require($response, 'novel_plan', '小说规划'),
                $volumeCount,
            );
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::Context,
                'version' => 1,
                'content' => $response->content,
                'data' => $blueprint,
                'checksum' => hash('sha256', json_encode($blueprint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            ]);
            $this->ensureDraftOutline($novel, $artifact);
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

    public function regenerateNode(
        Novel $novel,
        NovelOutline $outline,
        GenerationArtifact $sourceArtifact,
        string $nodeKey,
        string $instruction,
    ): NovelOutline {
        $novel->refresh();
        $outline->refresh();
        $nodeKey = trim($nodeKey);
        $instruction = trim($instruction);

        if ($outline->novel_id !== $novel->getKey() || $outline->status !== NovelOutlineStatus::Draft) {
            throw new AiProviderException('outline_regeneration_unavailable', '局部重新生成只接受当前小说的 Draft Outline。', false);
        }
        if ($instruction === '' || ! in_array($nodeKey, $this->nodeKeys($outline->content), true)) {
            throw new AiProviderException('outline_regeneration_target_invalid', '必须选择有效节点并填写局部修改要求。', false);
        }
        if ($sourceArtifact->generationRun()->where('novel_id', $novel->getKey())->where('scope_type', 'novel')->doesntExist()
            || ! is_array(data_get($sourceArtifact->data, 'bible'))) {
            throw new AiProviderException('outline_regeneration_source_invalid', '局部重新生成缺少可追踪的初始 Blueprint Artifact。', false);
        }

        $settings = $this->settingsResolver->resolve(AiStage::Planner, $novel);
        $context = [
            'novel_id' => $novel->getKey(),
            'source_artifact_id' => $sourceArtifact->getKey(),
            'base_outline_id' => $outline->getKey(),
            'base_outline_checksum' => $outline->checksum,
            'target_node_key' => $nodeKey,
            'instruction' => $instruction,
            'outline' => $outline->content,
        ];
        $inputHash = hash('sha256', json_encode([
            'context' => $context,
            'provider' => $settings->provider,
            'model' => $settings->model,
            'prompt_version' => self::REGENERATION_PROMPT_VERSION,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $attempt = ((int) $novel->generationRuns()
            ->where('stage', GenerationStage::ChapterPlanning)
            ->max('attempt')) + 1;
        $run = $novel->generationRuns()->create([
            'scope_type' => 'novel',
            'scope_id' => $novel->getKey(),
            'stage' => GenerationStage::ChapterPlanning,
            'status' => RunStatus::Running,
            'attempt' => $attempt,
            'idempotency_key' => "outline-regenerate:{$novel->getKey()}:{$inputHash}:{$attempt}",
            'input_hash' => $inputHash,
            'prompt_version' => self::REGENERATION_PROMPT_VERSION,
            'provider' => $settings->provider,
            'model_policy' => $settings->model,
            'context_snapshot' => collect($context)->except('outline')->all(),
            'started_at' => now(),
        ]);

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                provider: $settings->provider,
                systemPrompt: '你是 XNovel 大纲局部修订器。只返回符合 Schema 的完整 Outline JSON。仅允许修改 target_node_key 对应节点及其后代；节点外的字段、顺序、key 和语义必须保持不变。不得把 Candidate 写入正式人物或世界资料。',
                prompt: json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.4,
                maxTokens: 8_000,
                responseSchema: $this->outlineSchema(),
                promptVersion: self::REGENERATION_PROMPT_VERSION,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $novel->getKey(),
                    'target_node_key' => $nodeKey,
                    'stage' => AiStage::Planner->value,
                ],
            ));
            $revised = StructuredOutput::require($response, 'outline_node_regeneration', '大纲局部修订');
            $this->outlineValidator->assertValid($revised);
            $this->assertOnlyTargetChanged($outline->content, $revised, $nodeKey);

            $artifactData = $sourceArtifact->data;
            $artifactData['outline'] = $revised;
            $artifactData['regeneration'] = [
                'source_artifact_id' => $sourceArtifact->getKey(),
                'base_outline_id' => $outline->getKey(),
                'target_node_key' => $nodeKey,
                'instruction' => $instruction,
            ];
            $run->artifacts()->create([
                'type' => ArtifactType::Context,
                'version' => 1,
                'content' => $response->content,
                'data' => $artifactData,
                'checksum' => hash('sha256', json_encode($artifactData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            ]);
            $created = $this->createOutlineVersion->handle(
                novel: $novel,
                content: $revised,
                source: NovelOutlineSource::Revision,
                basedOn: $outline,
            );
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $created;
        } catch (Throwable $exception) {
            $run->update([
                'status' => RunStatus::Failed,
                'error_code' => $exception instanceof AiProviderException ? $exception->errorCode : 'outline_regeneration_failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $content @return array<int, string> */
    public function nodeKeys(array $content): array
    {
        return collect($content['volumes'] ?? [])->flatMap(function (array $volume): array {
            return [
                $volume['key'],
                ...collect($volume['arcs'] ?? [])->flatMap(fn (array $arc): array => [
                    $arc['key'],
                    ...collect($arc['beats'] ?? [])->pluck('key')->all(),
                ])->all(),
            ];
        })->filter(fn (mixed $key): bool => is_string($key) && $key !== '')->values()->all();
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function assertOnlyTargetChanged(array $before, array $after, string $targetKey): void
    {
        $allowed = array_unique([
            $targetKey,
            ...$this->descendantKeys($before, $targetKey),
            ...$this->descendantKeys($after, $targetKey),
        ]);
        $diff = app(OutlineDiffService::class)->compare($before, $after);
        $outside = collect($diff)->flatten(1)
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key) && ! in_array($key, $allowed, true))
            ->unique();

        if ($outside->isNotEmpty()) {
            throw new AiProviderException(
                'outline_regeneration_scope_violation',
                '局部重新生成修改了目标节点之外的内容：'.$outside->implode('、'),
                false,
            );
        }
    }

    /** @param array<string, mixed> $content @return array<int, string> */
    private function descendantKeys(array $content, string $targetKey): array
    {
        foreach ($content['volumes'] ?? [] as $volume) {
            if (($volume['key'] ?? null) === $targetKey) {
                return collect($volume['arcs'] ?? [])->flatMap(fn (array $arc): array => [
                    $arc['key'],
                    ...collect($arc['beats'] ?? [])->pluck('key')->all(),
                ])->all();
            }
            foreach ($volume['arcs'] ?? [] as $arc) {
                if (($arc['key'] ?? null) === $targetKey) {
                    return collect($arc['beats'] ?? [])->pluck('key')->all();
                }
            }
        }

        return [];
    }

    private function ensureDraftOutline(Novel $novel, GenerationArtifact $artifact): void
    {
        $content = data_get($artifact->data, 'outline');
        if (! is_array($content)) {
            throw new AiProviderException('novel_outline_missing', 'AI 小说规划缺少结构化 Outline。', false);
        }

        $checksum = $this->outlineChecksum->for($content);
        $existing = $novel->outlines()->where('checksum', $checksum)->latest('version')->first();
        if ($existing?->status === NovelOutlineStatus::Draft || $existing?->status === NovelOutlineStatus::Current) {
            return;
        }

        $this->createOutlineVersion->handle(
            novel: $novel,
            content: $content,
            source: $existing === null ? NovelOutlineSource::Ai : NovelOutlineSource::Revision,
            basedOn: $existing,
        );
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
            'outline' => ['required', 'array'],
            'outline.volumes' => ['required', 'array', 'size:'.$volumeCount],
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
        $valid['outline'] = $data['outline'];

        $this->outlineValidator->assertValid($valid['outline']);

        $arcKeys = collect($valid['outline']['volumes'])
            ->flatMap(fn (array $volume): array => $volume['arcs'] ?? [])
            ->pluck('key');

        if (! collect($valid['characters'])->contains(fn (array $character): bool => $character['role'] === '主角')) {
            throw new AiProviderException('novel_plan_protagonist_missing', '小说规划必须包含至少一名主角。', false);
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
            'required' => ['bible', 'characters', 'world_entities', 'outline', 'foreshadowings'],
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
                'outline' => $this->outlineSchema(),
                'foreshadowings' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'description', 'promised_payoff', 'due_from_chapter', 'due_to_chapter', 'importance', 'owner_arc_key'], 'properties' => [
                    'title' => ['type' => 'string'], 'description' => ['type' => 'string'], 'promised_payoff' => ['type' => 'string'], 'due_from_chapter' => ['type' => 'integer'], 'due_to_chapter' => ['type' => 'integer'], 'importance' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']], 'owner_arc_key' => ['type' => ['string', 'null']],
                ]]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function outlineSchema(): array
    {
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string']];
        $integerArray = ['type' => 'array', 'items' => ['type' => 'integer']];
        $characterCandidate = ['type' => 'object', 'additionalProperties' => false, 'required' => ['candidate_key', 'name', 'role', 'motivation', 'profile', 'personality', 'abilities', 'knowledge', 'deduplication_basis', 'possible_duplicate_character_ids', 'introduction_reason', 'target_scene_sequence'], 'properties' => [
            'candidate_key' => ['type' => 'string'], 'name' => ['type' => 'string'], 'role' => ['type' => 'string'], 'motivation' => ['type' => 'string'], 'profile' => ['type' => 'object'], 'personality' => ['type' => 'object'], 'abilities' => ['type' => 'object'], 'knowledge' => ['type' => 'object'], 'deduplication_basis' => ['type' => 'string'], 'possible_duplicate_character_ids' => $integerArray, 'introduction_reason' => ['type' => 'string'], 'target_scene_sequence' => ['type' => 'integer', 'minimum' => 1],
        ]];
        $worldCandidate = ['type' => 'object', 'additionalProperties' => false, 'required' => ['candidate_key', 'type', 'name', 'description', 'deduplication_basis', 'possible_duplicate_entity_ids', 'introduction_reason', 'target_scene_sequence'], 'properties' => [
            'candidate_key' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['location', 'item', 'faction', 'organization', 'rule', 'concept']], 'name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'deduplication_basis' => ['type' => 'string'], 'possible_duplicate_entity_ids' => $integerArray, 'introduction_reason' => ['type' => 'string'], 'target_scene_sequence' => ['type' => 'integer', 'minimum' => 1],
        ]];
        $beat = ['type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'sequence', 'title', 'summary', 'chapter_budget', 'acceptance_criteria', 'must_include', 'must_not_include', 'character_candidates', 'world_entity_candidates'], 'properties' => [
            'key' => ['type' => 'string'], 'sequence' => ['type' => 'integer', 'minimum' => 1], 'title' => ['type' => 'string'], 'summary' => ['type' => 'string'],
            'chapter_budget' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['min', 'max'], 'properties' => ['min' => ['type' => 'integer', 'minimum' => 1], 'max' => ['type' => ['integer', 'null'], 'minimum' => 1]]],
            'acceptance_criteria' => $stringArray, 'must_include' => $stringArray, 'must_not_include' => $stringArray,
            'character_candidates' => ['type' => 'array', 'items' => $characterCandidate], 'world_entity_candidates' => ['type' => 'array', 'items' => $worldCandidate],
        ]];
        $arc = ['type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'sequence', 'type', 'title', 'goal', 'stakes', 'completion_conditions', 'beats'], 'properties' => [
            'key' => ['type' => 'string'], 'sequence' => ['type' => 'integer', 'minimum' => 1], 'type' => ['type' => 'string', 'enum' => ['main', 'subplot']], 'title' => ['type' => 'string'], 'goal' => ['type' => 'string'], 'stakes' => ['type' => 'string'], 'completion_conditions' => $stringArray, 'beats' => ['type' => 'array', 'items' => $beat],
        ]];
        $volume = ['type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'sequence', 'title', 'goal', 'climax', 'target_words', 'arcs'], 'properties' => [
            'key' => ['type' => 'string'], 'sequence' => ['type' => 'integer', 'minimum' => 1], 'title' => ['type' => 'string'], 'goal' => ['type' => 'string'], 'climax' => ['type' => 'string'], 'target_words' => ['type' => 'integer', 'minimum' => 1], 'arcs' => ['type' => 'array', 'items' => $arc],
        ]];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'summary', 'must_include', 'must_not_include', 'baseline_completions', 'volumes'],
            'properties' => [
                'title' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'must_include' => $stringArray, 'must_not_include' => $stringArray,
                'baseline_completions' => ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'object']],
                'volumes' => ['type' => 'array', 'items' => $volume],
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
