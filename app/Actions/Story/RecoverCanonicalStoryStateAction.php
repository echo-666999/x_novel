<?php

namespace App\Actions\Story;

use App\Jobs\RefreshNovelProjectionJob;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Services\StoryStateRebuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecoverCanonicalStoryStateAction
{
    public function __construct(private readonly StoryStateRebuilder $rebuilder) {}

    public function execute(
        Novel $novel,
        int $expectedStateVersion,
        string $expectedCurrentChecksum,
        string $expectedRebuiltChecksum,
    ): StoryStateVersion {
        $version = DB::transaction(function () use ($novel, $expectedStateVersion, $expectedCurrentChecksum, $expectedRebuiltChecksum): StoryStateVersion {
            $lockedNovel = Novel::query()->lockForUpdate()->with('canonicalStateVersion')->findOrFail($novel->getKey());
            $current = $lockedNovel->canonicalStateVersion;

            if ($current === null
                || $current->version !== $expectedStateVersion
                || ! hash_equals($current->checksum, $expectedCurrentChecksum)) {
                throw ValidationException::withMessages([
                    'state' => 'State Version Conflict：当前 Canonical Story State 已变化。请关闭弹窗，重新校验后再恢复。',
                ]);
            }

            $result = $this->rebuilder->rebuild($lockedNovel);
            if (! hash_equals($result->rebuiltChecksum, $expectedRebuiltChecksum)) {
                throw ValidationException::withMessages([
                    'state' => '重建结果已变化。请重新打开校验报告并核对差异。',
                ]);
            }
            if ($result->matches()) {
                throw ValidationException::withMessages([
                    'state' => '当前 Canonical Story State 已与重建结果一致，无需恢复。',
                ]);
            }

            $nextVersion = ((int) $lockedNovel->storyStateVersions()->max('version')) + 1;
            $recovered = $lockedNovel->storyStateVersions()->create([
                'version' => $nextVersion,
                'chapter_id' => null,
                'state' => $result->rebuiltState,
                'checksum' => $result->rebuiltChecksum,
            ]);
            $lockedNovel->update(['canonical_state_version_id' => $recovered->getKey()]);

            return $recovered;
        }, 3);

        RefreshNovelProjectionJob::dispatch($version->novel_id, $version->getKey())->afterCommit();

        return $version;
    }
}
