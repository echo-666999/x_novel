<?php

use App\AI\OpenAiStructuredOutputSchema;
use App\Services\ArcCompletionAuditRepairer;
use App\Services\CanonicalChapterSummaryService;
use App\Services\ChapterPlanPayload;
use App\Services\ChapterReviewer;
use App\Services\ForeshadowingCoverageEvidenceRepairer;
use App\Services\NovelOutlinePipeline;
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
        'scene_rewrite' => SceneRewritePayload::schema(),
        'plan_coverage_repair' => PlanCoverage::schema(),
        'foreshadowing_coverage_repair' => ForeshadowingCoverageEvidenceRepairer::responseSchema(),
        'story_event_evidence_repair' => StoryEventEvidenceRepairer::responseSchema(2),
        'outline_foundation' => app(NovelOutlinePipeline::class)->foundationSchema(),
        'outline_skeleton' => app(NovelOutlinePipeline::class)->skeletonSchema(1),
        'outline_beat_detail' => app(NovelOutlinePipeline::class)->beatDetailSchema(),
        'outline_regeneration_volume' => app(NovelPlanner::class)->regenerationSchema('volume'),
        'outline_regeneration_arc' => app(NovelPlanner::class)->regenerationSchema('arc'),
        'outline_regeneration_beat' => app(NovelPlanner::class)->regenerationSchema('beat'),
        'outline_regeneration_milestone' => app(NovelPlanner::class)->regenerationSchema('milestone'),
        'chapter_review' => $invoke(ChapterReviewer::class, 'schema'),
        'canonical_summary' => $invoke(CanonicalChapterSummaryService::class, 'schema'),
        'scene_structure_repair' => $invoke(SceneDraftStructureRepairer::class, 'schema'),
        'arc_completion_repair' => $invoke(ArcCompletionAuditRepairer::class, 'schema'),
        'review_dimension_repair' => $invoke(ReviewDimensionAuditRepairer::class, 'schema', ['facts', ['FACT_CONFLICT' => 'facts']]),
        'story_event_extraction' => $invoke(StoryEventExtractor::class, 'responseSchema'),
    ];

    $checker = app(OpenAiStructuredOutputSchema::class);
    $errors = collect($schemas)
        ->map(fn (array $schema): array => $checker->errors($schema))
        ->filter()
        ->all();

    expect($errors)->toBe([]);
});
