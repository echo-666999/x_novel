<?php

test('outline configuration documentation and environment example match the four-stage contract', function () {
    $root = dirname(__DIR__, 2);
    $env = file_get_contents($root.'/.env.example');
    $aiConfig = file_get_contents($root.'/config/ai.php');
    $generationConfig = file_get_contents($root.'/config/generation.php');
    $prd = file_get_contents($root.'/docs/PRD.md');
    $architecture = file_get_contents($root.'/docs/architecture/generation-pipeline.md');
    $dataModel = file_get_contents($root.'/docs/architecture/data-model.md');

    foreach ([$env, $aiConfig, $generationConfig, $prd, $architecture, $dataModel] as $content) {
        expect($content)->toBeString()->not->toBeEmpty();
    }

    $stages = [
        'outline_foundation' => 'OUTLINE_FOUNDATION',
        'outline_structure' => 'OUTLINE_STRUCTURE',
        'outline_arc_beats' => 'OUTLINE_ARC_BEATS',
        'outline_beat_detail' => 'OUTLINE_BEAT_DETAIL',
    ];
    foreach ($stages as $stage => $environmentSuffix) {
        expect($env)
            ->toContain("AI_PROVIDER_{$environmentSuffix}=")
            ->toContain("AI_MODEL_{$environmentSuffix}=")
            ->toContain("AI_REASONING_EFFORT_{$environmentSuffix}=")
            ->toContain("{$environmentSuffix}_OUTPUT_TOKENS=")
            ->toContain("{$environmentSuffix}_REASONING_RESERVE_TOKENS=");
        expect($aiConfig)
            ->toContain("AI_PROVIDER_{$environmentSuffix}")
            ->toContain("AI_MODEL_{$environmentSuffix}")
            ->toContain("AI_REASONING_EFFORT_{$environmentSuffix}")
            ->toContain("'{$stage}'");
        expect($generationConfig)
            ->toContain("{$environmentSuffix}_OUTPUT_TOKENS")
            ->toContain("{$environmentSuffix}_REASONING_RESERVE_TOKENS")
            ->toContain("'{$stage}'");
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
