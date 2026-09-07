<?php

return [
    'recent_chapter_window' => (int) env('CONTEXT_RECENT_CHAPTER_WINDOW', 10),
    'previous_chapter_ending_characters' => (int) env('CONTEXT_PREVIOUS_ENDING_CHARACTERS', 1_000),
];
