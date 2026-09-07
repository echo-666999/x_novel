<?php

return [
    'scene_context_token_budget' => (int) env('SCENE_CONTEXT_TOKEN_BUDGET', 12_000),
    'scene_max_output_tokens' => (int) env('SCENE_MAX_OUTPUT_TOKENS', 4_000),
    'previous_scene_tail_characters' => (int) env('PREVIOUS_SCENE_TAIL_CHARACTERS', 1_000),
    'assembly_max_output_tokens' => (int) env('ASSEMBLY_MAX_OUTPUT_TOKENS', 12_000),
    'event_extraction_max_output_tokens' => (int) env('EVENT_EXTRACTION_MAX_OUTPUT_TOKENS', 4_000),
    'review_max_output_tokens' => (int) env('REVIEW_MAX_OUTPUT_TOKENS', 4_000),
    'review_pass_score' => (float) env('REVIEW_PASS_SCORE', 80),
];
