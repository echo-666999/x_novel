<?php

use App\AI\Data\AiRequest;
use App\AI\PromptVersionResolver;
use App\Enums\AiStage;

test('prompt versions follow the documented convention', function () {
    expect(app(PromptVersionResolver::class)->all())->toBe([
        'outline_foundation' => 'novel-outline-foundation-v6+natural-planning-v3',
        'outline_structure' => 'novel-outline-structure-v6+natural-planning-v3',
        'outline_arc_beats' => 'novel-outline-arc-beats-v8+natural-planning-v3',
        'outline_beat_detail' => 'novel-outline-beat-detail-v6+natural-planning-v3',
        'planner' => 'chapter-planner-v12+natural-planning-v3',
        'writer' => 'scene-writer-v16+natural-writing-v3',
        'extractor' => 'event-extractor-v9',
        'reviewer' => 'reviewer-v16+natural-reviewing-v3',
        'rewrite' => 'rewrite-v14+natural-writing-v3',
        'summary' => 'summary-v2+natural-summary-v3',
    ]);
});

test('each prompt stage resolves its current version', function (AiStage $stage, string $version) {
    expect(app(PromptVersionResolver::class)->resolve($stage))->toBe($version);
})->with([
    'planner' => [AiStage::Planner, 'chapter-planner-v12+natural-planning-v3'],
    'outline foundation' => [AiStage::OutlineFoundation, 'novel-outline-foundation-v6+natural-planning-v3'],
    'outline structure' => [AiStage::OutlineStructure, 'novel-outline-structure-v6+natural-planning-v3'],
    'outline arc beats' => [AiStage::OutlineArcBeats, 'novel-outline-arc-beats-v8+natural-planning-v3'],
    'outline beat detail' => [AiStage::OutlineBeatDetail, 'novel-outline-beat-detail-v6+natural-planning-v3'],
    'writer' => [AiStage::Writer, 'scene-writer-v16+natural-writing-v3'],
    'extractor' => [AiStage::Extractor, 'event-extractor-v9'],
    'reviewer' => [AiStage::Reviewer, 'reviewer-v16+natural-reviewing-v3'],
    'rewrite' => [AiStage::Rewrite, 'rewrite-v14+natural-writing-v3'],
    'summary' => [AiStage::Summary, 'summary-v2+natural-summary-v3'],
]);

test('effective prompt versions include only their configured policy while base versions remain available', function () {
    $resolver = app(PromptVersionResolver::class);

    expect($resolver->resolve(AiStage::Writer))
        ->toBe('scene-writer-v16+natural-writing-v3')
        ->and($resolver->resolveBase(AiStage::Writer))->toBe('scene-writer-v16')
        ->and($resolver->resolve(AiStage::Planner))->toBe('chapter-planner-v12+natural-planning-v3')
        ->and($resolver->resolve(AiStage::Extractor))->toBe($resolver->resolveBase(AiStage::Extractor));
});

test('provider tasks outside fixed ai stages also resolve their policy version', function () {
    expect(app(PromptVersionResolver::class)->resolveTask('outline_node_regeneration'))
        ->toBe('novel-outline-node-v6+natural-planning-v3');
});

test('a stage without a prompt version is rejected', function () {
    app(PromptVersionResolver::class)->resolve(AiStage::Embedding);
})->throws(InvalidArgumentException::class, 'Prompt version is not configured for stage [embedding].');

test('ai requests retain the resolved prompt version for run tracking', function () {
    $version = app(PromptVersionResolver::class)->resolve(AiStage::Planner);
    $request = new AiRequest(model: 'test-model', promptVersion: $version);

    expect($request->promptVersion)->toBe('chapter-planner-v12+natural-planning-v3');
});
