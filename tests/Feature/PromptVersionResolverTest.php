<?php

use App\AI\Data\AiRequest;
use App\AI\PromptVersionResolver;
use App\Enums\AiStage;

test('prompt versions follow the documented convention', function () {
    expect(app(PromptVersionResolver::class)->all())->toBe([
        'planner' => 'chapter-planner-v6',
        'writer' => 'scene-writer-v10',
        'assembler' => 'assembler-v8',
        'extractor' => 'event-extractor-v4',
        'reviewer' => 'reviewer-v8',
        'rewrite' => 'rewrite-v9',
        'summary' => 'summary-v1',
    ]);
});

test('each prompt stage resolves its current version', function (AiStage $stage, string $version) {
    expect(app(PromptVersionResolver::class)->resolve($stage))->toBe($version);
})->with([
    'planner' => [AiStage::Planner, 'chapter-planner-v6'],
    'writer' => [AiStage::Writer, 'scene-writer-v10'],
    'assembler' => [AiStage::Assembler, 'assembler-v8'],
    'extractor' => [AiStage::Extractor, 'event-extractor-v4'],
    'reviewer' => [AiStage::Reviewer, 'reviewer-v8'],
    'rewrite' => [AiStage::Rewrite, 'rewrite-v9'],
    'summary' => [AiStage::Summary, 'summary-v1'],
]);

test('a stage without a prompt version is rejected', function () {
    app(PromptVersionResolver::class)->resolve(AiStage::Embedding);
})->throws(InvalidArgumentException::class, 'Prompt version is not configured for stage [embedding].');

test('ai requests retain the resolved prompt version for run tracking', function () {
    $version = app(PromptVersionResolver::class)->resolve(AiStage::Planner);
    $request = new AiRequest(model: 'test-model', promptVersion: $version);

    expect($request->promptVersion)->toBe('chapter-planner-v6');
});
