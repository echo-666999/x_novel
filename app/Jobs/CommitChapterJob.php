<?php

namespace App\Jobs;

use App\Data\CanonicalCommitData;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Models\GenerationArtifact;
use App\Models\Review;
use App\Services\CanonicalCommitService;
use App\Services\GenerationFailurePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Canonical Commit 只重试临时基础设施故障，确定性校验失败必须立即停止。 */
class CommitChapterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $timeout = 60;

    /** 使用正式提交阶段的统一最大尝试次数。 */
    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::Commit);
    }

    /** 使用正式提交阶段的统一退避间隔。 */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::Commit);
    }

    public function __construct(public readonly int $chapterId, public readonly int $reviewId)
    {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'chapter:'.$this->chapterId;
    }

    public function handle(CanonicalCommitService $canonicalCommit): void
    {
        if ($this->stopWhenChapterWasDeleted($this->chapterId)) {
            return;
        }

        try {
            $canonicalCommit->commit($this->commitData());
        } catch (Throwable $exception) {
            if (app(GenerationFailurePolicy::class)->shouldQueueRetry($exception, GenerationStage::Commit)) {
                throw $exception;
            }

            $this->fail($exception);
        } finally {
            $this->releaseGenerationDispatch();
        }
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
