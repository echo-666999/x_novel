<?php

test('outline configuration documentation and database settings match the four-stage contract', function () {
    $root = dirname(__DIR__, 2);
    $env = file_get_contents($root.'/.env.example');
    $aiConfig = file_get_contents($root.'/config/ai.php');
    $generationConfig = file_get_contents($root.'/config/generation.php');
    $generationSettings = file_get_contents($root.'/app/Services/GenerationSettingsService.php');
    $settingsPage = file_get_contents($root.'/app/Filament/Pages/Settings.php');
    $prd = file_get_contents($root.'/docs/PRD.md');
    $architecture = file_get_contents($root.'/docs/architecture/generation-pipeline.md');
    $dataModel = file_get_contents($root.'/docs/architecture/data-model.md');

    foreach ([$env, $aiConfig, $generationConfig, $generationSettings, $settingsPage, $prd, $architecture, $dataModel] as $content) {
        expect($content)->toBeString()->not->toBeEmpty();
    }

    $routes = file_get_contents($root.'/app/Models/AIModelRoute.php');
    $connections = file_get_contents($root.'/app/Models/AIProviderConnection.php');
    $stages = [
        'outline_foundation' => 'OUTLINE_FOUNDATION',
        'outline_structure' => 'OUTLINE_STRUCTURE',
        'outline_arc_beats' => 'OUTLINE_ARC_BEATS',
        'outline_beat_detail' => 'OUTLINE_BEAT_DETAIL',
    ];
    foreach ($stages as $stage => $environmentSuffix) {
        expect($env)
            ->not->toContain("AI_PROVIDER_{$environmentSuffix}=")
            ->not->toContain("AI_MODEL_{$environmentSuffix}=")
            ->not->toContain("AI_REASONING_EFFORT_{$environmentSuffix}=")
            ->not->toContain("{$environmentSuffix}_OUTPUT_TOKENS=")
            ->not->toContain("{$environmentSuffix}_REASONING_RESERVE_TOKENS=");
        expect($aiConfig)
            ->not->toContain("AI_PROVIDER_{$environmentSuffix}")
            ->not->toContain("AI_MODEL_{$environmentSuffix}")
            ->not->toContain("AI_REASONING_EFFORT_{$environmentSuffix}");
        expect($routes)->toContain('AIModelRoute');
        expect($connections)->toContain('AIProviderConnection');
        expect($generationConfig)
            ->toContain("'{$stage}'");
        expect($generationSettings)->toContain('outline_request_budgets');
        expect($settingsPage)->toContain('outline_request_budgets');
        expect($prd)->toContain("`{$stage}`");
        expect($architecture)->toContain("`{$stage}`");
        expect($dataModel)->toContain("`{$stage}`");
    }

    expect($env)
        ->not->toContain('OUTLINE_PLANNER_CAPACITY')
        ->not->toContain('AI_MODEL_OUTLINE=');
    expect($prd)
        ->toContain('Restart')
        ->toContain('reasoning_tokens')
        ->toContain('visible_output_truncated');
    expect($architecture)
        ->toContain('Restart')
        ->toContain('reasoning_tokens')
        ->toContain('visible_output_truncated');
    expect($dataModel)
        ->toContain('Restart')
        ->toContain('旧 v4');
});
