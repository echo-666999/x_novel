<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\FactStatus;
use App\Enums\GenerationStage;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ManageNovelChapters;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\Review;
use App\Models\Scene;
use App\Models\StoryStateVersion;
use App\Models\UsageRecord;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('chapter detail is the workspace for all chapter pipeline stages', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 12,
        'title' => '旧港夜航',
        'word_count' => 2460,
    ]);
    ChapterPlan::factory()->for($chapter)->create([
        'chapter_function' => '迫使主角离开安全区',
        'arc_contribution' => '推进失踪船队主线',
        'reader_promise' => '确认钟声来自沉没灯塔',
    ]);
    Scene::factory()->for($chapter)->create([
        'sequence' => 1,
        'goal' => '取得出港许可',
        'conflict' => '港务官拒绝放行',
        'turn' => '潮汐钟提前响起',
        'outcome' => '林舟被迫偷船',
    ]);
    StoryStateVersion::factory()->for($novel)->create([
        'chapter_id' => $chapter->getKey(),
        'version' => 3,
    ]);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertOk()
        ->assertSee('第 12 章 · 旧港夜航')
        ->assertSee('章节工作台')
        ->assertSeeTextInOrder([
            '概览',
            '计划',
            '场景',
            '草稿',
            '事件',
            '审校',
            '状态变化',
            '正式版本',
            '运行记录',
        ])
        ->assertSee('迫使主角离开安全区')
        ->assertSee('取得出港许可')
        ->assertSee('v3')
        ->assertSee('尚无章节草稿')
        ->assertSee('故事事件候选')
        ->assertSee('尚无候选事件')
        ->assertSee('叙事审校')
        ->assertSee('尚无生成运行记录');
});

test('canonical chapter viewer separates the formal text from drafts and shows its provenance', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $draftRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $canonicalArtifact = GenerationArtifact::factory()->for($draftRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'version' => 2,
        'content' => '这是已经提交的正式章节正文。',
    ]);
    $reviewRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
    ]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create([
        'type' => ArtifactType::ReviewResult,
        'data' => ['source_artifact_id' => $canonicalArtifact->getKey()],
    ]);
    Review::factory()->for($reviewRun)->create([
        'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::Pass,
    ]);
    StoryStateVersion::factory()->for($novel)->for($chapter)->create([
        'version' => 4,
        'created_at' => '2026-09-07 16:30:00',
    ]);
    UsageRecord::factory()->create([
        'generation_run_id' => $draftRun->getKey(),
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
        'estimated_cost' => 0.012345,
    ]);
    $chapter->update([
        'status' => ChapterStatus::Canonical,
        'canonical_artifact_id' => $canonicalArtifact->getKey(),
        'word_count' => 14,
    ]);
    Memory::factory()->for($novel)->create([
        'source_type' => 'story_event',
        'source_id' => 42,
        'valid_from_chapter' => $chapter->sequence,
    ]);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertOk()
        ->assertSee('正式版本')
        ->assertSee('正式')
        ->assertSee('章节草稿 v2')
        ->assertSee('2026-09-07 16:30:00')
        ->assertSee('v4')
        ->assertSee('通过')
        ->assertSee('USD 0.0123')
        ->assertSee('已创建记忆：1')
        ->assertSee('/x/memory')
        ->assertSee('这是已经提交的正式章节正文。');
});

test('chapter detail shows useful empty states before planning starts', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();

    Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertOk()
        ->assertSee('尚未建立章节计划')
        ->assertSee('尚未同步场景')
        ->assertSee('尚无正式状态变化');
});

test('chapter overview shows the latest draft length instead of the stored canonical word count', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create(['word_count' => 1380]);
    ChapterPlan::factory()->for($chapter)->create(['target_words' => 3000]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scene_id' => null,
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => str_repeat('章', 900),
    ]);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertOk()
        ->assertSee('当前稿字数')
        ->assertSee('目标字数')
        ->assertSee('字数完成度')
        ->assertSee('900')
        ->assertSee('3,000')
        ->assertSee('30%');
});

test('chapter detail rejects a chapter from another novel', function () {
    $novel = Novel::factory()->create();
    $foreignChapter = Chapter::factory()->create();

    $this->get(NovelResource::getUrl('chapter', [
        'record' => $novel,
        'chapter' => $foreignChapter,
    ]))->assertNotFound();
});

test('the chapter list links each chapter to its detail workspace', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionVisible('viewChapter', $chapter)
        ->assertTableActionHasUrl(
            'viewChapter',
            NovelResource::getUrl('chapter', [
                'record' => $novel,
                'chapter' => $chapter,
            ]),
            $chapter,
        );
});

