<?php

namespace App\Services;

use App\AI\AiSettingsResolver;
use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingRequest;
use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Jobs\GenerateEmbeddingJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\AIModelPrice;
use App\Models\AIProviderConnection;
use App\Models\Chapter;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\StoryEvent;
use App\Models\UsageRecord;
use App\Support\GenerationDiagnosticRedactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 正式记忆可重建，向量为辅助；暂停和费用门禁仍适用于派生请求。 */
final class BookMemoryService
{
    /** 人工补派也遵守来源与有限尝试，不显示实际不会执行的成功消息。 */
    public function retry(int $memoryId): void
    {
        $memory = Memory::findOrFail($memoryId);
        $task = NovelGenerationTask::findOrFail($memory->novel->current_generation_task_id);
        if (! in_array($task->status, ['running', 'completed'], true) || ! $this->valid($memory->novel)->whereKey($memoryId)->exists()) {
            throw new \LogicException('任务暂停或来源已失效，当前不能发送向量请求。正式正文已保留。');
        }
        $route = app(AiSettingsResolver::class)->resolve(AiStage::Embedding, $memory->novel);
        $hash = app(GenerationInputIdentity::class)->hash([$memoryId, $memory->summary, $route->provider, $route->model, app(GenerationSettingsService::class)->embeddingDimensions()]);
        $latest = GenerationRun::where('pipeline_version', 'book-derived-v1')->where('input_hash', $hash)->latest('id')->first();
        if ($latest && ($latest->attempt >= 3 || $latest->status->value === 'running' && $latest->lease_expires_at?->isFuture())) {
            throw new \LogicException('同一向量输入仍在执行或三次尝试已耗尽。请核对连接和模型后再处理；可继续使用有效 SQL 记忆。');
        }
        GenerateEmbeddingJob::dispatch($memoryId);
    }

    public function update(int $chapterId): void
    {
        $chapter = Chapter::findOrFail($chapterId);
        DB::transaction(function () use ($chapter): void {
            Novel::whereKey($chapter->novel_id)->lockForUpdate()->firstOrFail();
            $chapter = Chapter::whereKey($chapter->id)->lockForUpdate()->firstOrFail();
            if ($chapter->status->value !== 'canonical' || ! $chapter->summary_artifact_id) {
                throw new \LogicException('只有具备正式摘要的章节可建立记忆。');
            }
            app(MemoryUpdater::class)->update($chapter->id, false);
            $memory = Memory::firstOrNew(['novel_id' => $chapter->novel_id, 'source_type' => 'chapter', 'source_id' => $chapter->id, 'type' => 'event']);
            if ($memory->summary !== $chapter->summary) {
                // 同章回滚重写后不能沿用旧内容的向量。
                $memory->fill(['embedding' => null, 'embedding_model' => null]);
            }
            $memory->fill(['summary' => $chapter->summary, 'entities' => [], 'salience' => 0.6, 'status' => 'active', 'valid_from_chapter' => $chapter->sequence, 'valid_to_chapter' => null])->save();
        });
        foreach ($this->valid($chapter->novel)->where('valid_from_chapter', $chapter->sequence)->whereNull('embedding')->get() as $memory) {
            GenerateEmbeddingJob::dispatch($memory->id)->afterCommit();
        }
    }

