<?php

test('chapter capacity database settings and documentation match the frozen budget contract', function () {
    $root = dirname(__DIR__, 2);
    $env = file_get_contents($root.'/.env.example');
    $generationConfig = file_get_contents($root.'/config/generation.php');
    $generationSettingMigration = file_get_contents($root.'/database/migrations/2026_10_14_110000_seed_generation_system_setting.php');
    $generationSettings = file_get_contents($root.'/app/Services/GenerationSettingsService.php');
    $settingsPage = file_get_contents($root.'/app/Filament/Pages/Settings.php');
    $prd = file_get_contents($root.'/docs/PRD.md');
    $architecture = file_get_contents($root.'/docs/architecture/generation-pipeline.md');
    $dataModel = file_get_contents($root.'/docs/architecture/data-model.md');

    foreach ([$env, $generationConfig, $generationSettingMigration, $generationSettings, $settingsPage, $prd, $architecture, $dataModel] as $content) {
        expect($content)->toBeString()->not->toBeEmpty();
    }

    $stages = [
        'planner' => ['PLANNER', true],
        'writer' => ['SCENE', true],
        'extractor' => ['EVENT_EXTRACTION', true],
        'reviewer' => ['REVIEW', false],
        'rewrite' => ['REWRITE', true],
        'summary' => ['SUMMARY', true],
    ];
    foreach ($stages as $stage => [$environmentPrefix, $hasRetryTiers]) {
        // 环境文件和 config 不再承载预算；数据库初始记录、服务、UI 与架构文档共同描述同一合同。
        expect($env)
            ->not->toContain("{$environmentPrefix}_MAX_OUTPUT_TOKENS=")
            ->not->toContain("{$environmentPrefix}_REASONING_RESERVE_TOKENS=");
        expect($generationSettingMigration)->toContain("'{$stage}' => [");
        expect($generationSettings)->toContain('chapter_request_budgets');
        expect($settingsPage)->toContain('chapter_request_budgets');
        expect($prd)->toContain("`{$stage}`");
        expect($architecture)->toContain("`{$stage}`");
        expect($dataModel)->toContain("`{$stage}`");

        if ($hasRetryTiers) {
            expect($env)
                ->not->toContain("{$environmentPrefix}_RETRY_MAX_OUTPUT_TOKENS=")
                ->not->toContain("{$environmentPrefix}_FINAL_RETRY_MAX_OUTPUT_TOKENS=")
                ->not->toContain("{$environmentPrefix}_RETRY_REASONING_RESERVE_TOKENS=")
                ->not->toContain("{$environmentPrefix}_FINAL_RETRY_REASONING_RESERVE_TOKENS=");
        }
    }

    expect($env)
        ->not->toContain('ASSEMBLY_MAX_OUTPUT_TOKENS')
        ->not->toContain('ASSEMBLY_RETRY_MAX_OUTPUT_TOKENS')
        ->not->toContain('ASSEMBLY_FINAL_RETRY_MAX_OUTPUT_TOKENS');
    expect($generationConfig)
        ->not->toContain('chapter_request_budgets')
        ->not->toContain('chapter_repair_request_budgets');
    expect($prd)
        ->toContain('Plan Admission v2')
        ->toContain('completion_budget_exhausted');
    expect($architecture)
        ->toContain('Plan Admission v2')
        ->toContain('Provider 实际发送参数');
    expect($dataModel)
        ->toContain('schema_version=2')
        ->toContain('selected_request_budget');
});
