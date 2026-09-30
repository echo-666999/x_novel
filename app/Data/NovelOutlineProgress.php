<?php

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class NovelOutlineProgress
{
    /**
     * @param  array<string, NovelOutlineStageProgress>  $stages
     * @param  array<int, array<string, mixed>>  $runs
     * @param  array<int, array<string, mixed>>  $artifacts
     * @param  array<string, mixed>  $errorMetadata
     * @param  array<string, string>  $promptVersions
     */
    public function __construct(
        public ?int $batchId,
        public ?string $databaseStatus,
        public string $pageStatus,
        public string $pageStatusLabel,
        public ?string $currentStage,
        public ?string $currentStageLabel,
        public ?string $currentItemKey,
        public ?string $currentItemLabel,
        public array $stages,
        public ?int $latestAttempt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $finishedAt,
        public ?int $durationMilliseconds,
        public ?CarbonImmutable $currentRunStartedAt,
        public ?CarbonImmutable $currentRunFinishedAt,
        public ?int $currentRunDurationMilliseconds,
        public ?string $provider,
        public ?string $model,
        public ?string $reasoningEffort,
        public ?string $batchPromptVersion,
        public ?string $promptVersion,
        public array $promptVersions,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $technicalError,
        public array $errorMetadata,
        public ?string $recommendedAction,
        public bool $canResume,
        public ?int $currentRunId,
        public array $runs,
        public array $artifacts,
    ) {}

    public function isActive(): bool
    {
        return in_array($this->pageStatus, ['queued', 'running', 'retrying'], true);
    }
}