test('scene workspace exposes generation actions and execution metrics', function () {
    Queue::fake();

    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    ChapterPlan::factory()->for($chapter)->create();
    $planned = Scene::factory()->for($chapter)->create(['sequence' => 2]);
    $completed = Scene::factory()->for($chapter)->create([
        'sequence' => 1,
        'status' => SceneStatus::Draft,
    ]);
    Scene::factory()->for($chapter)->create([
        'sequence' => 3,
        'status' => SceneStatus::Failed,
    ]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->for($completed)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::SceneDraft,
        'content' => '已生成的场景正文',
    ]);
    $completed->update(['current_artifact_id' => $artifact->getKey()]);
    UsageRecord::factory()->create([
        'generation_run_id' => $run->getKey(),
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
        'estimated_cost' => 0.012345,
    ]);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertSee('生成')
        ->assertSee('重试')
        ->assertActionDisabled('regeneratePlan')
        ->assertActionExists(TestAction::make('regenerateScene'.$completed->getKey())->schemaComponent('scene-'.$completed->getKey(), 'content'))
        ->callAction(TestAction::make('regenerateScene'.$completed->getKey())->schemaComponent('scene-'.$completed->getKey(), 'content'))
        ->assertSee('查看产物')
        ->assertSee('查看运行记录')
        ->assertSee('字数')
        ->assertSee('耗时')
        ->assertSee('USD 0.0123');

    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $completed->getKey()
        && $job->cascade
        && $job->regenerationBatchId !== null);
});

test('draft workspace switches between artifact versions and source scenes', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    ChapterPlan::factory()->for($chapter)->create();
    $scenes = collect([1, 2])->map(function (int $sequence) use ($chapter, $novel): Scene {
        $scene = Scene::factory()->for($chapter)->create([
            'sequence' => $sequence,
            'status' => SceneStatus::Draft,
        ]);
        $run = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
            'stage' => GenerationStage::SceneGeneration,
            'status' => RunStatus::Succeeded,
        ]);
        $artifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::SceneDraft,
            'content' => "第 {$sequence} 幕正文",
        ]);
        $scene->update(['current_artifact_id' => $artifact->getKey()]);

        return $scene;
    });

    foreach ([1, 2] as $version) {
        $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
            'scene_id' => null,
            'stage' => GenerationStage::ChapterAssembly,
            'status' => RunStatus::Succeeded,
        ]);
        GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::ChapterDraft,
            'version' => $version,
            'content' => "完整 Draft v{$version}",
        ]);
    }

    Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertSee('组装章节')
        ->assertSee('草稿 v2')
        ->assertSee('草稿 v1')
        ->assertSee('完整草稿')
        ->assertSee('场景 1')
        ->assertSee('场景 2')
        ->assertSee('第 1 幕正文');
});

test('pipeline timeline identifies the blocked stage and exposes its run details', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    ChapterPlan::factory()->for($chapter)->create();
    $planningRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Succeeded,
        'context_snapshot' => ['schema_version' => 'context-v1', 'l0' => ['chapter' => ['id' => $chapter->getKey()]]],
    ]);
    GenerationArtifact::factory()->for($planningRun)->create([
        'type' => ArtifactType::ChapterPlan,
    ]);
    $scene = Scene::factory()->for($chapter)->create([
        'sequence' => 1,
        'status' => SceneStatus::Failed,
        'goal' => '穿过封锁线',
    ]);
    $failedRun = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Failed,
        'model_policy' => 'gpt-test',
        'prompt_version' => 'scene-writer-v1',
        'error_code' => 'provider_timeout',
        'error_message' => '模型请求超时',
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);
    UsageRecord::factory()->create([
        'generation_run_id' => $failedRun->getKey(),
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
        'input_tokens' => 120,
        'output_tokens' => 30,
        'estimated_cost' => 0.0015,
    ]);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertSee('生成流水线')
        ->assertSeeTextInOrder(['计划', '上下文', '场景', '场景 1', '章节组装', '事件', '审校', '正式提交', '记忆'])
        ->assertSee('失败')
        ->assertActionExists(TestAction::make('inspectTimelinePlan')->schemaComponent('timeline-stage-plan', 'content'), fn ($action): bool => $action->isModalSlideOver())
        ->assertActionExists(TestAction::make('inspectTimelineScene'.$scene->getKey())->schemaComponent('timeline-stage-scene-'.$scene->getKey(), 'content'), fn ($action): bool => $action->isModalSlideOver())
        ->mountAction(TestAction::make('inspectTimelineScene'.$scene->getKey())->schemaComponent('timeline-stage-scene-'.$scene->getKey(), 'content'))
        ->assertSee('gpt-test')
        ->assertSee('scene-writer-v1')
        ->assertSee('provider_timeout · 模型请求超时')
        ->unmountAction()
        ->mountAction(TestAction::make('inspectTimelineContext')->schemaComponent('timeline-stage-context', 'content'))
        ->assertSchemaComponentExists('timeline_context_l0')
        ->assertSchemaComponentExists('timeline_context_l1')
        ->assertSchemaComponentExists('timeline_context_l2')
        ->assertSchemaComponentExists('timeline_context_l3')
        ->assertSchemaComponentExists('timeline_context_l4')
        ->assertSchemaComponentExists('timeline_context_token_allocation')
        ->assertSchemaComponentExists('timeline_context_selected_memories');
});

