<?php

namespace App\Jobs;

use App\Actions\Generation\CheckNextAction;
use App\Enums\ChapterStatus;
use App\Models\Chapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ContinueAutoGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $chapterId,
        public readonly int $canonicalArtifactId,
        public readonly int $stateVersionId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "continue-generation:{$this->chapterId}:{$this->canonicalArtifactId}:{$this->stateVersionId}";
    }

    public function handle(CheckNextAction $checkNextAction): void
    {
        $chapter = Chapter::query()->with('novel')->findOrFail($this->chapterId);
        $novel = $chapter->novel;

        if ($chapter->status !== ChapterStatus::Canonical
            || (int) $chapter->canonical_artifact_id !== $this->canonicalArtifactId
            || (int) $novel->canonical_state_version_id !== $this->stateVersionId
            || blank($chapter->summary)
            || $chapter->sequence !== $novel->current_chapter_sequence) {
            return;
        }

        $checkNextAction->handle($novel, $chapter->getKey());
    }
}
