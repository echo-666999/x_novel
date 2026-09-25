<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\AssembleChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Models\StoryStateVersion;
use App\Services\ChapterAssembler;
use App\Services\ChapterAssemblyPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function chapterAssemblyFixture(int $sceneCount = 2, array $firstSceneCoverageOverrides = []): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create(['tone' => '克制悬疑']);
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 20,
        'status' => ChapterStatus::Generating,
    ]);
    ChapterPlan::factory()->for($chapter)->create([
        'tone' => '紧张',
        'scene_plans' => [],
        'target_words' => 7,
    ]);
    $scenes = collect(range(1, $sceneCount))->map(function (int $sequence) use ($chapter, $novel, $firstSceneCoverageOverrides): Scene {
        $scene = Scene::factory()->for($chapter)->create([
            'sequence' => $sequence,
            'status' => SceneStatus::Draft,
        ]);
        $run = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
            'stage' => GenerationStage::SceneGeneration,
            'status' => RunStatus::Succeeded,
        ]);
        $content = "Scene {$sequence} 正文";
        $fulfilled = ['status' => 'fulfilled', 'evidence' => $content];
        $selfCheck = [
            'goal' => $fulfilled,
            'conflict' => $fulfilled,
            'turn' => $fulfilled,
            'outcome' => $fulfilled,
            ...($sequence === 1 ? $firstSceneCoverageOverrides : []),
        ];
        $artifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::SceneDraft,
            'content' => $content,
            'data' => ['self_check' => $selfCheck, 'foreshadowing_coverage' => []],
            'checksum' => hash('sha256', $content),
        ]);
        $scene->update(['current_artifact_id' => $artifact->getKey()]);

        return $scene->fresh();
    });

    return compact('novel', 'chapter', 'scenes');
}

function assemblyCoverage(string $content, ?array $overrides = null): array
{
    $fulfilled = ['status' => 'fulfilled', 'evidence' => $content];

    return Scene::query()->orderBy('sequence')->get()->map(function (Scene $scene) use ($fulfilled, $overrides): array {
        $coverage = [
            'scene_id' => $scene->getKey(),
            'goal' => $fulfilled,
            'conflict' => $fulfilled,
            'turn' => $fulfilled,
            'outcome' => $fulfilled,
            'foreshadowing_coverage' => [],
        ];

        return $scene->getKey() === data_get($overrides, 'scene_id')
            ? [...$coverage, ...($overrides['coverage'] ?? [])]
            : $coverage;
    })->values()->all();
}

function assemblyResponse(string $content = '完整章节正文', ?array $coverage = null, array $introducedMajorFacts = []): AiResponse
{
    $payload = [
        'content' => $content,
        'scene_coverage' => $coverage ?? assemblyCoverage($content),
        'introduced_major_facts' => $introducedMajorFacts,
    ];

    return new AiResponse(
        content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: 300,
        outputTokens: 600,
        cachedTokens: 0,
        latencyMs: 500,
        providerRequestId: 'assembly-request',
        model: 'assembler-test',
    );
}

function assemblyCoverageRepairResponse(array $coverage): AiResponse
{
    return new AiResponse(
        content: json_encode($coverage, JSON_UNESCAPED_UNICODE),
        structuredData: $coverage,
        inputTokens: 50,
        outputTokens: 50,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'assembly-coverage-repair-request',
        model: 'assembler-test',
    );
}

function truncatedAssemblyResponse(int $outputTokens): AiResponse
{
    return new AiResponse(
        content: '{"content":"partial',
        structuredData: null,
        inputTokens: 300,
        outputTokens: $outputTokens,
        cachedTokens: 0,
        latencyMs: 500,
        providerRequestId: 'assembly-truncated-request',
        model: 'assembler-test',
        metadata: ['finish_reason' => 'length', 'refusal' => null],
    );
}

function assemblyForeshadowingAction(array $fixture, int $sceneSequence = 1): Foreshadowing
{
    $foreshadowing = Foreshadowing::factory()->for($fixture['novel'])->create([
        'title' => '染血地图',
        'promised_payoff' => '地图最终指向潮汐门。',
        'status' => ForeshadowingStatus::Planted,
        'due_from_chapter' => 18,
        'due_to_chapter' => 20,
    ]);
    $fixture['chapter']->latestPlan->update(['foreshadowing_actions' => [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'pay_off',
        'target_scene_sequence' => $sceneSequence,
        'acceptance_criteria' => '正文明确地图指向潮汐门。',
        'reason' => null,
    ]]]);

    return $foreshadowing;
}

