<?php

use App\Enums\ChapterStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ManageNovelChapters;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the chapter workspace lists only the current novels chapters', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $volume = Volume::factory()->for($novel)->create(['sequence' => 1, 'title' => '孤城卷']);
    $planned = Chapter::factory()->for($novel)->create([
        'volume_id' => $volume->getKey(),
        'sequence' => 1,
        'title' => '雾港来信',
        'status' => ChapterStatus::Planned,
    ]);
    $canonical = Chapter::factory()->for($novel)->create([
        'sequence' => 2,
        'title' => '灯塔余烬',
        'status' => ChapterStatus::Canonical,
        'word_count' => 3_200,
    ]);
    StoryStateVersion::factory()->for($novel)->create([
        'version' => 2,
        'chapter_id' => $canonical->getKey(),
    ]);
    $otherChapter = Chapter::factory()->create(['title' => '不应出现']);

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSeeTextInOrder(['章节', '雾海长明', '标题', '分卷', '状态', '字数', 'Review', '成本', 'State Version'])
        ->assertCanSeeTableRecords([$planned, $canonical])
        ->assertCanNotSeeTableRecords([$otherChapter])
        ->assertSee('雾港来信')
        ->assertSee('第 1 卷 · 孤城卷')
        ->assertSee('已规划')
        ->assertSee('正式章节')
        ->assertSee('3,200')
        ->assertSee('尚未接入')
        ->assertSee('v2')
        ->assertActionExists('create');
});

test('the owner can create a planned chapter', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create();

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'sequence' => 1,
            'title' => '启程',
            'volume_id' => $volume->getKey(),
        ])
        ->assertHasNoActionErrors();

    $chapter = $novel->chapters()->sole();

    expect($chapter->sequence)->toBe(1)
        ->and($chapter->title)->toBe('启程')
        ->and($chapter->volume->is($volume))->toBeTrue()
        ->and($chapter->status)->toBe(ChapterStatus::Planned)
        ->and($chapter->word_count)->toBe(0)
        ->and($chapter->canonical_artifact_id)->toBeNull();
});

test('the chapter form validates required fields and scoped sequence uniqueness', function () {
    $novel = Novel::factory()->create();
    Chapter::factory()->for($novel)->create(['sequence' => 1]);

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'sequence' => 1,
            'title' => '',
        ])
        ->assertHasActionErrors([
            'sequence' => 'unique',
            'title' => 'required',
        ]);
});

test('chapters can be filtered by status and volume', function () {
    $novel = Novel::factory()->create();
    $firstVolume = Volume::factory()->for($novel)->create(['sequence' => 1]);
    $secondVolume = Volume::factory()->for($novel)->create(['sequence' => 2]);
    $planned = Chapter::factory()->for($novel)->create([
        'volume_id' => $firstVolume->getKey(),
        'status' => ChapterStatus::Planned,
    ]);
    $blocked = Chapter::factory()->for($novel)->create([
        'volume_id' => $secondVolume->getKey(),
        'status' => ChapterStatus::Blocked,
    ]);

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->filterTable('status', ChapterStatus::Blocked->value)
        ->assertCanSeeTableRecords([$blocked])
        ->assertCanNotSeeTableRecords([$planned])
        ->resetTableFilters()
        ->filterTable('volume_id', $firstVolume->getKey())
        ->assertCanSeeTableRecords([$planned])
        ->assertCanNotSeeTableRecords([$blocked]);
});

test('the chapter page stays inside the novel workspace', function () {
    $novel = Novel::factory()->create();

    $this->get(NovelResource::getUrl('chapters', ['record' => $novel]))
        ->assertOk()
        ->assertSee('章节')
        ->assertSee('创建计划章节');
});
