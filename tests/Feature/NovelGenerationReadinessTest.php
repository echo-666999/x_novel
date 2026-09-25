<?php

use App\Actions\Novels\StartNovelGenerationAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ChapterStatus;
use App\Enums\NovelOutlineStatus;
use App\Enums\NovelStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\NovelOutline;
use App\Models\StoryArc;
use App\Models\User;
use App\Models\Volume;
use App\Models\WorldEntity;
use App\Services\NovelGenerationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('readiness reports every missing planning condition with a repair hint', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft]);
    NovelBible::factory()->for($novel)->create();

    $items = collect(app(NovelGenerationReadiness::class)->evaluate($novel))->keyBy('key');

    expect($items)->toHaveKeys([
        'current_outline',
        'bible',
        'protagonist',
        'world_entity',
        'active_volume',
        'active_story_arc',
        'initial_state',
        'previous_chapter_derivatives',
    ])->and($items['bible']['ready'])->toBeTrue()
        ->and($items['current_outline']['ready'])->toBeFalse()
        ->and($items['protagonist']['repair_hint'])->toContain('人物管理')
        ->and($items['previous_chapter_derivatives']['ready'])->toBeTrue();
});

test('the novel workspace shows readiness gaps and disables the primary start action', function () {
    $this->actingAs(User::factory()->create());
    $novel = Novel::factory()->create(['status' => NovelStatus::Draft]);
    NovelBible::factory()->for($novel)->create();

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertSee('正文生成准备度')
        ->assertSee('Current Outline')
        ->assertSee('主角')
        ->assertSee('前往人物管理创建至少一名角色类型为“主角”的人物。')
        ->assertActionVisible('startNovelGeneration')
        ->assertActionDisabled('startNovelGeneration');
});

test('all readiness conditions enable starting generation', function () {
    $this->actingAs(User::factory()->create());
    $novel = generationReadinessNovel();

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertActionEnabled('startNovelGeneration')
        ->callAction('startNovelGeneration')
        ->assertHasNoActionErrors()
        ->assertNotified('小说已进入生成阶段');

    expect($novel->fresh()->status)->toBe(NovelStatus::Generating);
});

test('the start action rechecks readiness inside its transaction', function () {
    $novel = generationReadinessNovel();
    $readiness = app(NovelGenerationReadiness::class);

    expect($readiness->isReady($novel))->toBeTrue();

    $novel->worldEntities()->delete();

    expect(fn () => app(StartNovelGenerationAction::class)->handle($novel))
        ->toThrow(ValidationException::class, '世界设定');
    expect($novel->fresh()->status)->toBe(NovelStatus::Planning);
});

test('a missing previous canonical summary blocks readiness', function () {
    $novel = generationReadinessNovel();
    Chapter::factory()->for($novel)->create([
        'sequence' => 1,
        'status' => ChapterStatus::Canonical,
        'summary' => null,
    ]);

    $item = collect(app(NovelGenerationReadiness::class)->evaluate($novel))
        ->firstWhere('key', 'previous_chapter_derivatives');

    expect($item['ready'])->toBeFalse()
        ->and($item['repair_hint'])->toContain('第 1 章');
});

function generationReadinessNovel(): Novel
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Planning]);
    $outline = NovelOutline::factory()->for($novel)->create([
        'status' => NovelOutlineStatus::Current,
        'applied_at' => now(),
    ]);
    $novel->update(['current_outline_id' => $outline->getKey()]);
    NovelBible::factory()->for($novel)->create();
    Character::factory()->for($novel)->create(['role' => '主角']);
    WorldEntity::factory()->for($novel)->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    StoryArc::factory()->forVolume($volume)->create(['status' => StoryArcStatus::Active]);
    app(InitializeNovelStateAction::class)->handle($novel);

    return $novel->refresh();
}
