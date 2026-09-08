<?php

use App\AI\Data\AiRequest;
use App\AI\PromptVersionResolver;
use App\Enums\AiStage;

test('prompt versions follow the documented convention', function () {
    expect(app(PromptVersionResolver::class)->all())->toBe([
        'planner' => 'chapter-planner-v2',
        'writer' => 'scene-writer-v2',
        'assembler' => 'assembler-v2',
        'extractor' => 'event-extractor-v1',
        'reviewer' => 'reviewer-v1',
        'rewrite' => 'rewrite-v1',
        'summary' => 'summary-v1',
    ]);
});

test('each prompt stage resolves its current version', function (AiStage $stage, string $version) {
    expect(app(PromptVersionResolver::class)->resolve($stage))->toBe($version);
})->with([
    'planner' => [AiStage::Planner, 'chapter-planner-v2'],
    'writer' => [AiStage::Writer, 'scene-writer-v2'],
    'assembler' => [AiStage::Assembler, 'assembler-v2'],
    'extractor' => [AiStage::Extractor, 'event-extractor-v1'],
    'reviewer' => [AiStage::Reviewer, 'reviewer-v1'],
    'rewrite' => [AiStage::Rewrite, 'rewrite-v1'],
    'summary' => [AiStage::Summary, 'summary-v1'],
]);

test('a stage without a prompt version is rejected', function () {
    app(PromptVersionResolver::class)->resolve(AiStage::Embedding);
})->throws(InvalidArgumentException::class, 'Prompt version is not configured for stage [embedding].');

test('ai requests retain the resolved prompt version for run tracking', function () {
    $version = app(PromptVersionResolver::class)->resolve(AiStage::Planner);
    $request = new AiRequest(model: 'test-model', promptVersion: $version);

    expect($request->promptVersion)->toBe('chapter-planner-v2');
});
