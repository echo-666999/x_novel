<?php

namespace App\Console\Commands;

use App\Models\Novel;
use App\Services\StoryStateRebuilder;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class RebuildStoryState extends Command
{
    protected $signature = 'story:rebuild-state {novel : Novel ID} {--dry-run : 仅校验，不写入 Canonical Story State}';

    protected $description = '从 State Version 0 与有效 Story Events 重建并校验 Canonical Story State';

    public function handle(StoryStateRebuilder $rebuilder): int
    {
        $novel = Novel::query()->find($this->argument('novel'));

        if ($novel === null) {
            $this->error('找不到指定小说。');

            return self::FAILURE;
        }

        try {
            $result = $rebuilder->rebuild($novel);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('小说', "{$novel->title} (#{$novel->getKey()})");
        $this->components->twoColumnDetail('当前版本', 'v'.$result->currentVersion);
        $this->components->twoColumnDetail('重放事件', (string) $result->replayedEventCount);
        $this->components->twoColumnDetail('当前 checksum', $result->currentChecksum);
        $this->components->twoColumnDetail('重建 checksum', $result->rebuiltChecksum);
        $this->components->twoColumnDetail('状态差异', (string) count($result->changes));

        if ($result->matches()) {
            $this->info('校验通过：重建状态与当前 Canonical Story State 一致。');

            return self::SUCCESS;
        }

        $this->error('校验失败：重建 checksum 与当前 checksum 不一致。');
        $this->warn('当前命令默认为 dry-run，未写入或覆盖任何 Story State Version。');

        return self::FAILURE;
    }
}
