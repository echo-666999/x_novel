<?php

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\StoryArc;
use App\Models\StoryStateVersion;
use App\Services\ClosureDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('closure debt combines every defined source and identifies critical items', function () {
    $novel = Novel::factory()->create(['current_chapter_sequence' => 20]);
    NovelBible::factory()->for($novel)->create();

    StoryArc::factory()->for($novel)->create([
        'title' => '终结雾潮',
        'type' => StoryArcType::Main,
        'status' => StoryArcStatus::Active,
    ]);
    StoryArc::factory()->for($novel)->create([
        'title' => '商路争端',
        'type' => StoryArcType::Subplot,
        'status' => StoryArcStatus::Completed,
    ]);
    Foreshadowing::factory()->for($novel)->create([
        'title' => '断剑来历',
        'due_from_chapter' => 18,
        'due_to_chapter' => 19,
        'status' => ForeshadowingStatus::Reinforced,
        'importance' => ForeshadowingImportance::Critical,
    ]);

    $state = StoryStateVersion::factory()->for($novel)->create([
        'state' => [
            'reader_promises' => [
                'bell' => ['title' => '揭示钟声来源', 'status' => 'open', 'importance' => 'high'],
                'map' => ['title' => '解释地图', 'status' => 'resolved'],
            ],
            'relationships' => [
                'hero-rival' => ['title' => '主角与宿敌', 'status' => 'open', 'importance' => 'critical'],
            ],
            'world' => [
                'crises' => ['mist' => ['title' => '雾潮吞噬王都', 'status' => 'open']],
            ],
            'open_threads' => [],
        ],
    ]);
    $novel->update(['canonical_state_version_id' => $state->getKey()]);

    $result = app(ClosureDebtService::class)->calculate($novel->fresh());

    expect($result->total())->toBe(5)
        ->and($result->critical())->toBe(4)
        ->and(collect($result->toArray())->pluck('title')->all())->toContain(
            '终结雾潮',
            '揭示钟声来源',
            '断剑来历',
            '主角与宿敌',
            '雾潮吞噬王都',
        );
});

test('ending contract gaps are critical while resolved state and terminal records add no debt', function () {
    $novel = Novel::factory()->create(['current_chapter_sequence' => 30]);
    NovelBible::factory()->for($novel)->create([
        'ending_contract' => [
            'final_protagonist_state' => '主角成为守门人',
            'main_conflict_resolution' => '',
            'theme_payoff' => '责任来自选择',
            'required_foreshadowing_payoff' => ['断剑来历'],
            'character_arc_requirements' => ['不再逃避'],
            'allowed_open_endings' => ['远海文明'],
        ],
    ]);
    StoryArc::factory()->for($novel)->create(['status' => StoryArcStatus::Completed]);
    Foreshadowing::factory()->for($novel)->create([
        'status' => ForeshadowingStatus::PaidOff,
        'due_from_chapter' => 10,
        'due_to_chapter' => 20,
    ]);
    $state = StoryStateVersion::factory()->for($novel)->create([
        'state' => [
            'reader_promises' => ['promise' => ['title' => '旧承诺', 'status' => 'resolved']],
            'relationships' => ['pair' => ['title' => '旧关系', 'status' => 'resolved', 'importance' => 'critical']],
            'world' => ['crises' => ['storm' => ['title' => '旧危机', 'status' => 'resolved']]],
            'open_threads' => [],
        ],
    ]);
    $novel->update(['canonical_state_version_id' => $state->getKey()]);

    $result = app(ClosureDebtService::class)->calculate($novel->fresh());

    expect($result->total())->toBe(1)
        ->and($result->critical())->toBe(1)
        ->and($result->toArray()[0]['title'])->toBe('主冲突解决方式')
        ->and($result->toArray()[0]['category'])->toBe('ending_contract_gap');
});
