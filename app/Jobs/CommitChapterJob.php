<?php

namespace App\Jobs;

use App\Data\CanonicalCommitData;
use App\Enums\ArtifactType;
use App\Models\GenerationArtifact;
use App\Models\Review;
use App\Services\CanonicalCommitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;

class CommitChapterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $chapterId, public readonly int $reviewId)
    {
        $this->onQueue('generation');
    }

    public function handle(CanonicalCommitService $canonicalCommit): void
    {
        $canonicalCommit->commit($this->commitData());
    }

    private function commitData(): CanonicalCommitData
    {
        $review = Review::query()
            ->with(['artifact', 'generationRun'])
            ->findOrFail($this->reviewId);
        $artifactId = (int) data_get($review->artifact?->data, 'source_artifact_id', 0);
        $artifact = $artifactId > 0 ? GenerationArtifact::query()->find($artifactId) : null;
        $candidate = $artifact === null ? null : GenerationArtifact::query()
            ->where('type', ArtifactType::EventCandidate)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))
            ->latest('version')
            ->latest('id')
            ->get()
            ->first(fn (GenerationArtifact $candidate): bool => (int) data_get($candidate->data, 'source_artifact_id') === $artifact->getKey());
        $patch = $candidate === null ? null : GenerationArtifact::query()
            ->where('type', ArtifactType::StatePatch)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))
            ->latest('version')
            ->latest('id')
            ->get()
            ->first(fn (GenerationArtifact $patch): bool => (int) data_get($patch->data, 'source_artifact_id') === $candidate->getKey());

        if ($review->generationRun?->chapter_id !== $this->chapterId
            || $artifact === null
            || $candidate === null
            || $patch === null
            || $review->generationRun->state_version === null) {
            throw ValidationException::withMessages([
                'commit' => 'Canonical Commit 的冻结输入不完整。',
            ]);
        }

        return new CanonicalCommitData(
            chapterId: $this->chapterId,
            artifactId: $artifact->getKey(),
            reviewId: $review->getKey(),
            eventCandidateArtifactId: $candidate->getKey(),
            statePatchArtifactId: $patch->getKey(),
            expectedStateVersion: $review->generationRun->state_version,
            artifactChecksum: $artifact->checksum,
        );
    }
}
