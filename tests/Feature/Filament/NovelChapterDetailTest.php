<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ManageNovelChapters;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\StoryStateVersion;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->assertSee('Chapter Detail 工作台')
        ->assertSeeTextInOrder([
            'Overview',
            'Plan',
            'Scenes',
            'Draft',
            'Events',
            'Review',
            'State Changes',
            'Runs',
        ])
        ->assertSee('迫使主角离开安全区')
        ->assertSee('取得出港许可')
        ->assertSee('v3')
        ->assertSee('Draft 尚未接入')
        ->assertSee('Story Events 尚未接入')
        ->assertSee('Review 尚未接入')
        ->assertSee('尚无 Chapter Planning Run');
});

test('chapter detail shows useful empty states before planning starts', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();

    Livewire::test(ViewNovelChapter::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertOk()
        ->assertSee('尚未建立 Chapter Plan')
        ->assertSee('尚未同步 Scenes')
        ->assertSee('尚无正式 State Changes');
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
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    ChapterPlan::factory()->for($chapter)->create();
    $planned = Scene::factory()->for($chapter)->create(['sequence' => 1]);
    $completed = Scene::factory()->for($chapter)->create([
        'sequence' => 2,
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
        ->assertSee('Generate')
        ->assertSee('Retry')
        ->assertSee('View Artifact')
        ->assertSee('View Run')
        ->assertSee('字数')
        ->assertSee('耗时')
        ->assertSee('USD 0.012345');
});
