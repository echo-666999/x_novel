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
        // 新链提交必须显式冻结任务与控制版本，旧调用保持原合同。
        public ?int $taskId = null,
        public ?int $controlVersion = null,
    ) {}
}
