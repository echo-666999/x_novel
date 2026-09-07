<?php

return [
    'recent_chapter_window' => (int) env('CONTEXT_RECENT_CHAPTER_WINDOW', 10),
    'previous_chapter_ending_characters' => (int) env('CONTEXT_PREVIOUS_ENDING_CHARACTERS', 1_000),
    'memory_candidate_k' => (int) env('CONTEXT_MEMORY_CANDIDATE_K', 30),
    'memory_final_k' => (int) env('CONTEXT_MEMORY_FINAL_K', 10),
];
