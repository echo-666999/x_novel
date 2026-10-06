<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\UsageRecord;
use App\Services\NovelOutlinePipeline;
use App\Services\NovelOutlineProgressResolver;
use App\Services\NovelOutlineStageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** @return array<string, string> */
function ogrProgressPromptVersions(): array
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
function ogrProgressRoutes(): array
{
    $prompts = ogrProgressPromptVersions();

    return collect([
        'outline_foundation' => $prompts['foundation'],
        'outline_structure' => $prompts['structure'],
        'outline_arc_beats' => $prompts['arc_beats'],
        'outline_beat_detail' => $prompts['beat_detail'],
    ])->mapWithKeys(fn (string $prompt, string $stage): array => [$stage => [
        'provider' => 'openai',
        'model' => 'gpt-test',
        'reasoning_effort' => 'medium',
        'source' => 'database',
        'prompt_version' => $prompt,
        'request_budget' => [
            'output_tokens' => 8_000,
            'reasoning_reserve_tokens' => 4_000,
            'max_completion_tokens' => 12_000,
        ],
        'model_capacity' => [
            'provider' => 'openai',
            'model' => 'gpt-test',
            'context_window_tokens' => 100000,
            'max_output_tokens' => 10000,
        ],
    ]])->all();
}

function ogrProgressBatch(Novel $novel, RunStatus $status = RunStatus::Running, array $overrides = []): GenerationRun
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
                'outline_routes' => ogrProgressRoutes(),
                'prompt_versions' => ogrProgressPromptVersions(),
            ],
        ],
        'started_at' => $status === RunStatus::Queued ? null : now()->subSeconds(5),
        'finished_at' => in_array($status, [RunStatus::Failed, RunStatus::Succeeded, RunStatus::Cancelled], true) ? now() : null,
        ...$overrides,
    ]);
}

function ogrProgressRun(
    Novel $novel,
    GenerationRun $batch,
    string $scope,
    RunStatus $status = RunStatus::Succeeded,
    ?string $discriminator = null,
    array $overrides = [],
): GenerationRun {
    return GenerationRun::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => null,
        'scope_type' => $scope,
        'scope_id' => $batch->getKey(),
        'stage' => GenerationStage::ChapterPlanning,
        'status' => $status,
        'attempt' => 1,
        'prompt_version' => ogrProgressPromptVersions()[match ($scope) {
            NovelOutlinePipeline::FOUNDATION_SCOPE => 'foundation',
            NovelOutlinePipeline::STRUCTURE_SCOPE => 'structure',
            NovelOutlinePipeline::ARC_BEATS_SCOPE => 'arc_beats',
            NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE => 'skeleton_assembly',
            NovelOutlinePipeline::BEAT_DETAIL_SCOPE => 'beat_detail',
            NovelOutlinePipeline::FINALIZE_SCOPE => 'finalize',
            default => 'foundation',
        }],
        'provider' => in_array($scope, [NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE, NovelOutlinePipeline::FINALIZE_SCOPE], true) ? null : 'openai',
        'model_policy' => in_array($scope, [NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE, NovelOutlinePipeline::FINALIZE_SCOPE], true) ? null : 'gpt-test',
        'context_snapshot' => array_filter([
            'batch_run_id' => $batch->getKey(),
            'discriminator' => $discriminator,
        ], static fn (mixed $value): bool => $value !== null),
        'started_at' => now()->subSeconds(2),
        'finished_at' => $status === RunStatus::Running || $status === RunStatus::Queued ? null : now(),
        ...$overrides,
    ]);
}

function ogrProgressArtifact(
    GenerationRun $run,
    ArtifactType $type,
    array $data,
): GenerationArtifact {
    $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    return GenerationArtifact::factory()->create([
        'generation_run_id' => $run->getKey(),
        'type' => $type,
        'version' => 1,
        'content' => $encoded,
        'data' => $data,
        'checksum' => hash('sha256', $encoded),
    ]);
}

/** @return array{id: int, type: string, checksum: string} */
function ogrProgressReference(GenerationArtifact $artifact): array
{
    return [
        'id' => $artifact->getKey(),
        'type' => $artifact->type->value,
        'checksum' => $artifact->checksum,
    ];
}