function setSourceForeshadowingCoverage(Scene $scene, array $coverage): void
{
    $source = $scene->currentArtifact;
    $run = GenerationRun::factory()->for($scene->chapter->novel)->for($scene->chapter)->for($scene)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::SceneDraft,
        'version' => $source->version + 1,
        'content' => $source->content,
        'data' => [...$source->data, 'foreshadowing_coverage' => $coverage],
        'checksum' => $source->checksum,
    ]);
    $scene->update(['current_artifact_id' => $artifact->getKey()]);
    $scene->setRelation('currentArtifact', $artifact->setRelation('generationRun', $run));
}

test('assembler combines multiple scene drafts in sequence into a chapter draft', function () {
    $fixture = chapterAssemblyFixture(3);
    $fixture['chapter']->latestPlan->update(['target_words' => 12]);
    $fake = (new FakeAiProvider)->enqueue(assemblyResponse('第一幕。第二幕。第三幕。'));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());
    $run = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->sole();
    $prompt = $fake->requests()[0]->prompt;

    expect($artifact->type)->toBe(ArtifactType::ChapterDraft)
        ->and($artifact->version)->toBe(1)
        ->and($artifact->content)->toBe('第一幕。第二幕。第三幕。')
        ->and($artifact->data['scene_coverage'])->toHaveCount(3)
        ->and($artifact->data['plan_findings'])->toBe([])
        ->and($artifact->data['introduced_major_facts'])->toBe([])
        ->and($artifact->data['ordered_scene_checksums'])->toBe($fixture['scenes']->pluck('currentArtifact.checksum')->all())
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->bible_version)->toBe(1)
        ->and(data_get($run->context_snapshot, 'style_contract_checksum'))->toBe(data_get($run->context_snapshot, 'l4.checksum'))
        ->and(data_get($run->context_snapshot, 'foreshadowing_contract_checksum'))->toBe(data_get($run->context_snapshot, 'foreshadowing_contract.checksum'))
        ->and(data_get($run->context_snapshot, 'l4.primary_style.name'))->toBe('通俗爽快')
        ->and($run->context_snapshot)->not->toHaveKeys(['style_constraints'])
        ->and(data_get($run->context_snapshot, 'writing_constraints'))->not->toHaveKey('style_profile')
        ->and($run->context_snapshot['ordered_scene_checksums'])->toHaveCount(3)
        ->and(data_get($run->context_snapshot, 'writing_constraints.chapter_target_words'))->toBe($fixture['chapter']->latestPlan->target_words)
        ->and(data_get($run->context_snapshot, 'writing_constraints.chapter_minimum_words'))->toBe(11)
        ->and(data_get($run->context_snapshot, 'writing_constraints.chapter_maximum_words'))->toBe(14)
        ->and($artifact->data['word_count'])->toBe(mb_strlen('第一幕。第二幕。第三幕。'))
        ->and($fake->requests()[0]->systemPrompt)->toContain('不得把正文压缩成摘要')
        ->and($fake->requests()[0]->systemPrompt)->toContain('previous_chapter_ending')
        ->and($fake->requests()[0]->systemPrompt)->toContain('foreshadowing_contract 是本章冻结的唯一伏笔动作契约')
        ->and($fake->requests()[0]->systemPrompt)->toContain('正文必须像人物正在经历事件')
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.scene_coverage.items.required'))->toBe(['scene_id', 'goal', 'conflict', 'turn', 'outcome', 'foreshadowing_coverage'])
        ->and(mb_strpos($prompt, 'Scene 1 正文'))->toBeLessThan(mb_strpos($prompt, 'Scene 2 正文'))
        ->and(mb_strpos($prompt, 'Scene 2 正文'))->toBeLessThan(mb_strpos($prompt, 'Scene 3 正文'));
});