    /** 扫描只补缺失派生项；有限失败留在问题中心，不每分钟无限收费。 */
    public function reconcile(): int
    {
        $count = 0;
        $novels = Novel::whereIn('current_generation_task_id', NovelGenerationTask::whereIn('status', ['running', 'completed'])->select('id'))->get();
        foreach ($novels as $novel) {
            foreach ($novel->chapters()->where('status', 'canonical')->whereNotNull('summary_artifact_id')->whereNotIn('id', Memory::where('novel_id', $novel->id)->where('source_type', 'chapter')->where('status', 'active')->select('source_id'))->limit(30)->get() as $chapter) {
                if (GenerationRun::where('generation_task_id', $novel->current_generation_task_id)->where('scope_key', 'memory-update:'.$chapter->id)->where('status', 'failed')->count() < 3) {
                    UpdateMemoryJob::dispatch($chapter->id)->afterCommit();
                    $count++;
                }
            }
            foreach ($this->valid($novel)->whereNull('embedding')->limit(50)->get() as $memory) {
                $latest = GenerationRun::where('generation_task_id', $novel->current_generation_task_id)->where('scope_key', 'memory:'.$memory->id)->latest('id')->first();
                if (! $latest || ($latest->attempt < 3 && ($latest->status->value === 'failed' && $latest->error_retryable || $latest->status->value === 'running' && $latest->lease_expires_at?->isPast()))) {
                    GenerateEmbeddingJob::dispatch($memory->id)->afterCommit();
                    $count++;
                }
            }
        }

        return $count;
    }

    /** 配置错误和队列终止也保存可见问题，不沿用旧自动停书路径。 */
    public function recordFailure(Novel $novel, string $scope, \Throwable $exception): void
    {
        $failure = app(GenerationFailurePolicy::class)->fromException($exception, 'book_memory_failed');
        $run = GenerationRun::create(['novel_id' => $novel->id, 'generation_task_id' => $novel->current_generation_task_id, 'pipeline_version' => 'book-derived-v1', 'stage' => 'embedding', 'scope_type' => 'novel', 'scope_id' => $novel->id, 'scope_key' => $scope, 'status' => 'failed', 'attempt' => 1, 'idempotency_key' => 'derived-failure:'.Str::uuid(), 'input_hash' => hash('sha256', $scope), 'error_code' => $failure->code, 'error_message' => app(GenerationDiagnosticRedactor::class)->clean($failure->message), 'error_retryable' => false, 'finished_at' => now()]);
        app(GenerationIssueService::class)->record($run, $failure);
    }

    /** 正式来源过滤在相似度排序之前，跨书、失效和草稿一律不能召回。 */
    public function valid(Novel $novel): Builder
    {
        return Memory::where('novel_id', $novel->id)->where('status', 'active')->where(function ($q) use ($novel): void {
            $q->where(fn ($q) => $q->where('source_type', 'chapter')->whereIn('source_id', $novel->chapters()->where('status', 'canonical')->select('id')))
                ->orWhere(fn ($q) => $q->where('source_type', 'story_event')->whereIn('source_id', StoryEvent::where('novel_id', $novel->id)->where('status', 'active')->whereIn('chapter_id', $novel->chapters()->where('status', 'canonical')->select('id'))->select('id')));
        });
    }

