<?php

use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\Pages\ManageNovelOutline;
use App\Jobs\GenerateNovelFoundationJob;
use App\Jobs\GenerateNovelOutlineJob;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\User;
use App\Services\NovelOutlinePipeline;
use App\Services\NovelOutlineStageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/** @return array<string, string> */
function outlineUiPromptVersions(): array
{
    return [
        'foundation' => NovelOutlinePipeline::FOUNDATION_PROMPT_VERSION,
        'structure' => NovelOutlineStageContract::STRUCTURE_PROMPT_VERSION,
        'arc_beats' => NovelOutlineStageContract::ARC_BEATS_PROMPT_VERSION,
        'skeleton_assembly' => NovelOutlinePipeline::SKELETON_ASSEMBLY_VERSION,
        'beat_detail' => NovelOutlinePipeline::BEAT_DETAIL_PROMPT_VERSION,
        'finalize' => NovelOutlinePipeline::FINALIZE_PROMPT_VERSION,
    ];
}

/** @return array<string, array<string, mixed>> */
function outlineUiRoutes(): array
{
    $prompts = outlineUiPromptVersions();

    return collect([
        'outline_foundation' => $prompts['foundation'],
        'outline_structure' => $prompts['structure'],
        'outline_arc_beats' => $prompts['arc_beats'],
        'outline_beat_detail' => $prompts['beat_detail'],
    ])->mapWithKeys(fn (string $prompt, string $stage): array => [$stage => [
        'provider' => 'openai',
        'model' => 'gpt-5.6-terra',
        'reasoning_effort' => 'medium',
        'source' => 'database',
        'prompt_version' => $prompt,
        'model_capacity' => [
            'provider' => 'openai',
            'model' => 'gpt-5.6-terra',
            'context_window_tokens' => 1050000,
            'max_output_tokens' => 128000,
        ],
    ]])->all();
}

function outlineUiBatch(Novel $novel, RunStatus $status = RunStatus::Running, array $overrides = []): GenerationRun
{
    return GenerationRun::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => null,
        'scope_type' => NovelOutlinePipeline::BATCH_SCOPE,
        'scope_id' => $novel->getKey(),
        'stage' => GenerationStage::ChapterPlanning,
        'status' => $status,
        'attempt' => 1,
        'prompt_version' => NovelOutlinePipeline::BATCH_PROMPT_VERSION,
        'provider' => null,
        'model_policy' => null,
        'context_snapshot' => [
            'requested_volume_count' => 1,
            'target_platform' => ['code' => 'fanqie'],
            'generation_preferences' => [
                'outline_routes' => outlineUiRoutes(),
                'prompt_versions' => outlineUiPromptVersions(),
            ],
        ],
        'started_at' => $status === RunStatus::Queued ? null : now()->subSeconds(126),
        'finished_at' => in_array($status, [RunStatus::Failed, RunStatus::Succeeded, RunStatus::Cancelled], true) ? now() : null,
        ...$overrides,
    ]);
}

function outlineUiRun(
    Novel $novel,
    GenerationRun $batch,
    string $scope,
    RunStatus $status = RunStatus::Succeeded,
    ?string $discriminator = null,
    array $overrides = [],
): GenerationRun {
    $promptKey = match ($scope) {
        NovelOutlinePipeline::FOUNDATION_SCOPE => 'foundation',
        NovelOutlinePipeline::STRUCTURE_SCOPE => 'structure',
        NovelOutlinePipeline::ARC_BEATS_SCOPE => 'arc_beats',
        NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE => 'skeleton_assembly',
        NovelOutlinePipeline::BEAT_DETAIL_SCOPE => 'beat_detail',
        default => 'finalize',
    };

    return GenerationRun::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => null,
        'scope_type' => $scope,
        'scope_id' => $batch->getKey(),
        'stage' => GenerationStage::ChapterPlanning,
        'status' => $status,
        'attempt' => 1,
        'prompt_version' => outlineUiPromptVersions()[$promptKey],
        'provider' => in_array($scope, [NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE, NovelOutlinePipeline::FINALIZE_SCOPE], true) ? null : 'openai',
        'model_policy' => in_array($scope, [NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE, NovelOutlinePipeline::FINALIZE_SCOPE], true) ? null : 'gpt-5.6-terra',
        'context_snapshot' => array_filter([
            'batch_run_id' => $batch->getKey(),
            'discriminator' => $discriminator,
        ], static fn (mixed $value): bool => $value !== null),
        'started_at' => now()->subSeconds(5),
        'finished_at' => in_array($status, [RunStatus::Queued, RunStatus::Running], true) ? null : now(),
        ...$overrides,
    ]);
}