test('assembly coverage creates a localized finding for a missing or contradicted outcome', function (string $status, string $code, ?string $evidence) {
    $fixture = chapterAssemblyFixture(1);
    $content = '林舟在门前停下，没有进入灯塔。';
    $fixture['chapter']->latestPlan->update(['target_words' => mb_strlen($content)]);
    $coverage = assemblyCoverage($content, [
        'scene_id' => $fixture['scenes']->first()->getKey(),
        'coverage' => ['outcome' => ['status' => $status, 'evidence' => $evidence]],
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(assemblyResponse($content, $coverage)));

    $artifact = app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());
    $finding = data_get($artifact->data, 'plan_findings.0');

    expect($finding['code'])->toBe($code)
        ->and($finding['scene_id'])->toBe($fixture['scenes']->first()->getKey())
        ->and($finding['plan_element'])->toBe('outcome')
        ->and($finding['coverage_status'])->toBe($status)
        ->and($finding['source'])->toBe('assembly_coverage');
})->with([
    'missing outcome' => ['missing', 'SCENE_PLAN_COVERAGE_MISSING', null],
    'contradicted outcome' => ['contradicted', 'SCENE_PLAN_COVERAGE_CONTRADICTED', '没有进入灯塔'],
]);

test('assembly rejects foreign scene references', function () {
    $fixture = chapterAssemblyFixture(1);
    $content = '林舟进入灯塔。';
    $fixture['chapter']->latestPlan->update(['target_words' => mb_strlen($content)]);
    $valid = assemblyCoverage($content);
    $coverage = [[...$valid[0], 'scene_id' => Scene::factory()->create()->getKey()]];
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(assemblyResponse($content, $coverage)));

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, '必须按顺序且不重复地引用本章全部 Scene');

    expect(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0);
});

test('assembly detects deletion of the only fulfilled foreshadowing evidence', function () {
    $fixture = chapterAssemblyFixture(1);
    $foreshadowing = assemblyForeshadowingAction($fixture);
    setSourceForeshadowingCoverage($fixture['scenes']->first(), [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'pay_off',
        'status' => 'fulfilled',
        'evidence' => '地图指向潮汐门',
    ]]);
    $content = '林舟收起地图，继续赶路。';
    $fixture['chapter']->latestPlan->update(['target_words' => mb_strlen($content)]);
    $coverage = assemblyCoverage($content);
    $coverage[0]['foreshadowing_coverage'] = [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'pay_off',
        'status' => 'missing',
        'evidence' => null,
    ]];
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(assemblyResponse($content, $coverage)));

    $artifact = app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());
    $finding = collect($artifact->data['plan_findings'])->firstWhere('code', 'FORESHADOWING_COVERAGE_MISSING');

    expect($finding)->not->toBeNull()
        ->and($finding['foreshadowing_id'])->toBe($foreshadowing->getKey())
        ->and($finding['source'])->toBe('assembly_foreshadowing_coverage');
});

test('assembly cannot upgrade a missing scene foreshadowing action to fulfilled', function () {
    $fixture = chapterAssemblyFixture(1);
    $foreshadowing = assemblyForeshadowingAction($fixture);
    setSourceForeshadowingCoverage($fixture['scenes']->first(), [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'pay_off',
        'status' => 'missing',
        'evidence' => null,
    ]]);
    $content = '林舟确认地图指向潮汐门。';
    $fixture['chapter']->latestPlan->update(['target_words' => mb_strlen($content)]);
    $coverage = assemblyCoverage($content);
    $coverage[0]['foreshadowing_coverage'] = [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'pay_off',
        'status' => 'fulfilled',
        'evidence' => '地图指向潮汐门',
    ]];
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(assemblyResponse($content, $coverage)));

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, '不得把 Scene Draft 中缺失或反转的伏笔动作改写为 fulfilled');

    expect(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0);
});

