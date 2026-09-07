<?php

namespace App\Data;

use App\Enums\StateFindingSeverity;

final readonly class StateFinding
{
    /**
     * @param  array<int, array<string, mixed>>  $evidence
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $code,
        public StateFindingSeverity $severity,
        public string $message,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public array $evidence = [],
        public ?int $relatedFactId = null,
        public ?string $relatedStatePath = null,
        public array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'evidence' => $this->evidence,
            'related_fact_id' => $this->relatedFactId,
            'related_state_path' => $this->relatedStatePath,
            'metadata' => $this->metadata,
        ];
    }
}