function outlineUiArtifact(GenerationRun $run, ArtifactType $type, array $data): GenerationArtifact
{
    $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    return GenerationArtifact::factory()->create([
        'generation_run_id' => $run->getKey(),
        'type' => $type,
        'content' => $encoded,
        'data' => $data,
        'checksum' => hash('sha256', $encoded),
    ]);
}

/** @return array{id: int, type: string, checksum: string} */
function outlineUiReference(GenerationArtifact $artifact): array
{
    return ['id' => $artifact->getKey(), 'type' => $artifact->type->value, 'checksum' => $artifact->checksum];
}

function outlineUiFoundation(Novel $novel, GenerationRun $batch): GenerationArtifact
{
    return outlineUiArtifact(
        outlineUiRun($novel, $batch, NovelOutlinePipeline::FOUNDATION_SCOPE),
        ArtifactType::OutlineFoundation,
        [
            'batch_run_id' => $batch->getKey(),
            'stage' => NovelOutlinePipeline::FOUNDATION_SCOPE,
            'discriminator' => null,
            'source_artifacts' => [],
            'payload' => ['bible' => ['logline' => '测试故事']],
        ],
    );
}

/** @param array<int, array{key: string, title: string, type?: string}> $arcs */
function outlineUiStructure(Novel $novel, GenerationRun $batch, GenerationArtifact $foundation, array $arcs): GenerationArtifact
{
    return outlineUiArtifact(
        outlineUiRun($novel, $batch, NovelOutlinePipeline::STRUCTURE_SCOPE),
        ArtifactType::OutlineStructure,
        [
            'batch_run_id' => $batch->getKey(),
            'stage' => NovelOutlinePipeline::STRUCTURE_SCOPE,
            'discriminator' => null,
            'source_artifacts' => [outlineUiReference($foundation)],
            'payload' => [
                'title' => '测试大纲',
                'summary' => '测试进度展示。',
                'volumes' => [[
                    'key' => 'vol-01',
                    'title' => '第一卷',
                    'arcs' => array_map(fn (array $arc): array => [
                        'key' => $arc['key'],
                        'title' => $arc['title'],
                        'type' => $arc['type'] ?? 'main',
                    ], $arcs),
                ]],
            ],
        ],
    );
}

function outlineUiArc(
    Novel $novel,
    GenerationRun $batch,
    GenerationArtifact $foundation,
    GenerationArtifact $structure,
    string $arcKey,
    array $beats,
): GenerationArtifact {
    return outlineUiArtifact(
        outlineUiRun($novel, $batch, NovelOutlinePipeline::ARC_BEATS_SCOPE, RunStatus::Succeeded, $arcKey),
        ArtifactType::OutlineArcBeats,
        [
            'batch_run_id' => $batch->getKey(),
            'stage' => NovelOutlinePipeline::ARC_BEATS_SCOPE,
            'discriminator' => $arcKey,
            'source_artifacts' => [outlineUiReference($foundation), outlineUiReference($structure)],
            'payload' => ['arc_key' => $arcKey, 'beats' => $beats],
        ],
    );
}

/** @param array<string, GenerationArtifact> $arcs */
function outlineUiSkeleton(
    Novel $novel,
    GenerationRun $batch,
    GenerationArtifact $foundation,
    GenerationArtifact $structure,
    array $arcs,
    array $beats,
): GenerationArtifact {
    return outlineUiArtifact(
        outlineUiRun($novel, $batch, NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE),
        ArtifactType::OutlineSkeleton,
        [
            'batch_run_id' => $batch->getKey(),
            'stage' => NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE,
            'discriminator' => null,
            'source_artifacts' => [
                'foundation' => outlineUiReference($foundation),
                'structure' => outlineUiReference($structure),
                'arc_beats' => collect($arcs)->map(fn (GenerationArtifact $artifact): array => outlineUiReference($artifact))->all(),
            ],
            'payload' => [
                'title' => '测试大纲',
                'summary' => '测试进度展示。',
                'volumes' => [[
                    'key' => 'vol-01',
                    'title' => '第一卷',
                    'arcs' => [[
                        'key' => 'arc-01',
                        'title' => '主线',
                        'type' => 'main',
                        'beats' => $beats,
                    ]],
                ]],
            ],
        ],
    );
}

function outlineUiDetail(Novel $novel, GenerationRun $batch, GenerationArtifact $foundation, GenerationArtifact $skeleton, string $beatKey): GenerationArtifact
{
    return outlineUiArtifact(
        outlineUiRun($novel, $batch, NovelOutlinePipeline::BEAT_DETAIL_SCOPE, RunStatus::Succeeded, $beatKey),
        ArtifactType::OutlineBeatDetail,
        [
            'batch_run_id' => $batch->getKey(),
            'stage' => NovelOutlinePipeline::BEAT_DETAIL_SCOPE,
            'discriminator' => $beatKey,
            'source_artifacts' => [outlineUiReference($foundation), outlineUiReference($skeleton)],
            'payload' => ['beat_key' => $beatKey, 'milestones' => []],
        ],
    );
}

