<?php

namespace App\Actions\Novels;

use App\Data\NormalizedNovelOutline;
use App\Enums\NovelOutlineSource;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\User;

/**
 * 兼容现有调用点的薄入口；所有保存统一委托给关系化版本创建 Action。
 */
class CreateNovelOutlineVersionAction
{
    public function __construct(private readonly CreateNormalizedNovelOutlineVersionAction $createNormalized) {}

    /** @param array<string, mixed>|NormalizedNovelOutline $outline 委托统一 Action 创建关系化版本。 */
    public function handle(
        Novel $novel,
        array|NormalizedNovelOutline $outline,
        NovelOutlineSource $source = NovelOutlineSource::Manual,
        ?NovelOutline $basedOn = null,
        ?User $creator = null,
        ?GenerationArtifact $sourceArtifact = null,
    ): NovelOutline {
        return $this->createNormalized->handle(
            $novel,
            $outline,
            $source,
            $basedOn,
            $creator,
            $sourceArtifact,
        );
    }
}
