<?php

namespace App\Console\Commands;

use App\Models\Novel;
use App\Services\CanonicalChapterSummaryService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class BackfillCanonicalChapterSummaries extends Command
{
    protected $signature = 'novel:backfill-chapter-summaries
        {novel : Novel ID}
        {--execute : Explicitly generate and persist missing Canonical Chapter summaries}
        {--plan-hash= : Exact hash printed by the reviewed dry-run}';

    protected $description = 'Preview or explicitly backfill missing summaries from current Canonical Chapter artifacts';

    public function handle(CanonicalChapterSummaryService $summaries): int
    {
        $novel = Novel::query()->findOrFail((int) $this->argument('novel'));

        try {
            if (! $this->option('execute')) {
                $plan = $summaries->preview($novel);
                $this->line(json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $this->newLine();
                $this->warn('dry-run 未调用 AI、未写入数据。审核后使用 --execute --plan-hash=<hash>。');

                return self::SUCCESS;
            }

            $planHash = trim((string) $this->option('plan-hash'));
            if ($planHash === '') {
                $this->error('--execute 必须同时提供 dry-run 输出的 --plan-hash。');

                return self::FAILURE;
            }

            $result = $summaries->execute($novel, $planHash);
            $this->info("摘要补写完成：新生成 {$result['generated']}，复用 {$result['reused']}，因 Canonical Artifact 变化未回写 {$result['stale']}。");

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('摘要补写失败：'.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