function ogrProgressFoundation(Novel $novel, GenerationRun $batch): GenerationArtifact
{
    $run = ogrProgressRun($novel, $batch, NovelOutlinePipeline::FOUNDATION_SCOPE);

    return ogrProgressArtifact($run, ArtifactType::OutlineFoundation, [
        'batch_run_id' => $batch->getKey(),
        'stage' => NovelOutlinePipeline::FOUNDATION_SCOPE,
        'discriminator' => null,
        'source_artifacts' => [],
        'payload' => ['bible' => ['logline' => '测试故事']],
    ]);
}

/** @param array<int, array{key: string, title: string, type?: string}> $arcs */
function ogrProgressStructure(Novel $novel, GenerationRun $batch, GenerationArtifact $foundation, array $arcs): GenerationArtifact
{
    $run = ogrProgressRun($novel, $batch, NovelOutlinePipeline::STRUCTURE_SCOPE);

    return ogrProgressArtifact($run, ArtifactType::OutlineStructure, [
        'batch_run_id' => $batch->getKey(),
        'stage' => NovelOutlinePipeline::STRUCTURE_SCOPE,
        'discriminator' => null,
        'source_artifacts' => [ogrProgressReference($foundation)],
        'payload' => [
            'title' => '测试全书大纲',
            'summary' => '用于进度解析测试。',
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
    ]);
}

function ogrProgressArc(
    Novel $novel,
    GenerationRun $batch,
    GenerationArtifact $foundation,
    GenerationArtifact $structure,
    string $arcKey,
    array $beats,
    array $runOverrides = [],
): GenerationArtifact {
    $run = ogrProgressRun($novel, $batch, NovelOutlinePipeline::ARC_BEATS_SCOPE, RunStatus::Succeeded, $arcKey, $runOverrides);

    return ogrProgressArtifact($run, ArtifactType::OutlineArcBeats, [
        'batch_run_id' => $batch->getKey(),
        'stage' => NovelOutlinePipeline::ARC_BEATS_SCOPE,
        'discriminator' => $arcKey,
        'source_artifacts' => [ogrProgressReference($foundation), ogrProgressReference($structure)],
        'payload' => ['arc_key' => $arcKey, 'beats' => $beats],
    ]);
}

/** @param array<string, GenerationArtifact> $arcArtifacts */
function ogrProgressSkeleton(
    Novel $novel,
    GenerationRun $batch,
    GenerationArtifact $foundation,
    GenerationArtifact $structure,
    array $arcArtifacts,
    array $arcs,
): GenerationArtifact {
    $run = ogrProgressRun($novel, $batch, NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE);

    return ogrProgressArtifact($run, ArtifactType::OutlineSkeleton, [
        'batch_run_id' => $batch->getKey(),
        'stage' => NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE,
        'discriminator' => null,
        'source_artifacts' => [
            'foundation' => ogrProgressReference($foundation),
            'structure' => ogrProgressReference($structure),
            'arc_beats' => collect($arcArtifacts)->map(fn (GenerationArtifact $artifact): array => ogrProgressReference($artifact))->all(),
        ],
        'payload' => [
            'title' => '测试全书大纲',
            'summary' => '用于进度解析测试。',
            'volumes' => [[
                'key' => 'vol-01',
                'title' => '第一卷',
                'arcs' => $arcs,
            ]],
        ],
    ]);
}

function ogrProgressDetail(
    Novel $novel,
    GenerationRun $batch,
    GenerationArtifact $foundation,
    GenerationArtifact $skeleton,
    string $beatKey,
): GenerationArtifact {
    $run = ogrProgressRun($novel, $batch, NovelOutlinePipeline::BEAT_DETAIL_SCOPE, RunStatus::Succeeded, $beatKey);

    return ogrProgressArtifact($run, ArtifactType::OutlineBeatDetail, [
        'batch_run_id' => $batch->getKey(),
        'stage' => NovelOutlinePipeline::BEAT_DETAIL_SCOPE,
        'discriminator' => $beatKey,
        'source_artifacts' => [ogrProgressReference($foundation), ogrProgressReference($skeleton)],
        'payload' => ['beat_key' => $beatKey, 'milestones' => []],
    ]);
}

/**
 * @param  array<string, GenerationArtifact>  $arcArtifacts
 * @param  array<string, GenerationArtifact>  $detailArtifacts
 */
function ogrProgressBlueprint(
    Novel $novel,
    GenerationRun $batch,
    GenerationArtifact $foundation,
    GenerationArtifact $structure,
    array $arcArtifacts,
    GenerationArtifact $skeleton,
    array $detailArtifacts,
): GenerationArtifact {
    $run = ogrProgressRun($novel, $batch, NovelOutlinePipeline::FINALIZE_SCOPE);

    return ogrProgressArtifact($run, ArtifactType::OutlineBlueprint, [
        'lineage' => [
            'batch_run_id' => $batch->getKey(),
            'foundation' => ogrProgressReference($foundation),
            'structure' => ogrProgressReference($structure),
            'arc_beats' => collect($arcArtifacts)->map(fn (GenerationArtifact $artifact): array => ogrProgressReference($artifact))->all(),
            'skeleton' => ogrProgressReference($skeleton),
            'beat_details' => collect($detailArtifacts)->map(fn (GenerationArtifact $artifact): array => ogrProgressReference($artifact))->all(),
        ],
        'bible' => [],
        'outline' => data_get($skeleton->data, 'payload'),
    ]);
}

test('outline progress reports not started and queued states without invented totals', function () {
    $novel = Novel::factory()->create();
    $resolver = app(NovelOutlineProgressResolver::class);

    $notStarted = $resolver->resolve($novel);
    expect($notStarted->pageStatus)->toBe('not_started')
        ->and($notStarted->batchId)->toBeNull()
        ->and($notStarted->stages['arc_beats']->total)->toBeNull()
        ->and($notStarted->stages['beat_detail']->total)->toBeNull()
        ->and($notStarted->isActive())->toBeFalse();

    $batch = ogrProgressBatch($novel, RunStatus::Queued);
    $queued = $resolver->resolve($novel);
    expect($queued->batchId)->toBe($batch->getKey())
        ->and($queued->databaseStatus)->toBe('queued')
        ->and($queued->pageStatus)->toBe('queued')
        ->and($queued->currentStage)->toBe('foundation')
        ->and($queued->stages['arc_beats']->total)->toBeNull()
        ->and($queued->stages['beat_detail']->total)->toBeNull()
        ->and($queued->isActive())->toBeTrue();
});

test('outline progress exposes structure and arc denominators only after their sources exist', function () {
    $novel = Novel::factory()->create();
    $batch = ogrProgressBatch($novel);
    $foundation = ogrProgressFoundation($novel, $batch);
    $resolver = app(NovelOutlineProgressResolver::class);

    $beforeStructure = $resolver->resolve($novel);
    expect($beforeStructure->pageStatus)->toBe('running')
        ->and($beforeStructure->currentStage)->toBe('structure')
        ->and($beforeStructure->stages['arc_beats']->total)->toBeNull()
        ->and($beforeStructure->stages['beat_detail']->total)->toBeNull();

    $arcs = [
        ['key' => 'arc-01', 'title' => '主线'],
        ['key' => 'arc-02', 'title' => '支线', 'type' => 'subplot'],
    ];
    $structure = ogrProgressStructure($novel, $batch, $foundation, $arcs);
    ogrProgressArc($novel, $batch, $foundation, $structure, 'arc-01', [
        ['key' => 'beat-01', 'title' => '起点'],
    ]);

    $progress = $resolver->resolve($novel);
    expect($progress->currentStage)->toBe('arc_beats')
        ->and($progress->currentItemKey)->toBe('arc-02')
        ->and($progress->currentItemLabel)->toBe('第一卷 · 支线')
        ->and($progress->stages['arc_beats']->completed)->toBe(1)
        ->and($progress->stages['arc_beats']->total)->toBe(2)
        ->and($progress->stages['beat_detail']->total)->toBeNull();
});

test('outline progress counts only main beat details after deterministic skeleton assembly', function () {
    $novel = Novel::factory()->create();
    $batch = ogrProgressBatch($novel);
    $foundation = ogrProgressFoundation($novel, $batch);
    $definitions = [
        ['key' => 'arc-01', 'title' => '主线', 'type' => 'main'],
        ['key' => 'arc-02', 'title' => '支线', 'type' => 'subplot'],
    ];
    $structure = ogrProgressStructure($novel, $batch, $foundation, $definitions);
    $arcArtifacts = [
        'arc-01' => ogrProgressArc($novel, $batch, $foundation, $structure, 'arc-01', []),
        'arc-02' => ogrProgressArc($novel, $batch, $foundation, $structure, 'arc-02', []),
    ];
    $skeleton = ogrProgressSkeleton($novel, $batch, $foundation, $structure, $arcArtifacts, [
        ['key' => 'arc-01', 'title' => '主线', 'type' => 'main', 'beats' => [
            ['key' => 'beat-01', 'title' => '起点'],
            ['key' => 'beat-02', 'title' => '转折'],
        ]],
        ['key' => 'arc-02', 'title' => '支线', 'type' => 'subplot', 'beats' => [
            ['key' => 'beat-side', 'title' => '支线节点'],
        ]],
    ]);
    ogrProgressDetail($novel, $batch, $foundation, $skeleton, 'beat-01');

    $progress = app(NovelOutlineProgressResolver::class)->resolve($novel);
    expect($progress->currentStage)->toBe('beat_detail')
        ->and($progress->currentItemKey)->toBe('beat-02')
        ->and($progress->currentItemLabel)->toBe('第一卷 · 主线 · 转折')
        ->and($progress->latestAttempt)->toBeNull()
        ->and($progress->stages['beat_detail']->completed)->toBe(1)
        ->and($progress->stages['beat_detail']->total)->toBe(2);
});

test('outline progress derives a discriminator-local attempt for legacy globally numbered runs', function () {
    $novel = Novel::factory()->create();
    $batch = ogrProgressBatch($novel);
    $foundation = ogrProgressFoundation($novel, $batch);
    $structure = ogrProgressStructure($novel, $batch, $foundation, [
        ['key' => 'arc-01', 'title' => '主线'],
    ]);
    $arc = ogrProgressArc($novel, $batch, $foundation, $structure, 'arc-01', []);
    $skeleton = ogrProgressSkeleton($novel, $batch, $foundation, $structure, ['arc-01' => $arc], [[
        'key' => 'arc-01', 'title' => '主线', 'type' => 'main', 'beats' => [
            ['key' => 'beat-01', 'title' => '起点'],
            ['key' => 'beat-02', 'title' => '转折'],
        ],
    ]]);
    ogrProgressDetail($novel, $batch, $foundation, $skeleton, 'beat-01');
    $current = ogrProgressRun($novel, $batch, NovelOutlinePipeline::BEAT_DETAIL_SCOPE, RunStatus::Running, 'beat-02', [
        'attempt' => 44,
    ]);

    $progress = app(NovelOutlineProgressResolver::class)->resolve($novel);
    $currentReference = collect($progress->runs)->firstWhere('id', $current->getKey());

    expect($progress->currentItemKey)->toBe('beat-02')
        ->and($progress->latestAttempt)->toBe(1)
        ->and(data_get($currentReference, 'attempt'))->toBe(1);
});

test('a retryable current child failure derives retrying and keeps raw provider detail technical', function () {
    $novel = Novel::factory()->create();
    $batch = ogrProgressBatch($novel);
    $foundation = ogrProgressFoundation($novel, $batch);
    $structure = ogrProgressStructure($novel, $batch, $foundation, [
        ['key' => 'arc-01', 'title' => '主线'],
    ]);
    $rawError = 'Guzzle timeout in /vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:1425';
    ogrProgressRun($novel, $batch, NovelOutlinePipeline::ARC_BEATS_SCOPE, RunStatus::Failed, 'arc-01', [
        'attempt' => 1,
        'error_code' => 'provider_timeout',
        'error_message' => 'first timeout',
        'error_retryable' => true,
    ]);
    $failed = ogrProgressRun($novel, $batch, NovelOutlinePipeline::ARC_BEATS_SCOPE, RunStatus::Failed, 'arc-01', [
        'attempt' => 2,
        'error_code' => 'provider_timeout',
        'error_message' => $rawError,
        'error_retryable' => true,
        'error_metadata' => ['http_status' => 504],
    ]);
    UsageRecord::factory()->create([
        'generation_run_id' => $failed->getKey(),
        'novel_id' => $novel->getKey(),
        'input_tokens' => 120,
        'output_tokens' => 0,
        'estimated_cost' => 0,
    ]);
    if (Schema::hasTable('failed_jobs')) {
        DB::table('failed_jobs')->delete();
    }

    $progress = app(NovelOutlineProgressResolver::class)->resolve($novel);
    $failedReference = collect($progress->runs)->firstWhere('id', $failed->getKey());
    expect($progress->pageStatus)->toBe('retrying')
        ->and($progress->currentStage)->toBe('arc_beats')
        ->and($progress->latestAttempt)->toBe(2)
        ->and($progress->errorCode)->toBe('provider_timeout')
        ->and($progress->errorMessage)->toBe('AI 服务响应超时，系统未收到完整结果。')
        ->and($progress->errorMessage)->not->toContain('/vendor/')
        ->and($progress->technicalError)->toBe($rawError)
        ->and(data_get($failedReference, 'usage.input_tokens'))->toBe(120);
});

test('terminal batch failure reports failed scope label and exact resume capability', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Planning]);
    $batch = ogrProgressBatch($novel, RunStatus::Running);
    $foundation = ogrProgressFoundation($novel, $batch);
    ogrProgressStructure($novel, $batch, $foundation, [
        ['key' => 'arc-01', 'title' => '主线'],
    ]);
    $failed = ogrProgressRun($novel, $batch, NovelOutlinePipeline::ARC_BEATS_SCOPE, RunStatus::Failed, 'arc-01', [
        'attempt' => 3,
        'error_code' => 'provider_timeout',
        'error_message' => 'request timed out',
        'error_retryable' => true,
        'error_metadata' => ['category' => 'external_temporary'],
    ]);
    $batch->update([
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
        'error_message' => 'request timed out',
        'error_retryable' => true,
        'error_metadata' => [
            'failed_scope' => NovelOutlinePipeline::ARC_BEATS_SCOPE,
            'discriminator' => 'arc-01',
            'child_run_id' => $failed->getKey(),
            'auto_retry_exhausted' => true,
            'category' => 'external_temporary',
        ],
        'finished_at' => now(),
    ]);

    $progress = app(NovelOutlineProgressResolver::class)->resolve($novel);
    expect($progress->databaseStatus)->toBe('failed')
        ->and($progress->pageStatus)->toBe('failed')
        ->and($progress->currentStage)->toBe('arc_beats')
        ->and($progress->currentItemKey)->toBe('arc-01')
        ->and($progress->currentItemLabel)->toBe('第一卷 · 主线')
        ->and($progress->currentRunId)->toBe($failed->getKey())
        ->and($progress->errorMetadata['auto_retry_exhausted'])->toBeTrue()
        ->and($progress->canResume)->toBeTrue();
    Queue::assertNothingPushed();

    $batch->update(['context_snapshot' => [
        ...$batch->context_snapshot,
        'generation_preferences' => [
            ...data_get($batch->context_snapshot, 'generation_preferences'),
            'prompt_versions' => [...ogrProgressPromptVersions(), 'finalize' => 'stale-finalize'],
        ],
    ]]);
    expect(app(NovelOutlineProgressResolver::class)->resolve($novel)->canResume)->toBeFalse();
});

