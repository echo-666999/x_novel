<?php

namespace App\Services;

use App\AI\AiSettingsService;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\UsageRecord;
use Illuminate\Support\Facades\DB;

final readonly class NovelOperationsOverview
{
    public function __construct(
        private AiSettingsService $settings,
        private DueForeshadowingQuery $dueForeshadowings,
        private GenerationFailurePolicy $failurePolicy,
        private NovelGenerationReadiness $readiness,
        private StalledRunRecoveryService $stalledRuns,
    ) {}

    /** @return array<string, mixed> */
    public function progress(Novel $novel): array
    {
        $volume = $novel->volumes()->where('status', VolumeStatus::Active)->orderBy('sequence')->first();

        return [
            'target_words' => $novel->target_words,
            'current_words' => (int) $novel->chapters()->where('status', ChapterStatus::Canonical)->sum('word_count'),
            'current_volume' => $volume === null ? '尚无 Active Volume' : "第 {$volume->sequence} 卷 · {$volume->title}",
            'current_chapter' => $novel->current_chapter_sequence > 0 ? "第 {$novel->current_chapter_sequence} 章" : '尚无正式章节',
            'current_state_version' => $novel->canonicalStateVersion === null ? '故事状态: 未初始化' : '当前版本: '.$novel->canonicalStateVersion->version,
            'generation_status' => $novel->status->getLabel(),
            'auto_generation_status' => (bool) data_get($novel->settings, 'auto_generate', false) ? 'Auto: ON' : 'Auto: OFF',
            'pause_position' => 'Paused at: '.data_get($novel->settings, 'pause.label', '等待下一阶段'),
            'auto_stop_reason' => data_get($novel->settings, 'auto_stop.reason') ?: '—',
            'auto_stop_recommended_action' => data_get($novel->settings, 'auto_stop.recommended_action') ?: '—',
        ];
    }

    /** @return array<string, mixed> */
    public function quality(Novel $novel): array
    {
        $todayCost = (float) UsageRecord::query()
            ->where('novel_id', $novel->getKey())
            ->whereDate('created_at', today())
            ->sum('estimated_cost');
        $reviewedChapterIds = Chapter::query()
            ->where('novel_id', $novel->getKey())
            ->whereHas('generationRuns.review')
            ->orderByDesc('sequence')
            ->limit(30)
            ->pluck('id');
        $firstReviews = Review::query()
            ->join('generation_runs', 'generation_runs.id', '=', 'reviews.generation_run_id')
            ->whereIn('generation_runs.chapter_id', $reviewedChapterIds)
            ->orderBy('reviews.id')
            ->get(['reviews.id', 'generation_runs.chapter_id', 'reviews.decision'])
            ->unique('chapter_id');
        $canonicalChapterIds = Chapter::query()
            ->where('novel_id', $novel->getKey())
            ->where('status', ChapterStatus::Canonical)
            ->orderByDesc('sequence')
            ->limit(30)
            ->pluck('id');
        $rewrittenChapters = $canonicalChapterIds->isEmpty() ? 0 : GenerationArtifact::query()
            ->where('type', ArtifactType::RewriteDraft)
            ->whereHas('generationRun', fn ($query) => $query->whereIn('chapter_id', $canonicalChapterIds))
            ->join('generation_runs', 'generation_runs.id', '=', 'generation_artifacts.generation_run_id')
            ->distinct('generation_runs.chapter_id')
            ->count('generation_runs.chapter_id');
        $latestRuns = GenerationRun::query()
            ->where('novel_id', $novel->getKey())
            ->whereIn('id', DB::table('generation_runs')
                ->selectRaw('MAX(id)')
                ->where('novel_id', $novel->getKey())
                ->groupBy('stage', 'scope_type', 'scope_id'))
            ->get();
        $latestReviews = Review::query()
            ->whereIn('id', DB::table('reviews')
                ->join('generation_runs', 'generation_runs.id', '=', 'reviews.generation_run_id')
                ->where('generation_runs.novel_id', $novel->getKey())
                ->whereNotNull('generation_runs.chapter_id')
                ->groupBy('generation_runs.chapter_id')
                ->selectRaw($this->latestReviewIdExpression()))
            ->get();

        return [
            'today_cost' => $this->currency().' '.number_format($todayCost, 4),
            'review_pass_rate' => $this->rate(
                $firstReviews->where('decision', ReviewDecision::Pass)->count(),
                $firstReviews->count(),
            ),
            'rewrite_rate' => $this->rate($rewrittenChapters, $canonicalChapterIds->count()),
            'due_foreshadowings' => $this->dueForeshadowings->countForNovel($novel),
            'failed_runs' => $latestRuns->filter(fn (GenerationRun $run): bool => $run->status === RunStatus::Failed || $this->stalledRuns->isStalled($run))->count(),
            'blocked_reviews' => $latestReviews->where('decision', ReviewDecision::Block)->count(),
            'needs_attention_reviews' => $latestReviews->where('decision', ReviewDecision::NeedsAttention)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function pipeline(Novel $novel): array
    {
        $chapter = $novel->chapters()
            ->whereIn('status', [ChapterStatus::Planned, ChapterStatus::Generating, ChapterStatus::Review, ChapterStatus::Rewrite, ChapterStatus::Blocked])
            ->orderByDesc('sequence')
            ->first() ?? $novel->chapters()->orderByDesc('sequence')->first();
        $run = GenerationRun::query()
            ->where('novel_id', $novel->getKey())
            ->when($chapter !== null, fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->latest('id')
            ->first();
        $review = $chapter?->latestReview()->first();
        $failure = $run?->status === RunStatus::Failed ? $this->failurePolicy->forRun($run) : null;
        [$actionKey, $actionLabel] = $this->nextAction($novel, $chapter, $run, $review, $failure?->metadata['category'] ?? null, $failure?->retryable ?? false);

        return [
            'chapter_id' => $chapter?->getKey(),
            'chapter' => $chapter === null ? '无当前章节' : "第 {$chapter->sequence} 章 · {$chapter->title}",
            'run_id' => $run?->getKey(),
            'run' => $run === null ? '无 Generation Run' : '#'.$run->getKey(),
            'stage' => $run?->stage->getLabel() ?? '等待启动',
            'status' => $run?->status->getLabel() ?? $novel->status->getLabel(),
            'elapsed' => $this->elapsed($run),
            'stop_reason' => $run?->error_message
                ?? data_get($novel->settings, 'auto_stop.reason')
                ?? '—',
            'failure_category' => $failure?->metadata['category'] ?? '—',
            'next_action_key' => $actionKey,
            'next_action' => $actionLabel,
        ];
    }

    /** @return array{string, string} */
    private function nextAction(Novel $novel, ?Chapter $chapter, ?GenerationRun $run, ?Review $review, ?string $failureCategory, bool $retryable): array
    {
        if ($novel->status === NovelStatus::Paused) {
            return ['resume', '继续'];
        }

        if (in_array($novel->status, [NovelStatus::Draft, NovelStatus::Planning], true)) {
            return $this->readiness->isReady($novel)
                ? ['start_generation', '开始正文生成']
                : ['complete_planning', '完成规划'];
        }

        if ($chapter !== null && $review?->decision === ReviewDecision::Pass && $chapter->canonical_artifact_id === null) {
            return ['commit', '提交正式章节'];
        }

        if ($chapter !== null && in_array($review?->decision, [ReviewDecision::NeedsAttention, ReviewDecision::Block], true)) {
            return ['handle_review', '处理审校'];
        }

        if ($run?->status === RunStatus::Failed) {
            if ($failureCategory === 'worker_lost') {
                return ['recover', '恢复'];
            }

            return $retryable
                ? ['retry', '重试失败阶段']
                : ['inspect_failure', '检查失败阶段'];
        }

        if ($chapter !== null && ($run === null || in_array($run->status, [RunStatus::Queued, RunStatus::Running], true))) {
            return ['view_chapter', '查看当前章节'];
        }

        if (in_array($novel->status, [NovelStatus::Generating, NovelStatus::Completing], true)) {
            return ['generate_next', '生成下一章'];
        }

        return ['view_novel', '查看小说'];
    }

    private function elapsed(?GenerationRun $run): string
    {
        if ($run?->started_at === null) {
            return '—';
        }

        $end = $run->finished_at ?? now();
        $seconds = (int) $run->started_at->diffInSeconds($end);

        return $seconds >= 60
            ? intdiv($seconds, 60).'m '.($seconds % 60).'s'
            : $seconds.'s';
    }

    private function rate(int $part, int $total): string
    {
        return $total === 0 ? '—' : number_format(($part / $total) * 100, 1)."% ({$part}/{$total})";
    }

    private function currency(): string
    {
        return (string) data_get($this->settings->costSettings(), 'currency', 'USD');
    }

    private function latestReviewIdExpression(): string
    {
        $grammar = DB::connection()->getQueryGrammar();

        return 'MAX('.$grammar->wrapTable('reviews').'.'.$grammar->wrap('id').')';
    }
}
