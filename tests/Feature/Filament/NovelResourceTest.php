<?php

use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\EditNovel;
use App\Filament\Resources\Novels\Pages\ListNovels;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the owner can list search and filter novels', function () {
    $draft = Novel::factory()->create([
        'title' => '雾海长明',
        'status' => NovelStatus::Draft,
    ]);
    $completed = Novel::factory()->create([
        'title' => '星河终章',
        'status' => NovelStatus::Completed,
    ]);

    Livewire::test(ListNovels::class)
        ->assertOk()
        ->assertSee('创建小说')
        ->assertCanSeeTableRecords([$draft, $completed])
        ->searchTable('雾海')
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$completed]);

    Livewire::test(ListNovels::class)
        ->filterTable('status', NovelStatus::Completed->value)
        ->assertCanSeeTableRecords([$completed])
        ->assertCanNotSeeTableRecords([$draft]);
});

test('the owner can create a novel and enters its workbench', function () {
    Livewire::test(CreateNovel::class)
        ->fillForm([
            'title' => '长夜将明',
            'genre' => '玄幻',
            'premise' => '失去故乡的少年踏上寻找真相的旅程。',
            'target_words' => 1_000_000,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $novel = Novel::query()->sole();

    expect($novel->title)->toBe('长夜将明')
        ->and($novel->status)->toBe(NovelStatus::Draft);

    $this->get(NovelResource::getUrl('view', ['record' => $novel]))
        ->assertOk()
        ->assertSee('小说概览');
});

test('the owner can edit a novels basic information', function () {
    $novel = Novel::factory()->create();

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->fillForm([
            'title' => '群星彼岸',
            'genre' => '科幻',
            'premise' => '远航者寻找失落文明。',
            'target_words' => 600_000,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($novel->refresh())
        ->title->toBe('群星彼岸')
        ->genre->toBe('科幻')
        ->target_words->toBe(600_000)
        ->status->toBe(NovelStatus::Draft)
        ->current_chapter_sequence->toBeNull();
});

test('novel form validates required fields and positive target words', function () {
    Livewire::test(CreateNovel::class)
        ->fillForm([
            'title' => '',
            'genre' => '',
            'target_words' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'title' => 'required',
            'genre' => 'required',
            'target_words' => 'min',
        ]);
});

test('the novel workspace overview shows real values and explicit unavailable metrics', function () {
    $novel = Novel::factory()->create([
        'title' => '雾海长明',
        'genre' => '玄幻',
        'premise' => '迷雾中的孤城等待黎明。',
        'target_words' => 900_000,
        'current_chapter_sequence' => 12,
        'status' => NovelStatus::Generating,
    ]);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSeeTextInOrder([
            '小说概览',
            '标题',
            '雾海长明',
            '题材',
            '玄幻',
            '状态',
            '生成中',
            '进度与状态',
            '目标字数',
            '900,000',
            '当前字数',
            '0',
            '当前卷',
            '尚未接入',
            '当前章节',
            '第 12 章',
            '当前 State Version',
            '生成状态',
            '质量与运营',
            '今日成本',
            '¥0.00',
            'Review 通过率',
            'Rewrite 比例',
            '待处理伏笔',
            '需要处理',
        ]);
});

test('next chapter generation and auto generation controls are available while future workflow actions remain disabled', function () {
    $novel = Novel::factory()->create();

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertSee('尚无正式章节')
        ->assertSee('Auto: OFF')
        ->assertActionExists('generateNextChapter')
        ->assertActionEnabled('generateNextChapter')
        ->assertActionVisible('startAutoGenerate')
        ->assertActionHidden('stopAutoGenerate')
        ->callAction('startAutoGenerate')
        ->assertNotified('自动生成已开启')
        ->assertActionHidden('startAutoGenerate')
        ->assertActionVisible('stopAutoGenerate')
        ->assertSee('Auto: ON')
        ->callAction('stopAutoGenerate')
        ->assertNotified('自动生成已停止')
        ->assertSee('Auto: OFF')
        ->assertActionHidden('pause')
        ->assertActionHidden('resume')
        ->assertActionExists('edit');
});

test('a generating novel can be paused from the overview with its current stage displayed', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $chapter = Chapter::factory()->for($novel)->create();
    GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Running,
    ]);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertActionVisible('pause')
        ->assertActionHidden('resume')
        ->callAction('pause')
        ->assertNotified('小说生成已暂停')
        ->assertSee('Paused at: 审校')
        ->assertActionHidden('pause')
        ->assertActionVisible('resume')
        ->assertActionDisabled('resume')
        ->assertActionHidden('generateNextChapter');

    expect($novel->fresh()->status)->toBe(NovelStatus::Paused);
});