test('an older failed attempt cannot override a later successful artifact', function () {
    $novel = Novel::factory()->create();
    $batch = ogrProgressBatch($novel);
    $foundation = ogrProgressFoundation($novel, $batch);
    $structure = ogrProgressStructure($novel, $batch, $foundation, [
        ['key' => 'arc-01', 'title' => '主线'],
        ['key' => 'arc-02', 'title' => '第二线'],
    ]);
    ogrProgressRun($novel, $batch, NovelOutlinePipeline::ARC_BEATS_SCOPE, RunStatus::Failed, 'arc-01', [
        'attempt' => 1,
        'error_code' => 'provider_timeout',
        'error_message' => 'old failure',
        'error_retryable' => true,
    ]);
    ogrProgressArc($novel, $batch, $foundation, $structure, 'arc-01', [], ['attempt' => 2]);

    $progress = app(NovelOutlineProgressResolver::class)->resolve($novel);
    expect($progress->pageStatus)->toBe('running')
        ->and($progress->currentItemKey)->toBe('arc-02')
        ->and($progress->stages['arc_beats']->completed)->toBe(1)
        ->and($progress->errorCode)->toBeNull();
});

test('a completed source chain exposes successful stage and trace references', function () {
    $novel = Novel::factory()->create();
    $batch = ogrProgressBatch($novel);
    $foundation = ogrProgressFoundation($novel, $batch);
    $structure = ogrProgressStructure($novel, $batch, $foundation, [
        ['key' => 'arc-01', 'title' => '主线'],
    ]);
    $arcArtifacts = [
        'arc-01' => ogrProgressArc($novel, $batch, $foundation, $structure, 'arc-01', []),
    ];
    $skeleton = ogrProgressSkeleton($novel, $batch, $foundation, $structure, $arcArtifacts, [[
        'key' => 'arc-01',
        'title' => '主线',
        'type' => 'main',
        'beats' => [['key' => 'beat-01', 'title' => '起点']],
    ]]);
    $detailArtifacts = [
        'beat-01' => ogrProgressDetail($novel, $batch, $foundation, $skeleton, 'beat-01'),
    ];
    $blueprint = ogrProgressBlueprint($novel, $batch, $foundation, $structure, $arcArtifacts, $skeleton, $detailArtifacts);
    $batch->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

    $progress = app(NovelOutlineProgressResolver::class)->resolve($novel);
    expect($progress->pageStatus)->toBe('succeeded')
        ->and($progress->currentStage)->toBe('finalize')
        ->and($progress->stages['finalize']->status)->toBe('succeeded')
        ->and($progress->stages['finalize']->artifactIds)->toBe([$blueprint->getKey()])
        ->and($progress->provider)->toBeNull()
        ->and($progress->model)->toBeNull()
        ->and($progress->reasoningEffort)->toBeNull()
        ->and($progress->promptVersions)->toBe(ogrProgressPromptVersions())
        ->and(collect($progress->runs)->pluck('id'))->toContain($batch->getKey())
        ->and(collect($progress->artifacts)->pluck('id'))->toContain($blueprint->getKey());
});

