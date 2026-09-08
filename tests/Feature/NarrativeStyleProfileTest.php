<?php

use App\Models\Novel;
use App\Services\NarrativeStyleProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('style profile expands presets and applies explicit parameter overrides', function () {
    $novel = Novel::factory()->create(['settings' => ['editorial' => [
        'subgenre' => '汉朝',
        'target_platform' => 'qidian',
        'story_tone' => 'serious',
        'primary_style' => 'steady_weighty',
        'secondary_styles' => ['plain_realist', 'austere', 'steady_weighty'],
        'language_era' => 'vernacular_ancient',
        'pacing' => 'balanced',
        'narrative_pov' => 'third_limited',
        'style_parameters' => ['dialogue_ratio' => 4],
    ]]]);

    $profile = app(NarrativeStyleProfile::class)->forNovel($novel);

    expect($profile['primary_style'])->toBe('沉稳厚重')
        ->and($profile['secondary_styles'])->toBe(['白描写实', '冷峻克制'])
        ->and($profile['language_era'])->toBe('古风白话')
        ->and($profile['narrative_pov'])->toBe('第三人称限知')
        ->and($profile['parameters']['dialogue_ratio'])->toBe(4)
        ->and($profile['instructions'])->toHaveCount(3);
});

test('legacy free text narrative style remains part of generated instructions', function () {
    $novel = Novel::factory()->create(['settings' => ['generation' => ['narrative_style' => '冷峻凝练，避免现代网络梗。']]]);

    expect(app(NarrativeStyleProfile::class)->forNovel($novel)['instructions'])
        ->toContain('已有自定义要求：冷峻凝练，避免现代网络梗。');
});