test('assembly aggregates foreshadowing coverage by target scene', function () {
    $fixture = chapterAssemblyFixture(2);
    $first = assemblyForeshadowingAction($fixture, 1);
    $second = Foreshadowing::factory()->for($fixture['novel'])->create([
        'status' => ForeshadowingStatus::Planted,
        'due_from_chapter' => 18,
        'due_to_chapter' => 20,
    ]);
    $fixture['chapter']->latestPlan->update(['foreshadowing_actions' => [
        ['foreshadowing_id' => $first->getKey(), 'action' => 'pay_off', 'target_scene_sequence' => 1, 'acceptance_criteria' => '第一场兑现。', 'reason' => null],
        ['foreshadowing_id' => $second->getKey(), 'action' => 'reinforce', 'target_scene_sequence' => 2, 'acceptance_criteria' => '第二场强化。', 'reason' => null],
    ]]);
    $content = '第一场兑现地图秘密。第二场强化旧钟异响。';
    $fixture['chapter']->latestPlan->update(['target_words' => mb_strlen($content)]);
    $firstCoverage = [['foreshadowing_id' => $first->getKey(), 'action' => 'pay_off', 'status' => 'fulfilled', 'evidence' => '第一场兑现地图秘密']];
    $secondCoverage = [['foreshadowing_id' => $second->getKey(), 'action' => 'reinforce', 'status' => 'fulfilled', 'evidence' => '第二场强化旧钟异响']];
    setSourceForeshadowingCoverage($fixture['scenes'][0], $firstCoverage);
    setSourceForeshadowingCoverage($fixture['scenes'][1], $secondCoverage);
    $coverage = assemblyCoverage($content);
    $coverage[0]['foreshadowing_coverage'] = $firstCoverage;
    $coverage[1]['foreshadowing_coverage'] = $secondCoverage;
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(assemblyResponse($content, $coverage)));

    $artifact = app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());

    expect(data_get($artifact->data, 'scene_coverage.0.foreshadowing_coverage.0.foreshadowing_id'))->toBe($first->getKey())
        ->and(data_get($artifact->data, 'scene_coverage.1.foreshadowing_coverage.0.foreshadowing_id'))->toBe($second->getKey())
        ->and($artifact->data['plan_findings'])->toBe([]);
});

test('assembly payload rejects evidence outside the final chapter', function () {
    $fixture = chapterAssemblyFixture(1);
    $content = '林舟进入灯塔。';
    $coverage = assemblyCoverage($content);
    $coverage[0]['outcome'] = ['status' => 'fulfilled', 'evidence' => '正文中不存在的句子'];

    expect(fn () => ChapterAssemblyPayload::validate([
        'content' => $content,
        'scene_coverage' => $coverage,
        'introduced_major_facts' => [],
    ], $fixture['chapter'], $fixture['scenes']->pluck('currentArtifact')))
        ->toThrow(ValidationException::class, '必须逐字来自当前正文');
});

test('assembler repairs invalid coverage evidence without rewriting the chapter', function () {
    $fixture = chapterAssemblyFixture(1);
    $content = '林舟进入灯塔。';
    $fixture['chapter']->latestPlan->update(['target_words' => mb_strlen($content)]);
    $invalid = assemblyCoverage($content);
    $invalid[0]['outcome'] = ['status' => 'fulfilled', 'evidence' => '他进入了灯塔'];
    $validCoverage = collect(assemblyCoverage($content)[0])->except(['scene_id', 'foreshadowing_coverage'])->all();
    $fake = (new FakeAiProvider)
        ->enqueue(assemblyResponse($content, $invalid))
        ->enqueue(assemblyCoverageRepairResponse($validCoverage));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());

    expect($artifact?->content)->toBe($content)
        ->and(data_get($artifact?->data, 'scene_coverage.0.outcome.evidence'))->toBe($content)
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->promptVersion)->toBe('coverage-evidence-repair-v1')
        ->and(data_get($fake->requests()[1]->metadata, 'coverage_path'))->toBe('scene_coverage.0');
});

test('assembly cannot invent a missing scene outcome or declare new major facts', function (bool $upgradeCoverage) {
    $fixture = chapterAssemblyFixture(1, [
        'outcome' => ['status' => 'missing', 'evidence' => null],
    ]);
    $content = '林舟突然获得了新能力。';
    $fixture['chapter']->latestPlan->update(['target_words' => mb_strlen($content)]);
    $response = $upgradeCoverage
        ? assemblyResponse($content)
        : assemblyResponse($content, introducedMajorFacts: ['林舟获得新能力']);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue($response));

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, $upgradeCoverage
            ? '不得把 Scene Draft 中缺失或反转的计划项改写为 fulfilled'
            : '不得新增重大事实');

    expect(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0);
})->with([
    'coverage upgrade' => [true],
    'declared major fact' => [false],
]);

test('assembly refuses to run until every scene has a successful draft', function () {
    $fixture = chapterAssemblyFixture();
    $fixture['scenes']->last()->update(['status' => SceneStatus::Failed, 'current_artifact_id' => null]);
    $fake = (new FakeAiProvider)->enqueue(assemblyResponse());
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, 'Scene 2 尚未成功');

    expect($fake->requests())->toHaveCount(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(0);
});

test('duplicate assembly delivery reuses the successful artifact', function () {
    $fixture = chapterAssemblyFixture();
    $fake = (new FakeAiProvider)->enqueue(assemblyResponse());
    app()->instance(AiProvider::class, $fake);
    $assembler = app(ChapterAssembler::class);

    $first = $assembler->assemble($fixture['chapter']->getKey());
    $second = $assembler->assemble($fixture['chapter']->getKey());

    expect($second?->is($first))->toBeTrue()
        ->and($fake->requests())->toHaveCount(1)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(1);
});

