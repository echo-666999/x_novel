<?php

namespace App\Services;

use App\AI\AiSettingsService;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\UsageRecord;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class DashboardOperationsOverview
{
    public function __construct(private AiSettingsService $settings) {}

    /** @return array<string, int|string> */
    public function stats(): array
    {
        $usage = UsageRecord::query()
            ->whereDate('created_at', today())
            ->selectRaw('COUNT(*) as requests, COALESCE(SUM(estimated_cost), 0) as cost, COALESCE(SUM(input_tokens + output_tokens), 0) as tokens')
            ->first();
        $latestRuns = GenerationRun::query()->whereIn('id', $this->latestRunIds())->get(['status']);
        $latestReviews = Review::query()->whereIn('id', $this->latestReviewIds())->get(['decision']);

        return [
            'active_novels' => Novel::query()->whereIn('status', [NovelStatus::Generating, NovelStatus::Paused, NovelStatus::Completing])->count(),
            'running_chapters' => GenerationRun::query()
                ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
                ->whereNotNull('chapter_id')
                ->distinct()
                ->count('chapter_id'),
            'today_cost' => $this->currency().' '.number_format((float) ($usage?->cost ?? 0), 4),
            'today_tokens' => (int) ($usage?->tokens ?? 0),
            'has_usage' => (int) ($usage?->requests ?? 0) > 0 ? 1 : 0,
            'failed' => $latestRuns->where('status', RunStatus::Failed)->count(),
            'blocked' => $latestReviews->where('decision', ReviewDecision::Block)->count(),
            'needs_attention' => $latestReviews->where('decision', ReviewDecision::NeedsAttention)->count(),
        ];
    }

    public function currentNovel(): ?Novel
    {
        return Novel::query()
            ->whereIn('status', [NovelStatus::Generating, NovelStatus::Paused, NovelStatus::Completing, NovelStatus::Planning])
            ->latest('updated_at')
            ->first();
    }

    /** @return array<int, array<string, mixed>> */
    public function recentRuns(int $limit = 10): array
    {
        return GenerationRun::query()
            ->with(['novel:id,title', 'chapter:id,novel_id,sequence,title'])
            ->withSum('usageRecords', 'estimated_cost')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (GenerationRun $run): array => [
                'run_id' => $run->getKey(),
                'novel_id' => $run->novel_id,
                'chapter_id' => $run->chapter_id,
                'novel' => $run->novel?->title ?? '已删除小说',
                'chapter' => $run->chapter === null ? '—' : '第 '.$run->chapter->sequence.' 章',
                'stage' => $run->stage->getLabel(),
                'status' => $run->status->getLabel(),
                'is_failed' => $run->status === RunStatus::Failed,
                'elapsed' => $this->elapsed($run),
                'cost' => $this->currency().' '.number_format((float) $run->usage_records_sum_estimated_cost, 4),
                'time' => $run->created_at?->format('Y-m-d H:i') ?? '—',
            ])
            ->all();
    }

    private function latestRunIds(): Builder
    {
        return DB::table('generation_runs')->selectRaw('MAX(id)')->groupBy('stage', 'scope_type', 'scope_id');
    }

    private function latestReviewIds(): Builder
    {
        $grammar = DB::connection()->getQueryGrammar();
        $reviews = $grammar->wrapTable('reviews');
        $id = $grammar->wrap('id');

        return DB::table('reviews')
            ->join('generation_runs', 'generation_runs.id', '=', 'reviews.generation_run_id')
            ->whereNotNull('generation_runs.chapter_id')
            ->groupBy('generation_runs.chapter_id')
            ->selectRaw("MAX({$reviews}.{$id})");
    }

    private function elapsed(GenerationRun $run): string
    {
        if ($run->started_at === null) {
            return '—';
        }

        $seconds = (int) $run->started_at->diffInSeconds($run->finished_at ?? now());

        return $seconds >= 60 ? intdiv($seconds, 60).'m '.($seconds % 60).'s' : $seconds.'s';
    }

    private function currency(): string
    {
        return (string) data_get($this->settings->costSettings(), 'currency', 'USD');
    }
}
