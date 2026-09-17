<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\Enums\ArtifactType;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use Illuminate\Support\Str;
use Throwable;

final class PlanCoverageJudgmentRepairer
{
    public const PROMPT_VERSION = 'coverage-judgment-repair-v1';

    public function __construct(private readonly AiProvider $provider) {}

    /**
     * @param  array<string, mixed>  $coverage
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    public function repair(GenerationRun $run, int $sceneId, array $coverage, string $content, array $task, string $model): array
    {
        $input = [
            ...compact('sceneId', 'coverage', 'content', 'task'),
            'model' => $model,
            'prompt_version' => self::PROMPT_VERSION,
        ];
        $inputHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        if ($existing = $this->existing($run, $inputHash)) {
            return (array) data_get($existing->data, 'result', []);
        }

        $logId = (string) Str::uuid();

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $model,
                systemPrompt: '你是 XNovel Coverage 判定复核器。只重新判断 goal、conflict、turn、outcome 的 status 和 evidence；不得修改正文、计划或任何 Canonical 数据。必须根据 task 的语义和完整 content 判断，不能用关键词命中代替语义完成。fulfilled/contradicted 的 evidence 必须逐字引用 content 中的连续文本；missing 的 evidence 必须为 null。若原判定正确应保持原判定。',
                prompt: '请复核以下 Coverage：'.json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: .2,
                maxTokens: (int) config('generation.coverage_judgment_repair_max_output_tokens', 1_500),
                responseSchema: PlanCoverage::schema(),
                promptVersion: self::PROMPT_VERSION,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $run->novel_id,
                    'chapter_id' => $run->chapter_id,
                    'scene_id' => $sceneId,
                    'stage' => 'coverage_judgment_repair',
                    'ai_request_log_id' => $logId,
                ],
            ));

            if (! is_array($response->structuredData)) {
                throw new \UnexpectedValueException('Coverage 判定修复未返回结构化结果。');
            }

            $repaired = PlanCoverage::validate($response->structuredData, $content, 'coverage_judgment_repair');
            $result = [
                'status' => 'succeeded',
                'model' => $model,
                'prompt_version' => self::PROMPT_VERSION,
                'coverage' => $repaired,
                'ai_request_log_id' => $logId,
                'provider_request_id' => $response->providerRequestId,
            ];
        } catch (Throwable $exception) {
            $result = [
                'status' => 'failed',
                'model' => $model,
                'prompt_version' => self::PROMPT_VERSION,
                'coverage' => $coverage,
                'ai_request_log_id' => $logId,
                'error_code' => $exception instanceof AiProviderException
                    ? $exception->errorCode
                    : 'coverage_judgment_repair_failed',
                'error_message' => $exception->getMessage(),
            ];
        }

        $this->persist($run, $inputHash, $result);

        return $result;
    }

    private function existing(GenerationRun $run, string $inputHash): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->where('type', ArtifactType::Context)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $run->chapter_id))
            ->latest('id')
            ->get()
            ->first(fn (GenerationArtifact $artifact): bool => data_get($artifact->data, 'role') === 'coverage_judgment_repair'
                && data_get($artifact->data, 'input_hash') === $inputHash);
    }

    /** @param array<string, mixed> $result */
    private function persist(GenerationRun $run, string $inputHash, array $result): void
    {
        $data = ['role' => 'coverage_judgment_repair', 'input_hash' => $inputHash, 'result' => $result];
        $run->artifacts()->create([
            'type' => ArtifactType::Context,
            'version' => ((int) $run->artifacts()->where('type', ArtifactType::Context)->max('version')) + 1,
            'content' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data' => $data,
            'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
    }
}