test('succeeded and cancelled batches expose their terminal page states', function (RunStatus $status, string $expected) {
    $novel = Novel::factory()->create();
    ogrProgressBatch($novel, $status);

    $progress = app(NovelOutlineProgressResolver::class)->resolve($novel);
    expect($progress->pageStatus)->toBe($expected)
        ->and($progress->isActive())->toBeFalse();
})->with([
    'succeeded' => [RunStatus::Succeeded, 'succeeded'],
    'cancelled' => [RunStatus::Cancelled, 'cancelled'],
]);

test('outline progress queries remain bounded as arc count grows and do not read failed jobs', function () {
    $resolver = app(NovelOutlineProgressResolver::class);
    $queryCounts = [];

    foreach ([1, 12] as $arcCount) {
        $novel = Novel::factory()->create();
        $batch = ogrProgressBatch($novel);
        $foundation = ogrProgressFoundation($novel, $batch);
        $definitions = collect(range(1, $arcCount))->map(fn (int $number): array => [
            'key' => sprintf('arc-%02d', $number),
            'title' => "故事线 {$number}",
        ])->all();
        $structure = ogrProgressStructure($novel, $batch, $foundation, $definitions);
        foreach ($definitions as $definition) {
            ogrProgressArc($novel, $batch, $foundation, $structure, $definition['key'], []);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $before = [
            GenerationRun::query()->count(),
            GenerationArtifact::query()->count(),
            UsageRecord::query()->count(),
        ];
        DB::flushQueryLog();
        $progress = $resolver->resolve($novel);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $after = [
            GenerationRun::query()->count(),
            GenerationArtifact::query()->count(),
            UsageRecord::query()->count(),
        ];

        expect($progress->stages['arc_beats']->completed)->toBe($arcCount)
            ->and($progress->stages['arc_beats']->total)->toBe($arcCount)
            ->and($after)->toBe($before)
            ->and(collect($queries)->pluck('query')->implode(' '))->not->toContain('failed_jobs');
        $queryCounts[] = count($queries);
    }

    expect($queryCounts[0])->toBe($queryCounts[1])
        ->and($queryCounts[0])->toBeLessThanOrEqual(5);
});
