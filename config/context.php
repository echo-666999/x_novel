<?php

return [
    'recent_chapter_window' => (int) env('CONTEXT_RECENT_CHAPTER_WINDOW', 10),
    'previous_chapter_ending_characters' => (int) env('CONTEXT_PREVIOUS_ENDING_CHARACTERS', 1_000),
    'memory_candidate_k' => (int) env('CONTEXT_MEMORY_CANDIDATE_K', 30),
    'memory_final_k' => (int) env('CONTEXT_MEMORY_FINAL_K', 10),
    'long_term_memory_token_budget' => (int) env('CONTEXT_LONG_TERM_MEMORY_TOKEN_BUDGET', 1_500),
    'memory_recency_window' => (int) env('CONTEXT_MEMORY_RECENCY_WINDOW', 100),
    'memory_semantic_dedup_threshold' => (float) env('CONTEXT_MEMORY_DEDUP_THRESHOLD', 0.98),
    'memory_ranking' => [
        'similarity' => 0.60,
        'salience' => 0.20,
        'recency' => 0.10,
        'entity_match' => 0.10,
    ],
];
