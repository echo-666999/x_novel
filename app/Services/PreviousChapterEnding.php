<?php

namespace App\Services;

use App\Enums\ChapterStatus;
use App\Models\Chapter;

class PreviousChapterEnding
{
    /** @return array<string, mixed>|null */
    public function for(Chapter $chapter): ?array
    {
        $previous = Chapter::query()
            ->where('novel_id', $chapter->novel_id)
            ->where('status', ChapterStatus::Canonical)
            ->where('sequence', '<', $chapter->sequence)
            ->reorder('sequence', 'desc')
            ->with('canonicalArtifact:id,content')
            ->first();

        return $this->from($previous);
    }

    /** @return array<string, mixed>|null */
    public function from(?Chapter $chapter): ?array
    {
        if ($chapter === null) {
            return null;
        }

        $content = $chapter->canonicalArtifact?->content;
        $source = filled($content) ? 'canonical_artifact' : 'chapter_summary';
        $text = filled($content) ? $content : $chapter->summary;

        if (blank($text)) {
            return null;
        }

        $limit = max(100, (int) config('context.previous_chapter_ending_characters', 1_000));

        return [
            'chapter_id' => $chapter->getKey(),
            'sequence' => $chapter->sequence,
            'title' => $chapter->title,
            'source' => $source,
            'text' => mb_substr((string) $text, -$limit),
        ];
    }
}
