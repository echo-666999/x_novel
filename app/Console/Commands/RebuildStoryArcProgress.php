<?php

namespace App\Console\Commands;

use App\Models\Novel;
use App\Services\StoryArcProgressProjector;
use Illuminate\Console\Command;

class RebuildStoryArcProgress extends Command
{
    protected $signature = 'story:rebuild-arc-progress {novel : Novel ID} {--execute : Persist the deterministic projection}';

    protected $description = 'Preview or rebuild Story Arc progress from accepted Canonical Story Events';

    public function handle(StoryArcProgressProjector $projector): int
    {
        $novel = Novel::query()->findOrFail((int) $this->argument('novel'));
        $rows = $novel->storyArcs()->orderBy('id')->get()->map(function ($arc) use ($projector): array {
            $summary = $projector->summary($arc);

            return [
                $arc->getKey(),
                $arc->title,
                round($arc->progress * 100, 2).'%',
                $summary['total'] === 0 ? '0%' : round($summary['canonical_completed'] / $summary['total'] * 100, 2).'%',
                $summary['canonical_completed'].'/'.$summary['total'],
                $summary['draft_pending'],
            ];
        });

        $this->components->twoColumnDetail('小说', "{$novel->title} (#{$novel->getKey()})");
        $this->components->twoColumnDetail('模式', $this->option('execute') ? 'execute' : 'dry-run');
        $this->table(['Arc ID', 'Story Arc', '当前进度', '重算进度', 'Canonical Beats', '草稿预计'], $rows);

        if (! $this->option('execute')) {
            $this->warn('历史 Plan 没有结构化 Beat 引用时不会推测进度；dry-run 未写入任何数据。');

            return self::SUCCESS;
        }

        $projector->refreshNovel($novel);
        $this->info('Story Arc Canonical Progress 已按正式事件重算。');

        return self::SUCCESS;
    }
}
