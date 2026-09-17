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

final class ArcCompletionAuditRepairer
{
    public const PROMPT_VERSION = 'arc-completion-repair-v2';

    public function __construct(private readonly AiProvider $provider) {}

    /**
     * @param  array<int, array<string, mixed>>  $contract
     * @param  array<int, mixed>  $audits
     * @return array<string, mixed>
     */
    public function repair(GenerationRun $run, array $contract, array $audits, string $draft, string $model): array
    {
        $input = [
            'arc_completion_contract' => $contract,
            'invalid_arc_completion_audits' => $audits,
            'draft' => $draft,
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
                systemPrompt: '你是 XNovel Arc Completion 审计修复器。只修复 arc_completion_audits，不得修改正文、Chapter Plan、评分、Findings 或 Canonical 数据。必须严格按 arc_completion_contract 的数量和顺序为每个 arc_id 返回一个对象。只有正文同时满足该 Arc 的全部 completion_conditions 时才返回 fulfilled，并提供一段来自正文的连续逐字片段；不得拼接、删节或合并相隔的句段。否则返回 not_met 且 evidence=null。不得把每个 completion condition 拆成独立审计。',
                prompt: '请修复以下 Arc Completion 审计：'.json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: .1,
                maxTokens: (int) config('generation.arc_completion_repair_max_output_tokens', 1_000),
                responseSchema: $this->schema(),
                promptVersion: self::PROMPT_VERSION,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $run->novel_id,
                    'chapter_id' => $run->chapter_id,
                    'stage' => 'arc_completion_repair',
                    'ai_request_log_id' => $logId,
                ],
            ));
            $repaired = data_get($response->structuredData, 'arc_completion_audits');

            if (! is_array($repaired) || ! $this->valid($repaired, $contract, $draft)) {
                throw new \UnexpectedValueException('Arc Completion Repair 返回结构无效。');
            }

            $result = [
                'status' => 'succeeded',
                'model' => $model,
                'prompt_version' => self::PROMPT_VERSION,
                'audits' => $repaired,
                'ai_request_log_id' => $logId,
                'provider_request_id' => $response->providerRequestId,
            ];
        } catch (Throwable $exception) {
            $result = [
                'status' => 'failed',
                'model' => $model,
                'prompt_version' => self::PROMPT_VERSION,
                'audits' => [],
                'ai_request_log_id' => $logId,
                'error_code' => $exception instanceof AiProviderException
                    ? $exception->errorCode
                    : 'arc_completion_repair_failed',
                'error_message' => $exception->getMessage(),
            ];
        }

        $this->persist($run, $inputHash, $result);

        return $result;
    }

    /** @param array<int, mixed> $audits @param array<int, array<string, mixed>> $contract */
    public function valid(array $audits, array $contract, string $draft): bool
    {
        $expectedIds = collect($contract)->pluck('arc_id')->map(fn ($id): int => (int) $id)->values()->all();
        $actualIds = collect($audits)->map(fn ($audit): int => is_array($audit) ? (int) ($audit['arc_id'] ?? 0) : 0)->values()->all();
        if ($actualIds !== $expectedIds) {
            return false;
        }

        return collect($audits)->every(function (mixed $audit) use ($draft): bool {
            if (! is_array($audit) || collect(array_keys($audit))->sort()->values()->all() !== ['arc_id', 'evidence', 'status']) {
                return false;
            }

            return match ($audit['status'] ?? null) {
                'fulfilled' => is_string($audit['evidence']) && filled($audit['evidence']) && str_contains($draft, $audit['evidence']),
                'not_met' => $audit['evidence'] === null,
                default => false,
            };
        });
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['arc_completion_audits'],
            'properties' => [
                'arc_completion_audits' => PlanningReviewAudit::schema()['arc_completion_audits'],
            ],
        ];
    }

    private function existing(GenerationRun $run, string $inputHash): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->where('type', ArtifactType::Context)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $run->chapter_id))
            ->latest('id')
            ->get()
            ->first(fn (GenerationArtifact $artifact): bool => data_get($artifact->data, 'role') === 'arc_completion_repair'
                && data_get($artifact->data, 'input_hash') === $inputHash);
    }

    /** @param array<string, mixed> $result */
    private function persist(GenerationRun $run, string $inputHash, array $result): void
    {
        $data = ['role' => 'arc_completion_repair', 'input_hash' => $inputHash, 'result' => $result];
        $run->artifacts()->create([
            'type' => ArtifactType::Context,
            'version' => ((int) $run->artifacts()->where('type', ArtifactType::Context)->max('version')) + 1,
            'content' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data' => $data,
            'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
    }
}
