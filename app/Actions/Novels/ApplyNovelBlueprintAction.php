<?php

namespace App\Actions\Novels;

use App\Actions\Story\InitializeNovelStateAction;
use App\Data\NormalizedNovelOutline;
use App\Enums\ArtifactType;
use App\Enums\BibleStatus;
use App\Enums\CharacterStatus;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Enums\VolumeStatus;
use App\Enums\WorldEntityStatus;
use App\Enums\WorldEntityType;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\NovelOutline;
use App\Services\GenerationInputIdentity;
use App\Services\GenerationRunCoordinator;
use App\Services\NormalizedNovelOutlineValidator;
use App\Services\NovelFoundationContract;
use App\Services\NovelOutlineChecksum;
use App\Services\NovelOutlinePipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 原子采用关系化 Outline，并建立对应的运行态 Volume 与 Arc。
 */
class ApplyNovelBlueprintAction
{
    public function __construct(
        private readonly CreateBibleVersionAction $createBibleVersion,
        private readonly InitializeNovelStateAction $initializeNovelState,
        private readonly NormalizedNovelOutlineValidator $outlineValidator,
        private readonly NovelOutlineChecksum $outlineChecksum,
    ) {}

    /** 新链首次初始化：三份冻结产物全部就绪后一次采用；不创建未来故事事件。 */
    public function initializeBook(int $taskId, int $foundationId, int $blueprintId, int $windowId, int $expectedControlVersion): Novel
    {
        $initial = NovelGenerationTask::findOrFail($taskId);

        return DB::transaction(function () use ($initial, $foundationId, $blueprintId, $windowId, $expectedControlVersion): Novel {
            $novel = Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $identity = ['intent' => $task->intent_artifact_id, 'foundation' => $foundationId, 'blueprint' => $blueprintId, 'window' => $windowId];
            $hash = app(GenerationInputIdentity::class)->hash($identity);
            if ($novel->canonical_state_version_id && data_get($task->progress_snapshot, 'initialization_hash') === $hash) {
                return $novel;
            }
            if ($novel->current_generation_task_id !== $task->id || $task->control_version !== $expectedControlVersion || $task->status !== 'running') {
                throw new \LogicException('任务已暂停或控制版本变化，不能初始化。');
            }
            if ($novel->canonical_state_version_id || $novel->bibles()->exists() || $novel->characters()->exists() || $novel->worldEntities()->exists() || $novel->chapters()->exists()) {
                throw new \LogicException('小说已存在正式初始数据；修改概述只能影响未来规划，不能重新初始化。');
            }
            $foundation = GenerationArtifact::findOrFail($foundationId);
            $blueprint = NovelOutline::whereKey($blueprintId)->lockForUpdate()->firstOrFail();
            $window = NovelOutline::whereKey($windowId)->lockForUpdate()->firstOrFail();
            $intent = GenerationArtifact::findOrFail($task->intent_artifact_id);
            $sources = collect([$intent, $foundation, $blueprint->sourceArtifact, $window->sourceArtifact])->map(function ($artifact) {
                if (! $artifact) {
                    throw new \LogicException('规划缺少冻结来源。');
                }

                return ['artifact_id' => $artifact->id, 'checksum' => $artifact->checksum];
            })->all();
            app(GenerationRunCoordinator::class)->assertSources($task, $sources);
            app(GenerationRunCoordinator::class)->assertSources($task, $foundation->source_manifest ?? [], $foundation->generationRun->execution_contract ?? []);
            if ($foundation->generationRun->status !== RunStatus::Succeeded || $foundation->type !== ArtifactType::FoundationBlueprint || $foundation->generationRun->generation_task_id !== $task->id
                || data_get($foundation->generationRun->execution_contract, 'intent_artifact_id') !== $intent->id) {
                throw new \LogicException('基础设定不是当前意图的已验证产物。');
            }
            app(NovelFoundationContract::class)->validate($foundation->data, $intent->data);
            if ($foundation->data['conflicts'] !== []) {
                throw new \LogicException('明确创作矛盾尚未由用户选择。');
            }
            foreach ([[$blueprint, 'blueprint', ArtifactType::OutlineBlueprint], [$window, 'goal_window', ArtifactType::GoalWindow]] as [$outline, $kind, $type]) {
                if ($outline->novel_id !== $novel->id || $outline->kind !== $kind || $outline->status !== NovelOutlineStatus::Draft
                    || $outline->sourceArtifact->type !== $type || ! hash_equals($outline->checksum, $outline->sourceArtifact->checksum)) {
                    throw new \LogicException('蓝图或目标窗口的归属、类型、状态或校验和不匹配。');
                }
            }
            $plan = $blueprint->sourceArtifact->data;
            $goals = $window->sourceArtifact->data;
            Validator::make($plan, ['foundation_artifact_id' => ['required', 'integer', 'in:'.$foundationId], 'mainline' => ['required', 'string'], 'ending' => ['required', 'string'], 'turning_points' => ['required', 'array', 'min:1'], 'turning_points.*' => ['required', 'string']])->validate();
            Validator::make($goals, ['blueprint_id' => ['required', 'integer', 'in:'.$blueprintId], 'goals' => ['required', 'array', 'min:1'], 'goals.*' => ['required']])->validate();
            $fields = $foundation->data['fields'];
            // 新 Bible 保存完整原文要求与来源；旧文风表单的枚举默认值不覆盖自然语言要求；旧必填 POV/时态列只指向真实来源，不捏造第三人称。
            $novel->bibles()->create(['version' => 1, 'status' => BibleStatus::Current,
                'logline' => $fields['plot']['value'], 'tone' => mb_substr($fields['style']['value'], 0, 255), 'pov' => '以原始创作要求及文风设定为准', 'tense' => '以原始创作要求及文风设定为准',
                'themes' => [], 'taboos' => [], 'hard_constraints' => ['user_requirements' => $intent->data['requirements'], 'clarifications' => $intent->data['clarifications'] ?? []],
                'ending_contract' => ['direction' => $fields['ending']['value'], 'reader_promises' => $foundation->data['promises']],
                'style_profile' => ['schema_version' => 'book-v1', 'description' => $fields['style']['value'], 'source' => $fields['style']['source']]]);
            foreach ($foundation->data['characters'] as $character) {
                $novel->characters()->create(['name' => $character['name'], 'role' => $character['role'], 'motivation' => $character['description'], 'profile' => ['description' => $character['description'], 'source_artifact_id' => $foundationId], 'status' => CharacterStatus::Active, 'current_state' => []]);
            }
            foreach ($foundation->data['world_entities'] as $entity) {
                $novel->worldEntities()->create(['name' => $entity['name'], 'description' => $entity['description'], 'type' => WorldEntityType::Location, 'status' => WorldEntityStatus::Active, 'attributes' => ['source_artifact_id' => $foundationId], 'current_state' => []]);
            }
            $blueprint->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
            $window->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
            $novel->update(['title' => mb_substr($fields['title']['value'], 0, 255), 'genre' => mb_substr($fields['genre']['value'], 0, 255),
                'current_blueprint_id' => $blueprintId, 'current_goal_window_id' => $windowId, 'status' => NovelStatus::Planning]);
            $state = $this->initializeNovelState->handle($novel);
            $progress = $task->progress_snapshot ?? [];
            $progress['initialization_hash'] = $hash;
            $progress['initialization'] = [...$identity, 'state_version_id' => $state->id, 'sources' => $sources, 'mode' => $task->mode, 'at' => now()->toISOString()];
            // 不可变采用记录与正式初始化同事务，Task 快照只作快捷展示。
            $adoption = GenerationRun::create(['novel_id' => $novel->id, 'generation_task_id' => $task->id, 'scope_type' => 'novel', 'scope_id' => $novel->id,
                'scope_key' => 'initialization', 'stage' => GenerationStage::Intake, 'pipeline_version' => 'pipeline-v1', 'status' => RunStatus::Succeeded, 'attempt' => 1,
                'input_hash' => $hash, 'logical_step_key' => $novel->id.':initialize:'.$hash, 'idempotency_key' => hash('sha256', 'initialize:'.$novel->id.':'.$hash), 'source_manifest' => $sources,
                'started_at' => now(), 'finished_at' => now()]);
            $receipt = GenerationArtifact::create(['generation_run_id' => $adoption->id, 'type' => ArtifactType::OperationRecord, 'data' => $progress['initialization'],
                'checksum' => app(GenerationInputIdentity::class)->hash($progress['initialization']), 'source_manifest' => $sources, 'validation_status' => 'validated']);
            $adoption->update(['result_artifact_id' => $receipt->id]);
            // 后续 Writer 未接入时不假装生成中；R04 从这些正式指针继续。
            $task->update(['progress_snapshot' => $progress, 'status' => 'paused', 'current_phase' => 'chapter_planning', 'pending_transition' => null,
                'control_version' => $task->control_version + 1, 'stop_reason' => 'stage_not_ready', 'stop_detail' => ['message' => '初始设定已采用，章节规划与正文等待 R04 接入。']]);

            return $novel->fresh();
        });
    }

