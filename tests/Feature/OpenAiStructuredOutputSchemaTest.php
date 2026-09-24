<?php

use App\AI\OpenAiStructuredOutputSchema;
use App\Services\ArcCompletionAuditRepairer;
use App\Services\CanonicalChapterSummaryService;
use App\Services\ChapterAssemblyPayload;
use App\Services\ChapterPlanPayload;
use App\Services\ChapterReviewer;
use App\Services\ChapterRewriteLengthRepairer;
use App\Services\ForeshadowingCoverageEvidenceRepairer;
use App\Services\NovelPlanner;
use App\Services\PlanCoverage;
use App\Services\ReviewDimensionAuditRepairer;
use App\Services\SceneDraftPayload;
use App\Services\SceneDraftStructureRepairer;
use App\Services\SceneRewritePayload;
use App\Services\StoryEventEvidenceRepairer;
use App\Services\StoryEventExtractor;

test('every ai response schema used by the generation pipeline satisfies openai strict mode', function () {
    $invoke = function (string $class, string $method, array $arguments = []): array {
        $reflection = new ReflectionMethod($class, $method);
        $target = $reflection->isStatic() ? null : app($class);

        return $reflection->invokeArgs($target, $arguments);
    };

    $schemas = [
        'chapter_plan' => ChapterPlanPayload::schema(),
        'scene_draft' => SceneDraftPayload::schema(),
        'chapter_assembly' => ChapterAssemblyPayload::schema(),
        'scene_rewrite' => SceneRewritePayload::schema(),
        'plan_coverage_repair' => PlanCoverage::schema(),
        'foreshadowing_coverage_repair' => ForeshadowingCoverageEvidenceRepairer::responseSchema(),
        'story_event_evidence_repair' => StoryEventEvidenceRepairer::responseSchema(2),
        'novel_plan' => $invoke(NovelPlanner::class, 'schema', [1]),
        'outline_regeneration' => app(NovelPlanner::class)->outlineSchema(1),
        'chapter_review' => $invoke(ChapterReviewer::class, 'schema'),
        'canonical_summary' => $invoke(CanonicalChapterSummaryService::class, 'schema'),
        'scene_structure_repair' => $invoke(SceneDraftStructureRepairer::class, 'schema'),
        'arc_completion_repair' => $invoke(ArcCompletionAuditRepairer::class, 'schema'),
        'review_dimension_repair' => $invoke(ReviewDimensionAuditRepairer::class, 'schema', ['facts', ['FACT_CONFLICT' => 'facts']]),
        'rewrite_length_repair' => $invoke(ChapterRewriteLengthRepairer::class, 'schema'),
        'story_event_extraction' => $invoke(StoryEventExtractor::class, 'responseSchema'),
    ];

    $checker = app(OpenAiStructuredOutputSchema::class);
    $errors = collect($schemas)
        ->map(fn (array $schema): array => $checker->errors($schema))
        ->filter()
        ->all();

    expect($errors)->toBe([]);
});
