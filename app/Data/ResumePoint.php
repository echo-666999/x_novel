<?php

namespace App\Data;

final readonly class ResumePoint
{
    public function __construct(
        public string $key,
        public string $label,
        public ?int $chapterId = null,
        public ?int $sceneId = null,
        public ?int $reviewId = null,
        public bool $canResume = true,
    ) {}
}
