<?php

namespace App\Services;

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingRequest;
use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Memory;
use Illuminate\Support\Facades\DB;
use Throwable;

class MemoryEmbedder
{
    public function __construct(private readonly EmbeddingProvider $provider) {}

    public function embed(int $memoryId): Memory
    {
        $memory = Memory::query()->findOrFail($memoryId);
        $model = (string) config('ai.embedding.model');
        $dimensions = (int) config('ai.embedding.dimensions');
        $key = "embedding:{$memory->getKey()}:{$model}";
        $inputHash = hash('sha256', $memory->summary."\0{$model}\0{$dimensions}");
        $chapterId = Chapter::query()
            ->where('novel_id', $memory->novel_id)
            ->where('sequence', $memory->valid_from_chapter)
            ->value('id');
        [$run, $shouldRequest] = $this->startRun($memory, $chapterId, $key, $inputHash, $model, $dimensions);

        if (! $shouldRequest) {
            return $memory;
        }

        try {
            $response = $this->provider->embed(new EmbeddingRequest(
                model: $model,
                input: $memory->summary,
                dimensions: $dimensions,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $memory->novel_id,
                    'chapter_id' => $chapterId,
                    'memory_id' => $memory->getKey(),
                ],
            ));

            if ($response->model !== $model || count($response->embedding) !== $dimensions) {
                throw new AiProviderException(
                    'embedding_dimensions_mismatch',
                    'Embedding Provider 返回的模型或向量维度与固定配置不一致。',
                    false,
                );
            }

            $vector = $this->vectorLiteral($response->embedding);

            return DB::transaction(function () use ($memory, $model, $run, $vector): Memory {
                $lockedMemory = Memory::query()->lockForUpdate()->findOrFail($memory->getKey());
                $lockedMemory->update(['embedding' => $vector, 'embedding_model' => $model]);
                $run->update([
                    'status' => RunStatus::Succeeded,
                    'finished_at' => now(),
                    'error_code' => null,
                    'error_message' => null,
                ]);

                return $lockedMemory->refresh();
            });
        } catch (Throwable $exception) {
            $run->update([
                'status' => RunStatus::Failed,
                'finished_at' => now(),
                'error_code' => $exception instanceof AiProviderException ? $exception->errorCode : 'embedding_failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function markTerminalFailure(int $memoryId, Throwable $exception): void
    {
        GenerationRun::query()
            ->where('scope_type', 'memory')
            ->where('scope_id', $memoryId)
            ->where('stage', GenerationStage::Embedding)
            ->latest('id')
            ->first()
            ?->update([
                'status' => RunStatus::Failed,
                'finished_at' => now(),
                'error_code' => $exception instanceof AiProviderException ? $exception->errorCode : 'embedding_failed',
                'error_message' => $exception->getMessage(),
            ]);
    }

    /** @return array{GenerationRun, bool} */
    private function startRun(Memory $memory, mixed $chapterId, string $key, string $inputHash, string $model, int $dimensions): array
    {
        return DB::transaction(function () use ($memory, $chapterId, $key, $inputHash, $model, $dimensions): array {
            Memory::query()->lockForUpdate()->findOrFail($memory->getKey());
            $run = GenerationRun::query()->where('idempotency_key', $key)->first();

            if ($run !== null) {
                if ($run->input_hash !== $inputHash) {
                    throw new AiProviderException('embedding_input_changed', '相同向量化幂等键对应的 Memory 摘要已经变化。', false);
                }

                if ($run->status === RunStatus::Failed) {
                    $run->update([
                        'status' => RunStatus::Running,
                        'attempt' => $run->attempt + 1,
                        'started_at' => now(),
                        'finished_at' => null,
                        'error_code' => null,
                        'error_message' => null,
                    ]);

                    return [$run, true];
                }

                return [$run, false];
            }

            $run = GenerationRun::query()->create([
                'novel_id' => $memory->novel_id,
                'chapter_id' => is_numeric($chapterId) ? (int) $chapterId : null,
                'scene_id' => null,
                'scope_type' => 'memory',
                'scope_id' => $memory->getKey(),
                'stage' => GenerationStage::Embedding,
                'status' => RunStatus::Running,
                'attempt' => 1,
                'idempotency_key' => $key,
                'input_hash' => $inputHash,
                'prompt_version' => null,
                'model_policy' => $model,
                'context_snapshot' => [
                    'memory_id' => $memory->getKey(),
                    'embedding_model' => $model,
                    'embedding_dimensions' => $dimensions,
                ],
                'started_at' => now(),
            ]);

            return [$run, true];
        });
    }

    /** @param array<int, float> $embedding */
    private function vectorLiteral(array $embedding): string
    {
        foreach ($embedding as $value) {
            if (! is_finite($value)) {
                throw new AiProviderException('embedding_invalid_vector', 'Embedding 向量包含无效数值。', false);
            }
        }

        return '['.implode(',', $embedding).']';
    }
}