test('outline page starts a persisted queued batch and polls only while active', function () {
    Queue::fake();
    config()->set('generation.outline_planner_capacity.provider', config('ai.provider'));
    config()->set('generation.outline_planner_capacity.model', config('ai.models.planner'));
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Draft,
        'target_words' => 200000,
    ]);

    $component = Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
        ->assertSee('AI 大纲生成')
        ->assertSee('尚未开始')
        ->assertActionVisible('generateOutlineCandidate')
        ->assertActionEnabled('generateOutlineCandidate')
        ->assertDontSeeHtml('wire:poll.3s="$refresh"');

    $component->callAction('generateOutlineCandidate', ['volume_count' => 1]);

    Queue::assertPushed(GenerateNovelOutlineJob::class, 1);
    expect($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->count())->toBe(1);

    $component
        ->assertSee('排队中')
        ->assertActionDisabled('generateOutlineCandidate')
        ->assertSeeHtml('wire:poll.3s="$refresh"');

    $batch = $novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->sole();
    $batch->update([
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
        'error_message' => 'request timed out',
        'error_retryable' => true,
        'error_metadata' => [
            'failed_scope' => NovelOutlinePipeline::FOUNDATION_SCOPE,
            'auto_retry_exhausted' => true,
            'category' => 'external_temporary',
        ],
        'finished_at' => now(),
    ]);

    $component
        ->call('refresh')
        ->assertSee('生成失败')
        ->assertActionVisible('resumeOutlineGeneration')
        ->assertActionHidden('generateOutlineCandidate')
        ->assertDontSeeHtml('wire:poll.3s="$refresh"');

    Queue::assertPushed(GenerateNovelOutlineJob::class, 1);
});

test('outline generation action previews every resolved task route before start', function () {
    config()->set('generation.outline_planner_capacity.provider', config('ai.provider'));
    config()->set('generation.outline_planner_capacity.model', config('ai.models.planner'));
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft]);

    Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
        ->mountAction('generateOutlineCandidate')
        ->assertActionMounted('generateOutlineCandidate')
        ->assertMountedActionModalSee([
            '启动前路由预览',
            AiStage::OutlineFoundation->getLabel(),
            AiStage::OutlineStructure->getLabel(),
            AiStage::OutlineArcBeats->getLabel(),
            AiStage::OutlineBeatDetail->getLabel(),
            '可启动 · OPENAI / '.config('ai.models.planner'),
            '容量 1,050,000 / 128,000',
        ]);
});

test('outline route preview exposes a capacity mismatch and start still rejects it', function () {
    Queue::fake();
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('generation.outline_planner_capacity.provider', config('ai.provider'));
    config()->set('generation.outline_planner_capacity.model', config('ai.models.planner'));
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Draft,
        'settings' => ['ai' => ['stages' => [
            'outline_structure' => [
                'provider' => 'openai',
                'model' => 'unverified-structure-model',
            ],
        ]]],
    ]);
    $component = Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
        ->mountAction('generateOutlineCandidate')
        ->assertActionMounted('generateOutlineCandidate')
        ->assertMountedActionModalSee(['不可启动', 'unverified-structure-model']);

    $component
        ->setActionData(['volume_count' => 1])
        ->callMountedAction()
        ->assertNotified('无法生成大纲候选');

    Queue::assertNotPushed(GenerateNovelOutlineJob::class);
    expect($novel->generationRuns()->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)->count())->toBe(0);
});