    /** 使用最近正式记忆的既有向量做远期相似召回，不在构建上下文的锁内发请求。 */
    public function retrieve(Novel $novel, array $goal = []): array
    {
        if (! $novel->canonicalStateVersion || $novel->chapters()->where('status', 'canonical')->whereNull('summary_artifact_id')->exists()) {
            throw new AiProviderException('book_memory_evidence_missing', '正式状态或必需摘要缺失，不能把向量降级当成跳过证据。请先补齐正式摘要。', false);
        }
        $base = $this->valid($novel);
        $goalText = json_encode($goal, JSON_UNESCAPED_UNICODE);
        $entityNames = $novel->characters()->pluck('name')->merge($novel->worldEntities()->pluck('name'))->filter(fn ($name) => $goalText && str_contains($goalText, $name))->values()->all();
        if ($entityNames) {
            // 有目标实体时先过滤相关记忆；高重要度记忆保留为长期承诺补充。
            $base->where(function ($q) use ($entityNames): void {
                $q->where('salience', '>=', .85);
                foreach ($entityNames as $name) {
                    $q->orWhereJsonContains('entities', $name)->orWhere('summary', 'like', '%'.addcslashes($name, '%_\\').'%');
                }
            });
        }
        $anchor = (clone $base)->whereNotNull('embedding')->latest('valid_from_chapter')->first();
        $reason = '向量尚未就绪；正式状态、锁定事实和近期摘要完整，按显著性召回有效 SQL 记忆。';
        $query = clone $base;
        if ($anchor && DB::getDriverName() === 'pgsql') {
            $query->where('embedding_model', $anchor->embedding_model)->whereNotNull('embedding')->whereKeyNot($anchor->id)
                ->selectRaw('memories.*, 1 - (embedding <=> ?::vector) AS similarity', [(string) $anchor->embedding])->orderByDesc('similarity');
            $reason = '以最近正式记忆为向量锚点，先同小说/正式来源过滤，再相似度、显著性排序。';
        }
        try {
            // 保存点隔离向量索引错误，避免 PostgreSQL 中止外层规划事务。
            $rows = DB::transaction(fn () => $query->orderByDesc('salience')->orderByDesc('id')->limit(30)->get());
        } catch (QueryException $exception) {
            if (! $anchor) {
                throw $exception;
            }
            $rows = (clone $base)->orderByDesc('salience')->orderByDesc('id')->limit(30)->get();
            $anchor = null;
            $reason = '向量查询不可用；正式证据完整，已降级为 SQL 显著性检索，请检查向量模型和索引。';
        }
        $selected = $rows->unique('summary')->take(10)
            ->map(fn ($m) => ['id' => $m->id, 'summary' => $m->summary, 'source_type' => $m->source_type, 'source_id' => $m->source_id, 'entities' => $m->entities, 'similarity' => $m->similarity, 'salience' => $m->salience, 'reason' => $reason])->values()->all();

        return ['memories' => $selected, 'selection' => ['strategy' => $anchor ? 'canonical_vector_anchor' : 'canonical_sql_fallback', 'reason' => $reason, 'anchor_memory_id' => $anchor?->id, 'selected_ids' => array_column($selected, 'id')]];
    }

