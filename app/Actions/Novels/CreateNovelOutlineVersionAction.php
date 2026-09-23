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
        // 内容在进入事务前先通过领域校验，任何来源都不能绕过同一套 Outline 契约。
        $this->validator->assertValid($content);

        return DB::transaction(function () use ($novel, $content, $source, $basedOn, $creator): NovelOutline {
            // 锁定小说以串行分配版本号，避免并发请求创建相同 version。
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if ($basedOn !== null && $basedOn->novel_id !== $lockedNovel->getKey()) {
                throw ValidationException::withMessages([
                    'based_on_outline_id' => '基础 Outline Version 不属于当前 Novel。',
                ]);
            }

            $version = ((int) $lockedNovel->outlines()->max('version')) + 1;

            // 每次编辑都创建不可变新版本；checksum 用于采用前验证冻结内容没有漂移。
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
                // 只保留最新 Draft 作为待采用候选，旧版本仍留库用于追溯。
                $basedOn->update(['status' => NovelOutlineStatus::Superseded]);
            }

            return $outline;
        });
    }
}