test('regeneration creates a new immutable chapter draft version', function () {
    $fixture = chapterAssemblyFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(assemblyResponse('Draft v1'))
        ->enqueue(assemblyResponse('Draft v2'));
    app()->instance(AiProvider::class, $fake);
    $assembler = app(ChapterAssembler::class);

    $first = $assembler->assemble($fixture['chapter']->getKey());
    $second = $assembler->assemble($fixture['chapter']->getKey(), true);

    expect($first->fresh()->content)->toBe('Draft v1')
        ->and($first->version)->toBe(1)
        ->and($second->version)->toBe(2)
        ->and($second->content)->toBe('Draft v2');
});

test('retrying assembly recovers a blocked chapter before starting a new run', function () {
    $fixture = chapterAssemblyFixture();
    $fixture['chapter']->update(['status' => ChapterStatus::Blocked]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(assemblyResponse('恢复后的章节正文')));

    app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey(), true);

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->sole()->status)->toBe(RunStatus::Succeeded);
});

test('retryable assembly failures are recorded and retry from assembly only', function () {
    Queue::fake();
    $fixture = chapterAssemblyFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(new AiProviderException('provider_timeout', 'timeout', true))
        ->enqueue(assemblyResponse('重试后完整正文'));
    app()->instance(AiProvider::class, $fake);
    $job = new AssembleChapterJob($fixture['chapter']->getKey());

    expect(fn () => $job->handle(app(ChapterAssembler::class)))
        ->toThrow(AiProviderException::class, 'timeout');

    $job->handle(app(ChapterAssembler::class));

    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(2)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->latest('id')->first()->status)->toBe(RunStatus::Succeeded)
        ->and($fixture['scenes']->every(fn (Scene $scene): bool => $scene->fresh()->status === SceneStatus::Draft))->toBeTrue();
});

test('assembly truncation retries increase and freeze the output budget', function () {
    config()->set('generation.assembly_max_output_tokens', 12_000);
    config()->set('generation.assembly_retry_max_output_tokens', 16_000);
    config()->set('generation.assembly_final_retry_max_output_tokens', 24_000);
    $fixture = chapterAssemblyFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(truncatedAssemblyResponse(12_000))
        ->enqueue(truncatedAssemblyResponse(16_000))
        ->enqueue(assemblyResponse('重试后完整正文'));
    app()->instance(AiProvider::class, $fake);
    $assembler = app(ChapterAssembler::class);

    expect(fn () => $assembler->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '输出 Token 用尽');
    expect(fn () => $assembler->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '输出 Token 用尽');
    $artifact = $assembler->assemble($fixture['chapter']->getKey());

    $runs = $fixture['chapter']->generationRuns()
        ->where('stage', GenerationStage::ChapterAssembly)
        ->orderBy('id')
        ->get();
    expect($artifact?->content)->toBe('重试后完整正文')
        ->and($fake->requests())->toHaveCount(3)
        ->and($fake->requests()[0]->maxTokens)->toBe(12_000)
        ->and($fake->requests()[1]->maxTokens)->toBe(16_000)
        ->and($fake->requests()[2]->maxTokens)->toBe(24_000)
        ->and($runs->pluck('input_hash')->unique())->toHaveCount(1)
        ->and($runs->map(fn (GenerationRun $run): int => (int) data_get($run->context_snapshot, 'generation_preferences.assembly_retry_ordinal'))->all())->toBe([1, 2, 3])
        ->and($runs->map(fn (GenerationRun $run): int => (int) data_get($run->context_snapshot, 'generation_preferences.max_completion_tokens'))->all())->toBe([12_000, 16_000, 24_000]);
});