    /** 派生 Run 独立于当前章推进，但每次请求仍绑定任务账本和有限尝试。 */
    public function embed(int $memoryId): void
    {
        $memory = Memory::findOrFail($memoryId);
        $route = app(AiSettingsResolver::class)->resolve(AiStage::Embedding, $memory->novel);
        $dimensions = app(GenerationSettingsService::class)->embeddingDimensions();
        $connection = AIProviderConnection::where('provider', $route->provider)->firstOrFail();
        $hash = app(GenerationInputIdentity::class)->hash([$memory->id, $memory->summary, $route->provider, $route->model, $dimensions]);
        $run = DB::transaction(function () use ($memory, $route, $dimensions, $connection, $hash): ?GenerationRun {
            $novel = Novel::whereKey($memory->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($novel->current_generation_task_id)->lockForUpdate()->firstOrFail();
            $memory = Memory::whereKey($memory->id)->lockForUpdate()->firstOrFail();
            if (! in_array($task->status, ['running', 'completed'], true) || ! $this->valid($novel)->whereKey($memory->id)->exists() || ($memory->embedding && $memory->embedding_model === $route->model)) {
                return null;
            }
            $previous = GenerationRun::where('pipeline_version', 'book-derived-v1')->where('input_hash', $hash)->latest('id')->first();
            if ($previous && ($previous->status->value === 'succeeded' || ($previous->status->value === 'running' && $previous->lease_expires_at?->isFuture()))) {
                return null;
            }
            if ($previous?->status->value === 'running') {
                $previous->update(['status' => 'failed', 'error_code' => 'worker_interrupted', 'error_message' => '派生请求租约过期，结果与费用等待核对。', 'finished_at' => now(), 'lease_token' => null, 'lease_expires_at' => null]);
                UsageRecord::where('generation_run_id', $previous->id)->where('status', 'in_flight')->update(['status' => 'unknown', 'cost_status' => 'unknown']);
            }
            $attempt = ($previous?->attempt ?? 0) + 1;
            if ($attempt > 3) {
                return null;
            }

            return GenerationRun::create(['novel_id' => $novel->id, 'generation_task_id' => $task->id, 'pipeline_version' => 'book-derived-v1', 'chapter_id' => $novel->chapters()->where('sequence', $memory->valid_from_chapter)->value('id'),
                'stage' => 'embedding', 'scope_type' => 'memory', 'scope_id' => $memory->id, 'scope_key' => 'memory:'.$memory->id, 'idempotency_key' => 'book-embedding:'.$hash.':'.$attempt, 'input_hash' => $hash, 'attempt' => $attempt, 'status' => 'running',
                'provider' => $route->provider, 'model' => $route->model, 'connection_id' => $connection->id, 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds(390), 'started_at' => now(),
                'execution_contract' => ['currency' => app(GenerationSettingsService::class)->currency(), 'price' => AIModelPrice::where('provider', $route->provider)->where('model', $route->model)->where('currency', app(GenerationSettingsService::class)->currency())->where('is_enabled', true)->first()?->only(['id', 'provider', 'model', 'currency', 'billing_unit', 'input_price', 'cached_input_price', 'output_price', 'updated_at']) ?? [], 'memory_hash' => hash('sha256', $memory->summary), 'dimensions' => $dimensions, 'control_version' => $task->control_version, 'connection' => $connection->only(['base_url', 'credential_ref', 'connect_timeout', 'timeout'])]]);
        });
        if (! $run) {
            return;
        }
        try {
            $response = app(EmbeddingProvider::class)->embed(new EmbeddingRequest($run->model, $memory->summary, $dimensions, ['novel_id' => $memory->novel_id, 'generation_run_id' => $run->id, 'provider' => $run->provider, 'lease_token' => $run->lease_token, 'control_version' => $run->execution_contract['control_version']]));
            if ($response->model !== $run->model || count($response->embedding) !== $dimensions || collect($response->embedding)->contains(fn ($x) => ! is_numeric($x) || ! is_finite((float) $x))) {
                throw new AiProviderException('embedding_invalid_vector', '向量模型、维度或数值不匹配，保留 SQL 记忆并等待重建。', false);
            }
            DB::transaction(function () use ($memory, $run, $response): void {
                $novel = Novel::whereKey($memory->novel_id)->lockForUpdate()->firstOrFail();
                $current = Memory::whereKey($memory->id)->lockForUpdate()->firstOrFail();
                if (! $this->valid($novel)->whereKey($memory->id)->exists() || hash('sha256', $current->summary) !== $run->execution_contract['memory_hash'] || ! $run->fresh()->lease_expires_at?->isFuture()) {
                    throw new \LogicException('向量返回时正式来源或租约已变化，不能采用。');
                }
                $current->update(['embedding' => '['.implode(',', $response->embedding).']', 'embedding_model' => $run->model]);
                $run->update(['status' => 'succeeded', 'finished_at' => now(), 'lease_token' => null, 'lease_expires_at' => null]);
                GenerationIssue::where('generation_task_id', $run->generation_task_id)->where('category', 'derived')->where('source_identity->scope', 'memory:'.$memory->id)->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by_run_id' => $run->id, 'resolution_reason' => '正式来源向量已重建。']);
            });
        } catch (\Throwable $exception) {
            $failure = app(GenerationFailurePolicy::class)->fromException($exception, 'book_embedding_failed');
            $run->update(['status' => 'failed', 'error_code' => $failure->code, 'error_message' => app(GenerationDiagnosticRedactor::class)->clean($failure->message), 'error_retryable' => $failure->retryable, 'finished_at' => now(), 'lease_token' => null, 'lease_expires_at' => null]);
            app(GenerationIssueService::class)->record($run, $failure);
            if ($failure->retryable) {
                throw $exception;
            }
        }
    }
}
