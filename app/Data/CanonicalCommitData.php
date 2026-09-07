<?php

namespace App\Data;

final readonly class CanonicalCommitData
{
    public function __construct(
        public int $chapterId,
        public int $artifactId,
        public int $reviewId,
        public int $eventCandidateArtifactId,
        public int $statePatchArtifactId,
        public int $expectedStateVersion,
        public string $artifactChecksum,
    ) {}
}
