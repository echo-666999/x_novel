<?php

namespace App\Console\Commands;

use App\Actions\Novels\RetireLegacyNovelOutlineBatchAction;
use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Services\NovelOutlinePipeline;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class RetireLegacyNovelOutlineBatch extends Command
{
    protected $signature = 'novel:outline-retire-legacy-batch
        {batch : Legacy Outline batch Generation Run ID}
        {--execute : Persist the cancellation after showing the impact}
        {--reason=OGR-007 pipeline contract upgrade : Audit reason stored with the batch}';

    protected $description = 'Inspect or retire one unfinished legacy v2 Outline batch without deleting its history';

    public function handle(RetireLegacyNovelOutlineBatchAction $retire): int
    {
        try {
            $batch = GenerationRun::query()->findOrFail((int) $this->argument('batch'));
            $summary = $this->summary($batch);

            $this->components->twoColumnDetail('Batch', '#'.$batch->getKey());
            $this->components->twoColumnDetail('Novel', "{$summary['novel_title']} (#{$batch->novel_id})");
            $this->components->twoColumnDetail('Status', $batch->status->value);
            $this->components->twoColumnDetail('Prompt Version', (string) $batch->prompt_version);
            $this->components->twoColumnDetail('Provider / Model', "{$batch->provider} / {$batch->model_policy}");
            $this->components->twoColumnDetail('Child Runs', implode(', ', $summary['child_run_ids']) ?: 'none');
            $this->components->twoColumnDetail('Successful Artifacts', implode(', ', $summary['successful_artifacts']) ?: 'none');
            $this->components->twoColumnDetail('Failed Child Runs', implode(', ', $summary['failed_child_run_ids']) ?: 'none');
            $this->components->twoColumnDetail('Formal Lifecycle Data', $summary['formal_lifecycle_data']);
            $this->line('Recovery: history remains queryable; create a new v3 batch to regenerate from Foundation.');

            if (! $this->option('execute')) {
                $this->warn('DRY-RUN：未修改数据库；加 --execute 才会将该旧批次标记为 cancelled。');

                return self::SUCCESS;
            }

            $updated = $retire->handle($batch, (string) $this->option('reason'));
            $this->info("旧 Outline Batch #{$updated->getKey()} 已标记为 {$updated->status->value} / {$updated->error_code}。");
            $this->warn('历史 Run、Artifact、Usage、AI Request Log 与 failed job 均未删除或重试。');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('旧 Outline Batch 处置失败：'.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function summary(GenerationRun $batch): array
    {
        if ($batch->scope_type !== NovelOutlinePipeline::BATCH_SCOPE
            || $batch->prompt_version !== NovelOutlinePipeline::LEGACY_BATCH_PROMPT_VERSION) {
            throw ValidationException::withMessages(['run' => '指定 Run 不是旧版 v2 Outline 主批次。']);
        }

        $novel = Novel::query()->findOrFail($batch->novel_id);
        $children = GenerationRun::query()
            ->where('novel_id', $novel->getKey())
            ->where('scope_id', $batch->getKey())
            ->where('scope_type', '!=', NovelOutlinePipeline::BATCH_SCOPE)
            ->orderBy('id')
            ->get(['id', 'status']);
        $artifacts = GenerationArtifact::query()
            ->whereIn('generation_run_id', $children->pluck('id'))
            ->whereHas('generationRun', fn ($query) => $query->where('status', RunStatus::Succeeded))
            ->orderBy('id')
            ->get(['id', 'type']);

        return [
            'novel_title' => $novel->title,
            'child_run_ids' => $children->pluck('id')->all(),
            'failed_child_run_ids' => $children->where('status', RunStatus::Failed)->pluck('id')->all(),
            'successful_artifacts' => $artifacts
                ->map(fn (GenerationArtifact $artifact): string => "#{$artifact->getKey()}:{$artifact->type->value}")
                ->all(),
            'formal_lifecycle_data' => sprintf(
                'current_outline=%s, outlines=%d, chapters=%d, story_events=%d',
                $novel->current_outline_id ?? 'null',
                $novel->outlines()->count(),
                $novel->chapters()->count(),
                $novel->storyEvents()->count(),
            ),
        ];
    }
}