    /**
     * 校验冻结版本和来源后，在同一事务内完成首次正式采用。
     */
    public function handle(Novel $novel, NovelOutline $outline, ?GenerationArtifact $artifact = null): Novel
    {
        if ($novel->current_generation_task_id) {
            throw new \LogicException('新版任务只能由整书入口采用完整的设定、蓝图与目标窗口。');
        }

        // 首次采用是正式规划数据的边界，所有写入必须在同一事务内全有或全无。
        return DB::transaction(function () use ($novel, $outline, $artifact): Novel {
            // 同时锁定 Novel 与候选版本，串行化首次采用并保护 current_outline_id。
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $selected = NovelOutline::query()->lockForUpdate()->findOrFail($outline->getKey());

            if ($selected->novel_id !== $locked->getKey()) {
                throw ValidationException::withMessages(['outline' => '该 Outline Version 不属于当前小说。']);
            }

            if ($selected->status === NovelOutlineStatus::Current && $locked->current_outline_id === $selected->getKey()) {
                // 重复提交同一版本直接返回，避免重复创建 Bible、人物、分卷和故事线。
                return $locked->refresh();
            }

            if ($selected->status !== NovelOutlineStatus::Draft) {
                throw ValidationException::withMessages(['outline' => '首次采用只接受 Draft Outline Version。']);
            }

            // 采用前重新校验结构和 checksum，不能仅依赖候选创建时的验证结果。
            $this->outlineValidator->assertValid(NormalizedNovelOutline::fromModel($selected));
            if (! hash_equals($selected->checksum, $this->outlineChecksum->for($selected))) {
                throw ValidationException::withMessages(['outline' => 'Outline checksum 与冻结内容不一致。']);
            }

            if ($locked->current_outline_id !== null || $locked->volumes()->exists() || $locked->storyArcs()->exists()) {
                throw ValidationException::withMessages(['outline' => '小说已有正式大纲、分卷或故事线，不能再次执行首次采用。']);
            }

            if ($locked->chapters()->exists() || $locked->storyEvents()->exists()) {
                throw ValidationException::withMessages(['outline' => '小说已经产生章节或正式事件，不能再采用初始大纲。']);
            }

            // AI 根版本从冻结 Artifact 读取初始化资料；手工根版本必须使用已存在的 Bible。
            $bootstrap = $this->bootstrapData($locked, $selected, $artifact);
            if ($bootstrap !== null) {
                $this->assertEmptyBootstrapTables($locked);
                $this->persistAiBootstrap($locked, $bootstrap);
            } elseif ($locked->currentBible === null) {
                throw ValidationException::withMessages([
                    'outline' => '手工大纲采用前必须先创建小说圣经；初始人物和世界资料可在 Workspace 中手工建立。',
                ]);
            }

            // 第一卷及其故事线立即激活，后续卷保持 Planned，供章节规划按顺序推进。
            $arcs = collect();
            $selected->load('volumes.arcs');
            foreach ($selected->volumes as $volumeData) {
                $firstMainArcId = $volumeData->arcs
                    ->where('type', StoryArcType::Main)
                    ->sortBy(fn ($arc): int => $arc->mainline_sequence ?? PHP_INT_MAX)
                    ->first()?->getKey();
                $volume = $locked->volumes()->create([
                    'source_outline_volume_id' => $volumeData->getKey(),
                    'sequence' => $volumeData->sequence,
                    'title' => $volumeData->title,
                    'goal' => $volumeData->goal,
                    'climax' => $volumeData->climax,
                    'target_words' => $volumeData->target_words,
                    'status' => $volumeData->sequence === 1 ? VolumeStatus::Active : VolumeStatus::Planned,
                ]);

                foreach ($volumeData->arcs as $arcData) {
                    $arc = $locked->storyArcs()->create([
                        'volume_id' => $volume->getKey(),
                        'source_outline_arc_id' => $arcData->getKey(),
                        'sequence' => $arcData->sequence,
                        'type' => $arcData->type,
                        'title' => $arcData->title,
                        'goal' => $arcData->goal,
                        'stakes' => $arcData->stakes,
                        'completion_conditions' => $arcData->completion_conditions,
                        'progress' => 0,
                        // 当前卷只激活顺序最早的主线；支线同时开放给 Planner，后续主线由生命周期推进器激活。
                        'status' => $volumeData->sequence === 1
                            && ($arcData->type === StoryArcType::Subplot || $arcData->getKey() === $firstMainArcId)
                                ? StoryArcStatus::Active
                                : StoryArcStatus::Planned,
                    ]);
                    $arcs->put($arcData->arc_key, $arc);
                }
            }

            if ($bootstrap !== null) {
                // 先通过稳定 owner_arc_key 查找刚创建的 Arc，再保存正式外键。
                foreach ($bootstrap['foreshadowings'] as $item) {
                    $locked->foreshadowings()->create([
                        ...collect($item)->except('owner_arc_key')->all(),
                        'owner_arc_id' => filled($item['owner_arc_key'] ?? null) ? $arcs->get($item['owner_arc_key'])?->getKey() : null,
                        'status' => ForeshadowingStatus::Idea,
                        'reinforce_count' => 0,
                    ]);
                }
            }

            // 只有完成全部初始化写入后才切换 Current 指针，避免出现半采用状态。
            $locked->outlines()
                ->whereKeyNot($selected->getKey())
                ->where('status', NovelOutlineStatus::Draft->value)
                ->get()
                ->each(fn (NovelOutline $draft) => $draft->update(['status' => NovelOutlineStatus::Superseded]));
            $selected->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
            $locked->update([
                'current_outline_id' => $selected->getKey(),
                'status' => NovelStatus::Planning,
            ]);

            if ($locked->canonical_state_version_id === null) {
                // 正文开始前必须有初始 Canonical State；它和 Outline 分别承担事实与规划职责。
                $this->initializeNovelState->handle($locked);
            } else {
                $this->initializeNovelState->refreshBeforeFirstChapter($locked);
            }

            return $locked->refresh();
        });
    }

