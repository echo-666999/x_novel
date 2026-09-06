<?php

use App\Enums\ChapterStatus;
use App\Enums\PlanStatus;
use App\Enums\SceneStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ManageNovelChapters;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
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

test('the owner can create a complete executable chapter plan without ai', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 4]);
    $pov = Character::factory()->for($novel)->create(['name' => '林舟']);
    $fact = Fact::factory()->for($novel)->create();
    $foreshadowing = Foreshadowing::factory()->for($novel)->create(['title' => '旧信之谜']);

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionExists('managePlan', fn ($action): bool => $action->isModalSlideOver())
        ->callTableAction('managePlan', $chapter, data: [
            'chapter_function' => '迫使主角离开安全区',
            'arc_contribution' => '推进失踪案主线',
            'reader_promise' => '揭示旧信的第一层来源',
            'target_words' => 3_200,
            'pov_character_id' => $pov->getKey(),
            'tone' => '压抑而紧张',
            'time_anchor' => '第三日黄昏',
            'hook_type' => '身份反转',
            'must_reveal' => ['旧信来自城内'],
            'may_hint' => ['守门人知情'],
            'must_not_reveal' => ['幕后主使身份'],
            'required_facts' => [$fact->getKey()],
            'forbidden_conflicts' => ['林舟不得知晓密道出口'],
            'due_foreshadowings' => [$foreshadowing->getKey()],
            'scene_plans' => [[
                'goal' => '取得旧信原件',
                'conflict' => '守门人拒绝交付',
                'turn' => '守门人认出信封印记',
                'outcome' => '林舟带走残缺旧信',
            ]],
            'status' => PlanStatus::Ready->value,
        ])
        ->assertHasNoTableActionErrors();

    $plan = $chapter->plans()->sole();

    expect($plan->version)->toBe(1)
        ->and($plan->chapter_function)->toBe('迫使主角离开安全区')
        ->and($plan->povCharacter->is($pov))->toBeTrue()
        ->and($plan->required_facts)->toBe([$fact->getKey()])
        ->and($plan->due_foreshadowings)->toBe([$foreshadowing->getKey()])
        ->and($plan->scene_plans[0]['outcome'])->toBe('林舟带走残缺旧信')
        ->and($plan->status)->toBe(PlanStatus::Ready);
});

test('the chapter plan form requires all executable fields and at least one scene', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->callTableAction('managePlan', $chapter, data: [
            'chapter_function' => '',
            'arc_contribution' => '',
            'reader_promise' => '',
            'target_words' => 0,
            'pov_character_id' => null,
            'tone' => '',
            'time_anchor' => '',
            'hook_type' => '',
            'scene_plans' => [],
            'status' => PlanStatus::Draft->value,
        ])
        ->assertHasTableActionErrors([
            'chapter_function' => 'required',
            'arc_contribution' => 'required',
            'reader_promise' => 'required',
            'target_words' => 'min',
            'pov_character_id' => 'required',
            'tone' => 'required',
            'time_anchor' => 'required',
            'hook_type' => 'required',
            'scene_plans' => 'required',
        ]);
});

test('the owner can edit the current plan without creating a duplicate version', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $pov = Character::factory()->for($novel)->create();
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'pov_character_id' => $pov->getKey(),
        'status' => PlanStatus::Draft,
    ]);

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->mountTableAction('managePlan', $chapter)
        ->assertTableActionDataSet(fn (array $data): bool => $data['chapter_function'] === $plan->chapter_function);

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->callTableAction('managePlan', $chapter, data: [
            ...$plan->only([
                'chapter_function', 'arc_contribution', 'reader_promise', 'target_words',
                'pov_character_id', 'tone', 'time_anchor', 'hook_type', 'must_reveal',
                'may_hint', 'must_not_reveal', 'required_facts', 'forbidden_conflicts',
                'due_foreshadowings', 'scene_plans',
            ]),
            'reader_promise' => '更新后的读者承诺',
            'status' => PlanStatus::Ready->value,
        ])
        ->assertHasNoTableActionErrors();

    $plan = $plan->fresh();

    expect($chapter->plans()->count())->toBe(1)
        ->and($plan->reader_promise)->toBe('更新后的读者承诺')
        ->and($plan->status)->toBe(PlanStatus::Ready);
});

test('the owner can sync and inspect scenes from a chapter plan', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 7]);
    $pov = Character::factory()->for($novel)->create(['name' => '沈砚']);
    ChapterPlan::factory()->for($chapter)->create([
        'pov_character_id' => $pov->getKey(),
        'time_anchor' => '午夜',
        'scene_plans' => [[
            'goal' => '潜入档案室',
            'conflict' => '巡逻提前抵达',
            'turn' => '档案已被调包',
            'outcome' => '取得伪造者名单',
            'location' => '旧议事厅',
        ]],
    ]);

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionVisible('syncScenes', $chapter)
        ->callTableAction('syncScenes', $chapter)
        ->assertHasNoTableActionErrors();

    $scene = $chapter->scenes()->sole();

    expect($scene->sequence)->toBe(1)
        ->and($scene->goal)->toBe('潜入档案室')
        ->and($scene->povCharacter->is($pov))->toBeTrue()
        ->and($scene->location)->toBe('旧议事厅')
        ->and($scene->status)->toBe(SceneStatus::Planned);

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionVisible('viewScenes', $chapter)
        ->mountTableAction('viewScenes', $chapter)
        ->assertTableActionDataSet(function (array $data): bool {
            $scene = array_values($data['scenes'])[0];

            return $scene['goal'] === '潜入档案室'
                && $scene['pov'] === '沈砚'
                && $scene['status'] === '已规划';
        });
});
