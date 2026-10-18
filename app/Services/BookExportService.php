<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** 逐章输出冻结的正式版本，文件失败只补导出，不重新生成小说。 */
final class BookExportService
{
    /** 清单只加载章身份和标题，不一次载入长篇全文。 */
    public function manifest(NovelGenerationTask $task): array
    {
        $novel = $task->novel->fresh();
        $chapters = $novel->chapters()->with('canonicalArtifact:id,checksum')->where('status', 'canonical')->orderBy('sequence')->get(['id', 'sequence', 'title', 'canonical_artifact_id', 'word_count']);
        if ($chapters->isEmpty() || $chapters->pluck('sequence')->all() !== range(1, $chapters->count())) {
            throw new \LogicException('正式章序不连续或没有可导出的正文，请核对正式指针。');
        }

        return ['format' => 'book-txt-v1', 'novel_id' => $novel->id, 'title' => $novel->title,
            'state_version_id' => $novel->canonical_state_version_id, 'complete' => $novel->status->value === 'completed',
            'chapters' => $chapters->map(fn ($c) => [...$c->only(['id', 'sequence', 'title', 'canonical_artifact_id', 'word_count']), 'checksum' => $c->canonicalArtifact->checksum])->all()];
    }

    /** 阅读导出只冻结正式清单，不改变运行中的续章断点，也不调用模型。 */
    public function snapshot(NovelGenerationTask $task): GenerationArtifact
    {
        return DB::transaction(function () use ($task): GenerationArtifact {
            Novel::whereKey($task->novel_id)->lockForUpdate()->firstOrFail();
            $manifest = $this->manifest($task);
            $hash = app(GenerationInputIdentity::class)->hash($manifest);
            $key = 'book-download:'.$task->id.':'.$hash;
            $previous = GenerationRun::where('idempotency_key', $key)->first();
            if ($previous) {
                return GenerationArtifact::findOrFail($previous->result_artifact_id);
            }
            $run = GenerationRun::create(['novel_id' => $task->novel_id, 'generation_task_id' => $task->id, 'pipeline_version' => 'book-derived-v1', 'stage' => 'export', 'scope_type' => 'novel', 'scope_id' => $task->novel_id, 'scope_key' => 'download', 'status' => 'succeeded', 'attempt' => 1, 'input_hash' => $hash, 'idempotency_key' => $key, 'started_at' => now(), 'finished_at' => now()]);
            $artifact = GenerationArtifact::create(['generation_run_id' => $run->id, 'type' => ArtifactType::ExportManifest, 'version' => 1, 'schema_version' => 'book-export-v1', 'data' => $manifest, 'checksum' => $hash]);
            $run->update(['result_artifact_id' => $artifact->id]);

            return $artifact;
        });
    }

    /** 文件缺失或损坏时从不可变清单重建，正式小说和费用账本不受影响。 */
    public function file(GenerationArtifact $artifact): string
    {
        if ($artifact->type !== ArtifactType::ExportManifest) {
            throw new \LogicException('该产物不是正文导出清单。');
        }
        $data = $artifact->data;
        // 标题和完结标记也属于快照身份，不能与相同章集合的另一快照共用文件。
        $path = 'novel-exports/'.$artifact->novel_id.'/'.app(GenerationInputIdentity::class)->hash($data).'.txt';
        $stream = tmpfile();
        if (! $stream) {
            throw new \RuntimeException('无法创建临时文件，请检查磁盘空间。');
        }
        try {
            foreach ($this->chunks($data) as $chunk) {
                if (fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('正文导出写入不完整，请检查磁盘空间后重试。');
                }
            }
            rewind($stream);
            $this->publishFile($path, $stream);
        } finally {
            fclose($stream);
        }

        return $path;
    }

    public function enqueue(NovelGenerationTask $task): GenerationRun
    {
        $manifest = $this->manifest($task);
        $sources = array_map(fn ($c) => ['artifact_id' => $c['canonical_artifact_id'], 'checksum' => $c['checksum'], 'chapter_id' => $c['id'], 'pointer' => 'canonical_artifact_id'], $manifest['chapters']);

        return app(GenerationRunCoordinator::class)->enqueue($task->id, GenerationStage::Export, 'book-export',
            ['book_export' => true, 'manifest' => $manifest, 'state_version_id' => $manifest['state_version_id']], $sources);
    }