    /** @return array<string, mixed>|null 读取 AI 根版本的初始化资料；手工版本返回空。 */
    private function bootstrapData(Novel $novel, NovelOutline $outline, ?GenerationArtifact $artifact): ?array
    {
        // 沿 based_on 链找到根版本，判断该修订链最初来自 AI 还是人工创建。
        $root = $outline;
        while ($root->based_on_outline_id !== null) {
            $root = $root->basedOn()->firstOrFail();
        }

        if ($root->source !== NovelOutlineSource::Ai) {
            if ($artifact !== null) {
                throw ValidationException::withMessages(['blueprint' => '手工 Outline 不能绑定 AI Blueprint Artifact。']);
            }

            return null;
        }

        $sourceArtifact = $root->sourceArtifact;
        if ($sourceArtifact === null
            || ($artifact !== null && $artifact->getKey() !== $sourceArtifact->getKey())
            || $sourceArtifact->type !== ArtifactType::OutlineBlueprint
            || $sourceArtifact->generationRun()
                ->where('novel_id', $novel->getKey())
                ->where('scope_type', NovelOutlinePipeline::FINALIZE_SCOPE)
                ->where('scope_id', data_get($sourceArtifact->data, 'lineage.batch_run_id'))
                ->doesntExist()) {
            throw ValidationException::withMessages(['blueprint' => 'AI Outline 缺少属于当前小说的来源 Artifact。']);
        }

        $this->assertCompleteLineage($novel, $sourceArtifact);

        // checksum 绑定 Outline 与来源 Artifact，防止把其他小说或其他候选的初始化资料混入。
        $artifactOutline = data_get($sourceArtifact->data, 'outline');
        $artifactChecksum = is_array($artifactOutline) ? $this->outlineChecksum->for($artifactOutline) : null;
        if ($artifactChecksum === null
            || (! hash_equals($root->checksum, $artifactChecksum) && ! hash_equals($outline->checksum, $artifactChecksum))) {
            throw ValidationException::withMessages(['blueprint' => 'AI Outline 与来源 Artifact 不匹配。']);
        }

        /** @var array<string, mixed> $data */
        $data = $sourceArtifact->data;

        return $data;
    }

