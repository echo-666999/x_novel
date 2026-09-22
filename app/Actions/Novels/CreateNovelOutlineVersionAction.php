<?php

namespace App\Actions\Novels;

use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\User;
use App\Services\NovelOutlineChecksum;
use App\Services\NovelOutlineValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateNovelOutlineVersionAction
{
    public function __construct(
        private readonly NovelOutlineValidator $validator,
        private readonly NovelOutlineChecksum $checksum,
    ) {}

    /** @param array<string, mixed> $content */
    public function handle(
        Novel $novel,
        array $content,
        NovelOutlineSource $source = NovelOutlineSource::Manual,
        ?NovelOutline $basedOn = null,
        ?User $creator = null,
    ): NovelOutline {
        $this->validator->assertValid($content);

        return DB::transaction(function () use ($novel, $content, $source, $basedOn, $creator): NovelOutline {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if ($basedOn !== null && $basedOn->novel_id !== $lockedNovel->getKey()) {
                throw ValidationException::withMessages([
                    'based_on_outline_id' => '基础 Outline Version 不属于当前 Novel。',
                ]);
            }

            $version = ((int) $lockedNovel->outlines()->max('version')) + 1;

            $outline = $lockedNovel->outlines()->create([
                'version' => $version,
                'status' => NovelOutlineStatus::Draft,
                'source' => $source,
                'schema_version' => 1,
                'content' => $content,
                'checksum' => $this->checksum->for($content),
                'based_on_outline_id' => $basedOn?->getKey(),
                'created_by' => $creator?->getKey(),
                'applied_at' => null,
            ]);

            if ($basedOn?->status === NovelOutlineStatus::Draft) {
                $basedOn->update(['status' => NovelOutlineStatus::Superseded]);
            }

            return $outline;
        });
    }
}