test('events workspace shows candidates and can dispatch extraction', function () {
    Queue::fake();

    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    ChapterPlan::factory()->for($chapter)->create();
    $assemblyRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $draft = GenerationArtifact::factory()->for($assemblyRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => '林舟抵达洛阳。',
    ]);
    $eventRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
    ]);
    GenerationArtifact::factory()->for($eventRun)->create([
        'type' => ArtifactType::EventCandidate,
        'data' => [
            'status' => 'candidate',
            'source_artifact_id' => $draft->getKey(),
            'events' => [[
                'event_type' => 'character_moved',
                'subject_type' => 'character',
                'subject_id' => '12',
                'payload' => ['to' => '洛阳'],
                'evidence' => [[
                    'artifact_id' => $draft->getKey(),
                    'scene_id' => null,
                    'quote' => '林舟抵达洛阳。',
                    'start_offset' => 0,
                    'end_offset' => 7,
                ]],
                'story_time' => '第三日',
                'confidence' => 0.95,
            ]],
        ],
    ]);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertSee('故事事件候选')
        ->assertSee('候选')
        ->assertSee('Character Moved')
        ->assertSee('character · 12')
        ->assertSee('95.0%')
        ->assertSee('林舟抵达洛阳。')
        ->assertActionExists(TestAction::make('extractStoryEvents')->schemaComponent('story-event-candidates', 'content'))
        ->callAction(TestAction::make('extractStoryEvents')->schemaComponent('story-event-candidates', 'content'));

    Queue::assertPushed(ExtractStoryEventsJob::class, fn (ExtractStoryEventsJob $job): bool => $job->chapterId === $chapter->getKey() && $job->regenerate);
});

test('state changes workspace builds and displays a candidate patch preview', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create();
    $eventRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
        'state_version' => 0,
    ]);
    GenerationArtifact::factory()->for($eventRun)->create([
        'type' => ArtifactType::EventCandidate,
        'data' => [
            'status' => 'candidate',
            'source_artifact_id' => 99,
            'events' => [[
                'event_type' => 'character_moved',
                'subject_type' => 'character',
                'subject_id' => '12',
                'payload' => ['from' => '长安', 'to' => '洛阳'],
                'evidence' => [[
                    'artifact_id' => 99,
                    'scene_id' => null,
                    'quote' => '林舟抵达洛阳。',
                    'start_offset' => null,
                    'end_offset' => null,
                ]],
                'story_time' => '第三日',
                'confidence' => 0.95,
            ]],
        ],
    ]);

    $page = Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertSee('状态补丁预览')
        ->assertSee('尚未生成')
        ->assertActionExists(TestAction::make('buildStatePatch')->schemaComponent('state-patch-preview', 'content'))
        ->callAction(TestAction::make('buildStatePatch')->schemaComponent('state-patch-preview', 'content'));

    $page = Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ]);

    $page
        ->assertSee('候选')
        ->assertSee('characters.12.location')
        ->assertSee('洛阳')
        ->assertSee('character_moved');

    expect($novel->fresh()->storyStateVersions()->count())->toBe(1);
});

test('state findings panel clearly blocks a golden locked fact conflict', function () {
    $novel = Novel::factory()->create();
    $character = Character::factory()->for($novel)->create(['name' => '林舟']);
    app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create();
    $fact = Fact::factory()->for($novel)->create([
        'subject_type' => 'character',
        'subject_id' => $character->getKey(),
        'predicate' => 'status',
        'value' => ['value' => 'dead'],
        'status' => FactStatus::Active,
        'locked' => true,
    ]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
        'state_version' => 0,
    ]);
    $event = [
        'event_type' => 'character_status_changed',
        'subject_type' => 'character',
        'subject_id' => (string) $character->getKey(),
        'payload' => ['status' => 'alive'],
        'evidence' => [[
            'artifact_id' => 99,
            'scene_id' => null,
            'quote' => '林舟重新站了起来。',
            'start_offset' => null,
            'end_offset' => null,
        ]],
        'story_time' => null,
        'confidence' => 0.99,
    ];
    $candidate = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::EventCandidate,
        'data' => ['status' => 'candidate', 'events' => [$event]],
    ]);
    GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::StatePatch,
        'data' => [
            'status' => 'candidate',
            'source_artifact_id' => $candidate->getKey(),
            'expected_state_version' => 0,
            'operations' => [[
                'op' => 'set',
                'path' => "characters.{$character->getKey()}.status",
                'value' => 'alive',
                'source_event_index' => 0,
            ]],
            'fact_changes' => [],
            'foreshadowing_changes' => [],
            'changes' => [[
                'path' => "characters.{$character->getKey()}.status",
                'operation' => 'set',
                'before' => 'dead',
                'after' => 'alive',
                'before_missing' => false,
                'after_missing' => false,
                'source_event_index' => 0,
                'source_event_type' => 'character_status_changed',
                'source_subject_type' => 'character',
                'source_subject_id' => (string) $character->getKey(),
            ]],
        ],
    ]);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertSee('状态检查结果')
        ->assertSee('BLOCK')
        ->assertSee('LOCKED_FACT_CONFLICT')
        ->assertSee('林舟重新站了起来。')
        ->assertSee('#'.$fact->getKey())
        ->assertSee("characters.{$character->getKey()}.status");
});