    /**
     * Finalize 来源必须完整绑定同一批次的全部阶段 Artifact，禁止跨小说或跨批次拼接初始化资料。
     */
    private function assertCompleteLineage(Novel $novel, GenerationArtifact $blueprint): void
    {
        $lineage = data_get($blueprint->data, 'lineage');
        $batchRunId = is_array($lineage) ? ($lineage['batch_run_id'] ?? null) : null;
        if (! is_int($batchRunId) || ! is_array($lineage)) {
            throw ValidationException::withMessages(['blueprint' => '最终 Outline Blueprint 缺少完整阶段来源。']);
        }

        $expectedArcKeys = collect(data_get($blueprint->data, 'outline.volumes', []))
            ->flatMap(fn (array $volume): array => $volume['arcs'] ?? [])
            ->pluck('key')
            ->values()
            ->all();
        $expectedBeatKeys = collect(data_get($blueprint->data, 'outline.volumes', []))
            ->flatMap(fn (array $volume) => collect($volume['arcs'] ?? [])->where('type', 'main')->flatMap(fn (array $arc): array => $arc['beats'] ?? []))
            ->sortBy('mainline_sequence')
            ->pluck('key')
            ->values()
            ->all();
        if (! is_array($lineage['beat_details'] ?? null)
            || array_keys($lineage['beat_details']) !== $expectedBeatKeys
            || ! hash_equals($blueprint->checksum, hash('sha256', json_encode($blueprint->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))) {
            throw ValidationException::withMessages(['blueprint' => '最终 Outline Blueprint 的 Beat 来源集合或 Checksum 不完整。']);
        }

        $isNewLineage = array_key_exists('structure', $lineage) || array_key_exists('arc_beats', $lineage);
        if ($isNewLineage && (! is_array($lineage['structure'] ?? null)
            || ! is_array($lineage['arc_beats'] ?? null)
            || array_keys($lineage['arc_beats']) !== $expectedArcKeys)) {
            throw ValidationException::withMessages(['blueprint' => '最终 Outline Blueprint 的 Structure 或 Arc Beats 来源集合不完整。']);
        }

        $references = [[
            'reference' => $lineage['foundation'] ?? null,
            'type' => ArtifactType::OutlineFoundation,
            'scope' => NovelOutlinePipeline::FOUNDATION_SCOPE,
            'discriminator' => null,
        ]];
        if ($isNewLineage) {
            $references[] = [
                'reference' => $lineage['structure'],
                'type' => ArtifactType::OutlineStructure,
                'scope' => NovelOutlinePipeline::STRUCTURE_SCOPE,
                'discriminator' => null,
            ];
            foreach ($expectedArcKeys as $arcKey) {
                $references[] = [
                    'reference' => $lineage['arc_beats'][$arcKey],
                    'type' => ArtifactType::OutlineArcBeats,
                    'scope' => NovelOutlinePipeline::ARC_BEATS_SCOPE,
                    'discriminator' => $arcKey,
                ];
            }
        }
        $references[] = [
            'reference' => $lineage['skeleton'] ?? null,
            'type' => ArtifactType::OutlineSkeleton,
            'scope' => $isNewLineage ? NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE : NovelOutlinePipeline::SKELETON_SCOPE,
            'discriminator' => null,
        ];
        foreach ($expectedBeatKeys as $beatKey) {
            $references[] = [
                'reference' => $lineage['beat_details'][$beatKey],
                'type' => ArtifactType::OutlineBeatDetail,
                'scope' => NovelOutlinePipeline::BEAT_DETAIL_SCOPE,
                'discriminator' => $beatKey,
            ];
        }

        foreach ($references as $descriptor) {
            $reference = $descriptor['reference'];
            $expected = $descriptor['type'];
            $expectedScope = $descriptor['scope'];
            $expectedDiscriminator = $descriptor['discriminator'];
            if (! is_array($reference)
                || ! is_int($reference['id'] ?? null)
                || ! is_string($reference['checksum'] ?? null)
                || ($reference['type'] ?? null) !== $expected->value) {
                throw ValidationException::withMessages(['blueprint' => '最终 Outline Blueprint 缺少上游 Artifact ID 或 Checksum。']);
            }
            $upstream = GenerationArtifact::query()->with('generationRun')->find($reference['id']);
            if ($upstream === null
                || $upstream->type !== $expected
                || ! hash_equals($upstream->checksum, $reference['checksum'])
                || ! hash_equals($upstream->checksum, hash('sha256', json_encode($upstream->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))
                || $upstream->generationRun->novel_id !== $novel->getKey()
                || $upstream->generationRun->scope_type !== $expectedScope
                || $upstream->generationRun->scope_id !== $batchRunId
                || $upstream->generationRun->status !== RunStatus::Succeeded
                || data_get($upstream->data, 'batch_run_id') !== $batchRunId
                || data_get($upstream->data, 'stage') !== $expectedScope
                || data_get($upstream->data, 'discriminator') !== $expectedDiscriminator) {
                throw ValidationException::withMessages(['blueprint' => '最终 Outline Blueprint 引用了其他小说、其他批次或不匹配的阶段 Artifact。']);
            }
        }
    }

    /**
     * 拒绝 AI 初始化覆盖已经存在的人工 Bible、人物、世界资料或伏笔。
     */
    private function assertEmptyBootstrapTables(Novel $novel): void
    {
        // AI 初始化不能覆盖或合并人工资料；出现既有内容时要求用户先明确处理冲突。
        if ($novel->bibles()->exists() || $novel->characters()->exists() || $novel->worldEntities()->exists() || $novel->foreshadowings()->exists()) {
            throw ValidationException::withMessages(['blueprint' => 'AI 初始规划只能应用到尚未手工建立 Bible、人物、世界资料或伏笔的小说。']);
        }
    }

    /** @param array<string, mixed> $data 保存 AI 规划中开篇即成立的正式基础资料。 */
    private function persistAiBootstrap(Novel $novel, array $data): void
    {
        // 这里只写入开篇即成立的资料；Beat Candidate 要等 Canonical Commit 后才能转为正式对象。
        $this->createBibleVersion->execute($novel, $data['bible']);

        foreach ($data['characters'] as $character) {
            $novel->characters()->create([
                ...$character,
                'aliases' => [],
                'locked_fields' => [],
                'status' => CharacterStatus::Active,
            ]);
        }

        foreach ($data['world_entities'] as $entity) {
            $novel->worldEntities()->create([
                ...$entity,
                'locked_fields' => [],
                'status' => WorldEntityStatus::Active,
            ]);
        }
    }
}
