<?php

test('chapter capacity configuration documentation and environment example match the frozen budget contract', function () {
    $root = dirname(__DIR__, 2);
    $env = file_get_contents($root.'/.env.example');
    $generationConfig = file_get_contents($root.'/config/generation.php');
    $prd = file_get_contents($root.'/docs/PRD.md');
    $architecture = file_get_contents($root.'/docs/architecture/generation-pipeline.md');
    $dataModel = file_get_contents($root.'/docs/architecture/data-model.md');

    foreach ([$env, $generationConfig, $prd, $architecture, $dataModel] as $content) {
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
        // 环境示例、运行配置和架构文档必须共同描述同一套 Stage 预算合同。
        expect($env)
            ->toContain("{$environmentPrefix}_MAX_OUTPUT_TOKENS=")
            ->toContain("{$environmentPrefix}_REASONING_RESERVE_TOKENS=");
        expect($generationConfig)
            ->toContain("'{$stage}' => [")
            ->toContain("{$environmentPrefix}_MAX_OUTPUT_TOKENS")
            ->toContain("{$environmentPrefix}_REASONING_RESERVE_TOKENS");
        expect($prd)->toContain("`{$stage}`");
        expect($architecture)->toContain("`{$stage}`");
        expect($dataModel)->toContain("`{$stage}`");

        if ($hasRetryTiers) {
            expect($env)
                ->toContain("{$environmentPrefix}_RETRY_MAX_OUTPUT_TOKENS=")
                ->toContain("{$environmentPrefix}_FINAL_RETRY_MAX_OUTPUT_TOKENS=")
                ->toContain("{$environmentPrefix}_RETRY_REASONING_RESERVE_TOKENS=")
                ->toContain("{$environmentPrefix}_FINAL_RETRY_REASONING_RESERVE_TOKENS=");
        }
    }

    expect($env)
        ->not->toContain('ASSEMBLY_MAX_OUTPUT_TOKENS')
        ->not->toContain('ASSEMBLY_RETRY_MAX_OUTPUT_TOKENS')
        ->not->toContain('ASSEMBLY_FINAL_RETRY_MAX_OUTPUT_TOKENS');
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
