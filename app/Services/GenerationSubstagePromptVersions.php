<?php

namespace App\Services;

use App\AI\Data\AiRequest;
use App\Enums\GenerationStage;
use App\Models\GenerationRun;

/** 冻结同一业务阶段内各次独立 Provider 请求实际使用的 Prompt Version。 */
final class GenerationSubstagePromptVersions
{
    /** @return array<string, string> */
    public function scene(string $primaryPromptVersion): array
    {
        return [
            'prose_generation' => $primaryPromptVersion,
            'scene_structure_repair' => SceneDraftStructureRepairer::PROMPT_VERSION,
            'coverage_evidence_repair' => PlanCoverageEvidenceRepairer::PROMPT_VERSION,
            'foreshadowing_coverage_repair' => ForeshadowingCoverageEvidenceRepairer::PROMPT_VERSION,
            'length_repair' => SceneGenerator::LENGTH_REPAIR_PROMPT_VERSION,
        ];
    }

    /** @return array<string, string> */
    public function rewrite(string $primaryPromptVersion): array
    {
        return [
            'rewrite' => $primaryPromptVersion,
            'coverage_evidence_repair' => PlanCoverageEvidenceRepairer::PROMPT_VERSION,
            'rewrite_length_repair' => $primaryPromptVersion,
        ];
    }

    /** @return array<string, string> */
    public function eventExtraction(string $primaryPromptVersion): array
    {
        return [
            'event_extraction' => $primaryPromptVersion,
            'event_evidence_repair' => StoryEventEvidenceRepairer::PROMPT_VERSION,
        ];
    }

    /**
     * 同一输入的后续 Job 必须继承首个 Run 已冻结的映射；旧 Run 没有该字段时才采用当前兼容映射。
     *
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    public function inherit(
        GenerationStage $stage,
        int $chapterId,
        ?int $sceneId,
        string $inputHash,
        array $defaults,
    ): array {
        $runs = GenerationRun::query()
            ->where('chapter_id', $chapterId)
            ->where('stage', $stage)
            ->where('input_hash', $inputHash)
            ->when($sceneId === null,
                fn ($query) => $query->whereNull('scene_id'),
                fn ($query) => $query->where('scene_id', $sceneId),
            )
            ->latest('id')
            ->get(['context_snapshot']);

        foreach ($runs as $run) {
            $frozen = data_get($run->context_snapshot, 'generation_preferences.substage_prompt_versions');
            if ($this->valid($frozen)) {
                return $frozen;
            }
        }

        return $defaults;
    }

    public function expectedFor(GenerationRun $run, AiRequest $request): ?string
    {
        $substage = trim((string) ($request->metadata['substage'] ?? ''));
        if ($substage === '') {
            return $run->prompt_version;
        }

        $versions = data_get(
            $run->context_snapshot,
            'generation_preferences.substage_prompt_versions',
        );

        // 新 Run 已冻结映射时，未知子阶段必须失败，防止退回父版本绕过合同。
        // 仅旧 Run 没有该映射时保留父版本兼容路径。
        if (is_array($versions) && $versions !== []) {
            $frozen = $versions[$substage] ?? null;

            return is_string($frozen) && $frozen !== '' ? $frozen : null;
        }

        return $run->prompt_version;
    }

    private function valid(mixed $versions): bool
    {
        return is_array($versions)
            && $versions !== []
            && collect($versions)->every(
                fn (mixed $version, mixed $substage): bool => is_string($substage)
                    && $substage !== ''
                    && is_string($version)
                    && $version !== '',
            );
    }
}
