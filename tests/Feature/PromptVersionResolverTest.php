<?php

use App\AI\Data\AiRequest;
use App\AI\NarrativeProsePolicy;
use App\AI\PromptVersionResolver;
use App\Enums\AiStage;

test('prompt versions follow the documented convention', function () {
    expect(app(PromptVersionResolver::class)->all())->toBe([
        'planner' => 'chapter-planner-v10+natural-prose-v1',
        'writer' => 'scene-writer-v15+natural-prose-v1',
        'assembler' => 'assembler-v12+natural-prose-v1',
        'extractor' => 'event-extractor-v8',
        'reviewer' => 'reviewer-v15+natural-prose-v1',
        'rewrite' => 'rewrite-v13+natural-prose-v1',
        'summary' => 'summary-v2+natural-prose-v1',
    ]);
});

test('each prompt stage resolves its current version', function (AiStage $stage, string $version) {
    expect(app(PromptVersionResolver::class)->resolve($stage))->toBe($version);
})->with([
    'planner' => [AiStage::Planner, 'chapter-planner-v10+natural-prose-v1'],
    'writer' => [AiStage::Writer, 'scene-writer-v15+natural-prose-v1'],
    'assembler' => [AiStage::Assembler, 'assembler-v12+natural-prose-v1'],
    'extractor' => [AiStage::Extractor, 'event-extractor-v8'],
    'reviewer' => [AiStage::Reviewer, 'reviewer-v15+natural-prose-v1'],
    'rewrite' => [AiStage::Rewrite, 'rewrite-v13+natural-prose-v1'],
    'summary' => [AiStage::Summary, 'summary-v2+natural-prose-v1'],
]);

test('effective prose versions include the narrative policy version while base versions remain available', function () {
    $resolver = app(PromptVersionResolver::class);

    expect($resolver->resolve(AiStage::Writer))
        ->toBe('scene-writer-v15+'.NarrativeProsePolicy::VERSION)
        ->and($resolver->resolveBase(AiStage::Writer))->toBe('scene-writer-v15')
        ->and($resolver->resolve(AiStage::Extractor))->toBe($resolver->resolveBase(AiStage::Extractor));
});

test('a stage without a prompt version is rejected', function () {
    app(PromptVersionResolver::class)->resolve(AiStage::Embedding);
})->throws(InvalidArgumentException::class, 'Prompt version is not configured for stage [embedding].');

test('ai requests retain the resolved prompt version for run tracking', function () {
    $version = app(PromptVersionResolver::class)->resolve(AiStage::Planner);
    $request = new AiRequest(model: 'test-model', promptVersion: $version);

    expect($request->promptVersion)->toBe('chapter-planner-v10+natural-prose-v1');
});
