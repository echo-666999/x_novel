<?php

namespace App\Console\Commands;

use App\Actions\Chapters\RecoverChapterForBibleChangeAction;
use App\Models\Novel;
use Illuminate\Console\Command;

class RecoverBibleChapter extends Command
{
    protected $signature = 'novel:recover-bible-chapter
        {novel : Novel ID}
        {chapter=12 : Chapter sequence}
        {--expected-bible= : Required current Bible version}
        {--expected-state= : Required Canonical State version}
        {--target-pov=第一人称 : POV for the new immutable Bible version}
        {--execute : Create the Bible version, reset the chapter lineage and queue replanning}
        {--plan-hash= : Exact hash printed by the reviewed dry-run}
        {--actor= : Existing user ID required for execute}';

    protected $description = 'Preview or explicitly execute a Bible-version chapter recovery';

    public function handle(RecoverChapterForBibleChangeAction $recovery): int
    {
        $novel = Novel::query()->findOrFail((int) $this->argument('novel'));
        $chapter = (int) $this->argument('chapter');
        $expectedBible = (int) $this->option('expected-bible');
        $expectedState = (int) $this->option('expected-state');
        $targetPov = (string) $this->option('target-pov');

        if ($expectedBible < 1 || $expectedState < 0) {
            $this->error('--expected-bible 和 --expected-state 必须显式提供有效版本。');

            return self::FAILURE;
        }

        if (! $this->option('execute')) {
            $plan = $recovery->preview($novel, $chapter, $expectedBible, $expectedState, $targetPov);
            $this->line(json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->newLine();
            $this->warn('dry-run 未写入数据。审核后使用 --execute --plan-hash=<hash> --actor=<user id>。');

            return self::SUCCESS;
        }

        $planHash = (string) $this->option('plan-hash');
        $actorId = (int) $this->option('actor');
        if ($planHash === '' || $actorId < 1) {
            $this->error('--execute 必须同时提供 dry-run 的 --plan-hash 和有效 --actor。');

            return self::FAILURE;
        }

        $result = $recovery->execute($novel, $chapter, $expectedBible, $expectedState, $targetPov, $planHash, $actorId);
        $this->info("恢复状态：{$result['status']}；Bible v{$result['bible_version']}；Chapter #{$result['chapter_id']}；重新规划已".($result['dispatched'] ? '派发' : '存在或已派发').'。');

        return self::SUCCESS;
    }
}