    /** 在数据库锁外流式写入私有文件；只有完整写入才发布成功产物。 */
    public function execute(GenerationRun $run, array $lease): void
    {
        $manifest = $run->execution_contract['manifest'];
        $hash = app(GenerationInputIdentity::class)->hash($manifest);
        $path = 'novel-exports/'.$run->novel_id.'/'.$hash.'.txt';
        $stream = tmpfile();
        if ($stream === false) {
            throw new \RuntimeException('无法创建导出临时文件，请检查磁盘空间与临时目录权限。');
        }
        try {
            foreach ($this->chunks($manifest) as $chunk) {
                if (fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('导出写入不完整，请检查存储空间。');
                }
            }
            rewind($stream);
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $stream);
            $fileHash = hash_final($ctx);
            rewind($stream);
            $this->publishFile($path, $stream);
            app(GenerationRunCoordinator::class)->completeExecution($run->id, $lease['token'], $lease['control_version'], ArtifactType::ExportManifest,
                [...$manifest, 'path' => $path, 'file_checksum' => $fileHash, 'chapter_count' => count($manifest['chapters']), 'word_count' => array_sum(array_column($manifest['chapters'], 'word_count'))],
                next: ['stage' => 'book_control', 'completed_run_id' => $run->id]);
        } finally {
            fclose($stream);
        }
    }

    /** 同目录临时文件完整写入后再发布，重复下载不能读到另一次重建的半个文件。 */
    private function publishFile(string $path, mixed $stream): void
    {
        $disk = Storage::disk('local');
        $pending = $path.'.'.Str::uuid().'.tmp';
        try {
            if (! $disk->put($pending, $stream) || ! $disk->move($pending, $path)) {
                throw new \RuntimeException('导出文件未完整发布，请检查存储空间和权限后重试导出。');
            }
        } finally {
            if ($disk->exists($pending)) {
                $disk->delete($pending);
            }
        }
    }

    /** 历史清单不读取当前章节正文指针，从而导出期间不会混版。 */
    public function chunks(array $manifest): \Generator
    {
        yield $manifest['title'].($manifest['complete'] ? '' : '（未完结）')."\n\n";
        foreach ($manifest['chapters'] as $item) {
            $artifact = GenerationArtifact::findOrFail($item['canonical_artifact_id']);
            if ($artifact->novel_id !== $manifest['novel_id'] || ! hash_equals($artifact->checksum, $item['checksum']) || ! hash_equals($artifact->checksum, app(GenerationInputIdentity::class)->hash([$artifact->content, $artifact->data, $artifact->source_manifest]))) {
                throw new \LogicException('导出冻结正文已丢失或校验和不符。');
            }
            yield '第 '.$item['sequence'].' 章 '.$item['title']."\n\n".$artifact->content."\n\n";
        }
    }

    /** 完结审计、正式清单和文件均一致才结束整书任务。 */
    public function adopt(NovelGenerationTask $task, GenerationRun $run): void
    {
        $artifact = GenerationArtifact::findOrFail($run->result_artifact_id);
        $data = $artifact->data;
        if (! $data['complete'] || $task->novel->fresh()->status->value !== 'completed' || ! Storage::disk('local')->exists($data['path'])) {
            throw new \LogicException('正文尚未完结或导出文件缺失，任务不能完成。');
        }
        // 文件存在不代表写入完整；收尾前再次核对实际字节，损坏只能重建导出。
        $stream = Storage::disk('local')->readStream($data['path']);
        if (! is_resource($stream)) {
            throw new \RuntimeException('导出文件无法读取，请检查存储后重试导出。');
        }
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            if (! hash_equals($data['file_checksum'], hash_final($context))) {
                throw new \RuntimeException('导出文件校验失败，请重试导出，正文无需重新生成。');
            }
        } finally {
            fclose($stream);
        }
        $task->update(['status' => 'completed', 'current_phase' => 'export', 'export_manifest_id' => $artifact->id, 'pending_transition' => null, 'finished_at' => now(), 'last_progress_at' => now(), 'stop_reason' => null, 'stop_detail' => null]);
    }
}