test('assembly does not repeat a request after the final output budget was truncated', function () {
    config()->set('generation.assembly_max_output_tokens', 12_000);
    config()->set('generation.assembly_retry_max_output_tokens', 16_000);
    config()->set('generation.assembly_final_retry_max_output_tokens', 24_000);
    $fixture = chapterAssemblyFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(truncatedAssemblyResponse(12_000))
        ->enqueue(truncatedAssemblyResponse(16_000))
        ->enqueue(truncatedAssemblyResponse(24_000))
        ->enqueue(assemblyResponse('不应被调用'));
    app()->instance(AiProvider::class, $fake);
    $assembler = app(ChapterAssembler::class);

    foreach ([12_000, 16_000, 24_000] as $budget) {
        try {
            $assembler->assemble($fixture['chapter']->getKey());
            $this->fail("Expected truncation at {$budget} tokens.");
        } catch (AiProviderException $exception) {
            expect($exception->errorCode)->toBe('assembly_output_truncated');
        }
    }

    try {
        $assembler->assemble($fixture['chapter']->getKey());
        $this->fail('Expected exhausted assembly output budget.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('assembly_output_budget_exhausted')
            ->and($exception->retryable)->toBeFalse();
    }

    expect($fake->requests())->toHaveCount(3)
        ->and($fixture['chapter']->generationRuns()->latest('id')->first()->error_code)->toBe('assembly_output_budget_exhausted');
});

test('an overlength assembly is compressed once before it becomes a chapter draft', function () {
    $fixture = chapterAssemblyFixture();
    $fixture['chapter']->latestPlan->update(['target_words' => 100]);
    $fake = (new FakeAiProvider)
        ->enqueue(assemblyResponse(str_repeat('超', 120)))
        ->enqueue(assemblyResponse(str_repeat('改', 100)));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());

    expect($artifact->content)->toBe(str_repeat('改', 100))
        ->and($artifact->data['word_count'])->toBe(100)
        ->and($artifact->data['maximum_words'])->toBe(115)
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->systemPrompt)->toContain('章节压缩器')
        ->and($fake->requests()[1]->systemPrompt)->toContain('POV、时态、主文风')
        ->and($fake->requests()[1]->prompt)->toContain('"l4"')
        ->and($fake->requests()[1]->prompt)->toContain('通俗爽快');
});

test('an assembly that remains overlength after compression is never persisted', function () {
    $fixture = chapterAssemblyFixture();
    $fixture['chapter']->latestPlan->update(['target_words' => 100]);
    $fake = (new FakeAiProvider)
        ->enqueue(assemblyResponse(str_repeat('超', 120)))
        ->enqueue(assemblyResponse(str_repeat('仍', 116)));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '必须控制在 85～115 字');

    expect(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->sole()->status)->toBe(RunStatus::Failed)
        ->and($fake->requests())->toHaveCount(2);
});

test('a stale assembly run is marked interrupted before recovery', function () {
    $fixture = chapterAssemblyFixture();
    $stale = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Running,
        'updated_at' => now()->subSeconds((int) config('generation.stalled_run_after_seconds') + 1),
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(assemblyResponse()));

    app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey());

    expect($stale->fresh()->status)->toBe(RunStatus::Failed)
        ->and($stale->fresh()->error_code)->toBe('worker_interrupted')
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(2);
});

test('paused novels cannot start assembly', function () {
    $fixture = chapterAssemblyFixture();
    $fixture['novel']->update(['status' => NovelStatus::Paused]);
    app()->instance(AiProvider::class, new FakeAiProvider);

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '小说已暂停');

    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->count())->toBe(0);
});

test('state version changes during assembly prevent draft persistence', function () {
    $fixture = chapterAssemblyFixture();
    $provider = new class($fixture['novel']) implements AiProvider
    {
        public function __construct(private Novel $novel) {}

        public function generate(AiRequest $request): AiResponse
        {
            $nextVersion = (int) $this->novel->storyStateVersions()->max('version') + 1;
            $version = StoryStateVersion::factory()->for($this->novel)->create(['version' => $nextVersion]);
            $this->novel->update(['canonical_state_version_id' => $version->getKey()]);

            return assemblyResponse();
        }
    };
    app()->instance(AiProvider::class, $provider);

    expect(fn () => app(ChapterAssembler::class)->assemble($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, 'Story State 已变化');

    expect(GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::ChapterAssembly)->sole()->status)->toBe(RunStatus::Failed);
});

test('terminal assembly failure blocks the chapter but preserves scene drafts', function () {
    $fixture = chapterAssemblyFixture();
    $sceneArtifactIds = $fixture['scenes']->pluck('current_artifact_id')->all();

    (new AssembleChapterJob($fixture['chapter']->getKey()))->failed(new AiProviderException('provider_timeout', 'timeout', true));

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Blocked)
        ->and($fixture['chapter']->scenes()->pluck('current_artifact_id')->all())->toBe($sceneArtifactIds);
});