test('outline page shows arc and main beat progress without a synthetic overall percentage', function () {
    $arcNovel = Novel::factory()->create();
    $arcBatch = outlineUiBatch($arcNovel);
    $arcFoundation = outlineUiFoundation($arcNovel, $arcBatch);
    $arcStructure = outlineUiStructure($arcNovel, $arcBatch, $arcFoundation, [
        ['key' => 'arc-01', 'title' => '主线'],
        ['key' => 'arc-02', 'title' => '长安夺嫡线'],
    ]);
    outlineUiArc($arcNovel, $arcBatch, $arcFoundation, $arcStructure, 'arc-01', []);

    Livewire::test(ManageNovelOutline::class, ['record' => $arcNovel->getRouteKey()])
        ->assertSee('故事线节点')
        ->assertSee('1 / 2')
        ->assertSee('第一卷 · 长安夺嫡线')
        ->assertDontSee('%');

    $beatNovel = Novel::factory()->create();
    $beatBatch = outlineUiBatch($beatNovel);
    $beatFoundation = outlineUiFoundation($beatNovel, $beatBatch);
    $beatStructure = outlineUiStructure($beatNovel, $beatBatch, $beatFoundation, [
        ['key' => 'arc-01', 'title' => '主线'],
    ]);
    $arc = outlineUiArc($beatNovel, $beatBatch, $beatFoundation, $beatStructure, 'arc-01', []);
    $skeleton = outlineUiSkeleton($beatNovel, $beatBatch, $beatFoundation, $beatStructure, ['arc-01' => $arc], [
        ['key' => 'beat-01', 'title' => '入局'],
        ['key' => 'beat-02', 'title' => '反击'],
    ]);
    outlineUiDetail($beatNovel, $beatBatch, $beatFoundation, $skeleton, 'beat-01');

    Livewire::test(ManageNovelOutline::class, ['record' => $beatNovel->getRouteKey()])
        ->assertSee('主线节点细化')
        ->assertSee('1 / 2')
        ->assertSee('第一卷 · 主线 · 反击')
        ->assertDontSee('%');
});

test('outline page persists safe failure guidance and exposes technical detail only in the slide over', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Planning]);
    $batch = outlineUiBatch($novel);
    $rawError = 'Guzzle timeout in /vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:1425';
    $failed = outlineUiRun($novel, $batch, NovelOutlinePipeline::FOUNDATION_SCOPE, RunStatus::Failed, null, [
        'attempt' => 3,
        'error_code' => 'provider_timeout',
        'error_message' => $rawError,
        'error_retryable' => true,
        'error_metadata' => ['category' => 'external_temporary'],
    ]);
    $batch->update([
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
        'error_message' => $rawError,
        'error_retryable' => true,
        'error_metadata' => [
            'failed_scope' => NovelOutlinePipeline::FOUNDATION_SCOPE,
            'child_run_id' => $failed->getKey(),
            'auto_retry_exhausted' => true,
            'category' => 'external_temporary',
        ],
        'finished_at' => now(),
    ]);

    $component = Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
        ->assertSee('生成失败')
        ->assertSee('AI 服务响应超时，系统未收到完整结果。')
        ->assertSee('provider_timeout')
        ->assertSee('自动重试：已耗尽')
        ->assertSee('已成功且来源有效的 Artifact 会保留')
        ->assertActionVisible('resumeOutlineGeneration')
        ->assertActionExists('viewOutlineGenerationRuns', fn ($action): bool => $action->isModalSlideOver())
        ->mountAction('viewOutlineGenerationRuns')
        ->assertActionMounted('viewOutlineGenerationRuns');

    $modalContent = $component->instance()->getMountedAction()?->getModalContent()?->render();
    expect($modalContent)
        ->toContain($rawError)
        ->not->toContain('api_key')
        ->not->toContain('system_prompt');
});

test('outline page resumes through the domain action and dispatches only the earliest missing stage', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Planning]);
    $batch = outlineUiBatch($novel, RunStatus::Failed, [
        'error_code' => 'provider_timeout',
        'error_message' => 'request timed out',
        'error_retryable' => true,
        'error_metadata' => [
            'failed_scope' => NovelOutlinePipeline::FOUNDATION_SCOPE,
            'auto_retry_exhausted' => true,
            'category' => 'external_temporary',
        ],
    ]);

    Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
        ->assertActionVisible('resumeOutlineGeneration')
        ->callAction('resumeOutlineGeneration')
        ->assertNotified('AI 大纲生成已继续')
        ->assertSee('生成中')
        ->assertActionDisabled('generateOutlineCandidate');

    expect($batch->fresh()->status)->toBe(RunStatus::Running);
    Queue::assertPushed(GenerateNovelFoundationJob::class, 1);
    Queue::assertNotPushed(GenerateNovelOutlineJob::class);
});

test('outline failure messages distinguish structured output failures', function (string $code, string $message) {
    $novel = Novel::factory()->create();
    outlineUiBatch($novel, RunStatus::Failed, [
        'error_code' => $code,
        'error_message' => 'internal provider detail',
        'error_retryable' => false,
        'error_metadata' => [
            'failed_scope' => NovelOutlinePipeline::FOUNDATION_SCOPE,
            'category' => 'structured_output',
        ],
    ]);

    Livewire::test(ManageNovelOutline::class, ['record' => $novel->getRouteKey()])
        ->assertSee($message)
        ->assertSee($code)
        ->assertActionVisible('resumeOutlineGeneration');
})->with([
    'truncated' => ['outline_output_truncated', 'AI 返回的大纲内容被截断，未保存不完整结果。'],
    'schema' => ['outline_stage_schema_invalid', 'AI 返回的大纲格式不符合要求，未保存该结果。'],
]);
