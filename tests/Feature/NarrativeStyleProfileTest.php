<?php

use App\Models\Novel;
use App\Models\NovelBible;
use App\Services\NarrativeStyleProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('style profile reads only the current bible when old editorial values conflict', function () {
    $novel = Novel::factory()->create(['settings' => ['editorial' => [
        'subgenre' => '旧子题材',
        'target_platform' => 'general',
        'story_tone' => 'dark',
        'primary_style' => 'light_humorous',
        'secondary_styles' => ['accessible_brisk'],
        'language_era' => 'modern_spoken',
        'pacing' => 'slow_burn',
        'narrative_pov' => 'third_limited',
        'style_parameters' => ['dialogue_ratio' => 1],
    ]]]);
    NovelBible::factory()->for($novel)->create([
        'tone' => '热血',
        'pov' => '第一人称',
        'tense' => '过去时',
        'style_profile' => [
            'subgenre' => '剑与魔法',
            'target_platform' => 'fanqie',
            'primary_style' => 'steady_weighty',
            'secondary_styles' => ['plain_realist', 'austere'],
            'language_era' => 'vernacular_ancient',
            'pacing' => 'fast',
            'parameters' => [
                'ornateness' => 3,
                'dialogue_ratio' => 4,
                'description_density' => 3,
                'psychology_density' => 2,
                'humor_level' => 1,
                'literary_level' => 3,
            ],
        ],
    ]);

    $profile = app(NarrativeStyleProfile::class)->forNovel($novel);

    expect($profile['subgenre'])->toBe('剑与魔法')
        ->and($profile['target_platform'])->toBe('番茄小说')
        ->and($profile['story_tone'])->toBe('热血')
        ->and($profile['primary_style'])->toBe('沉稳厚重')
        ->and($profile['secondary_styles'])->toBe(['白描写实', '冷峻克制'])
        ->and($profile['language_era'])->toBe('古风白话')
        ->and($profile['pacing'])->toBe('快节奏')
        ->and($profile['narrative_pov'])->toBe('第一人称')
        ->and($profile['tense'])->toBe('过去时')
        ->and($profile['parameters']['dialogue_ratio'])->toBe(4)
        ->and($profile['instructions'])->toHaveCount(3);
});

test('legacy free text narrative style is not used after the bible source switch', function () {
    $novel = Novel::factory()->create(['settings' => ['generation' => ['narrative_style' => '冷峻凝练，避免现代网络梗。']]]);
    NovelBible::factory()->for($novel)->create();

    expect(app(NarrativeStyleProfile::class)->forNovel($novel)['instructions'])
        ->not->toContain('已有自定义要求：冷峻凝练，避免现代网络梗。');
});

test('style profile rejects a novel without a current bible', function () {
    $novel = Novel::factory()->create();

    app(NarrativeStyleProfile::class)->forNovel($novel);
})->throws(ValidationException::class, '缺少 Current Bible');

test('style profile rejects an incomplete current bible profile without falling back', function () {
    $novel = Novel::factory()->create(['settings' => ['editorial' => [
        'primary_style' => 'accessible_brisk',
    ]]]);
    NovelBible::factory()->for($novel)->create(['style_profile' => null]);

    app(NarrativeStyleProfile::class)->forNovel($novel);
})->throws(ValidationException::class, '必须提供完整的文风设置');
