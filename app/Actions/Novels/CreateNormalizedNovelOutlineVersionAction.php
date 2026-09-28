<?php

namespace App\Actions\Novels;

use App\Data\NormalizedNovelOutline;
use App\Enums\ArtifactType;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\NovelOutlineBeat;
use App\Models\User;
use App\Services\NormalizedNovelOutlineValidator;
use App\Services\NovelOutlineChecksum;
use App\Services\NovelOutlinePipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 在单一事务中创建不可变的关系化小说大纲版本。
 */
class CreateNormalizedNovelOutlineVersionAction
{
    public function __construct(
        private readonly NormalizedNovelOutlineValidator $validator,
        private readonly NovelOutlineChecksum $checksum,
    ) {}

    /**
     * 校验完整 DTO 后写入版本头、Volume、Arc、Beat、Milestone 与 Handoff。
     */
    public function handle(
        Novel $novel,
        NormalizedNovelOutline|array $outline,
        NovelOutlineSource $source = NovelOutlineSource::Manual,
        ?NovelOutline $basedOn = null,
        ?User $creator = null,
        ?GenerationArtifact $sourceArtifact = null,
    ): NovelOutline {
        $dto = $outline instanceof NormalizedNovelOutline ? $outline : NormalizedNovelOutline::fromArray($outline);
        // 完整 DTO 在进入事务前先做一次确定性校验，避免创建任何部分版本。
        $this->validator->assertValid($dto);

        return DB::transaction(function () use ($novel, $dto, $source, $basedOn, $creator, $sourceArtifact): NovelOutline {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $this->assertSource($lockedNovel, $source, $sourceArtifact);

            if ($sourceArtifact !== null) {
                $existing = NovelOutline::query()->where('source_artifact_id', $sourceArtifact->getKey())->first();
                if ($existing !== null) {
                    if ($existing->novel_id !== $lockedNovel->getKey()) {
                        throw ValidationException::withMessages(['source_artifact_id' => '来源 Artifact 已绑定到其他小说的 Outline。']);
                    }

                    // Finalize Job 重复投递时复用同一版本，不分配新的 version。
                    return $existing->load('volumes.arcs.beats.milestones');
                }
            }

            if ($basedOn !== null && $basedOn->novel_id !== $lockedNovel->getKey()) {
                throw ValidationException::withMessages(['based_on_outline_id' => '基础 Outline Version 不属于当前 Novel。']);
            }

            $data = $dto->toArray();
            $version = ((int) $lockedNovel->outlines()->max('version')) + 1;
            $outlineVersion = $lockedNovel->outlines()->create([
                'version' => $version,
                'status' => NovelOutlineStatus::Draft,
                'source' => $source,
                'schema_version' => 2,
                'title' => $data['title'],
                'summary' => $data['summary'],
                'must_include' => $data['must_include'],
                'must_not_include' => $data['must_not_include'],
                'checksum' => $this->checksum->for($dto),
                'source_artifact_id' => $sourceArtifact?->getKey(),
                'based_on_outline_id' => $basedOn?->getKey(),
                'created_by' => $creator?->getKey(),
                'applied_at' => null,
            ]);

            /** @var array<string, NovelOutlineBeat> $beatsByKey */
            $beatsByKey = [];
            /** @var array<int, array{beat_id: int, next_beat_key: string|null}> $handoffs */
            $handoffs = [];

            foreach ($data['volumes'] as $volumeData) {
                $volume = $outlineVersion->volumes()->create([
                    'volume_key' => $volumeData['key'],
                    'sequence' => $volumeData['sequence'],
                    'title' => $volumeData['title'],
                    'goal' => $volumeData['goal'],
                    'climax' => $volumeData['climax'],
                    'target_words' => $volumeData['target_words'],
                ]);

                foreach ($volumeData['arcs'] as $arcData) {
                    $arc = $volume->arcs()->create([
                        'novel_outline_id' => $outlineVersion->getKey(),
                        'arc_key' => $arcData['key'],
                        'sequence' => $arcData['sequence'],
                        'mainline_sequence' => $arcData['mainline_sequence'],
                        'type' => $arcData['type'],
                        'title' => $arcData['title'],
                        'goal' => $arcData['goal'],
                        'stakes' => $arcData['stakes'],
                        'completion_conditions' => $arcData['completion_conditions'],
                    ]);

                    foreach ($arcData['beats'] as $beatData) {
                        $handoff = is_array($beatData['handoff'] ?? null) ? $beatData['handoff'] : [];
                        $beat = $arc->beats()->create([
                            'novel_outline_id' => $outlineVersion->getKey(),
                            'beat_key' => $beatData['key'],
                            'sequence' => $beatData['sequence'],
                            'mainline_sequence' => $beatData['mainline_sequence'],
                            'title' => $beatData['title'],
                            'summary' => $beatData['summary'],
                            'chapter_budget_min' => $beatData['chapter_budget']['min'],
                            'chapter_budget_max' => $beatData['chapter_budget']['max'],
                            'acceptance_criteria' => $beatData['acceptance_criteria'],
                            'must_include' => $beatData['must_include'],
                            'must_not_include' => $beatData['must_not_include'],
                            'character_candidates' => $beatData['character_candidates'],
                            'world_entity_candidates' => $beatData['world_entity_candidates'],
                            'handoff_next_beat_id' => null,
                            'handoff_transition_mode' => $handoff['transition_mode'] ?? null,
                            'handoff_exit_result' => $handoff['exit_result'] ?? null,
                            'handoff_next_trigger' => $handoff['next_trigger'] ?? null,
                            'handoff_carried_states' => $handoff['carried_states'] ?? [],
                            'handoff_open_threads' => $handoff['open_threads'] ?? [],
                            'handoff_required_transition' => $handoff['required_transition'] ?? [],
                            'handoff_forbidden_jump' => $handoff['forbidden_jump'] ?? [],
                        ]);
                        $beatsByKey[$beatData['key']] = $beat;
                        $handoffs[] = ['beat_id' => $beat->getKey(), 'next_beat_key' => $handoff['next_beat_key'] ?? null];

                        foreach ($beatData['milestones'] as $milestoneData) {
                            $beat->milestones()->create([
                                'novel_outline_id' => $outlineVersion->getKey(),
                                'milestone_key' => $milestoneData['key'],
                                'sequence' => $milestoneData['sequence'],
                                'title' => $milestoneData['title'],
                                'objective' => $milestoneData['objective'],
                                'acceptance_criteria' => $milestoneData['acceptance_criteria'],
                                'must_include' => $milestoneData['must_include'],
                                'must_not_include' => $milestoneData['must_not_include'],
                            ]);
                        }
                    }
                }
            }

            foreach ($handoffs as $handoff) {
                $targetKey = $handoff['next_beat_key'];
                if ($targetKey === null) {
                    continue;
                }
                $target = $beatsByKey[$targetKey] ?? null;
                if ($target === null) {
                    // 事务内再次拒绝未解析 Key，不能以 null Handoff 保存不完整版本。
                    throw ValidationException::withMessages(['outline' => "Handoff 目标 {$targetKey} 无法解析。"]);
                }
                DB::table('novel_outline_beats')->where('id', $handoff['beat_id'])->update([
                    'handoff_next_beat_id' => $target->getKey(),
                ]);
            }

            $persisted = $outlineVersion->fresh()->load('volumes.arcs.beats.milestones', 'volumes.arcs.beats.handoffNextBeat');
            if (! hash_equals($outlineVersion->checksum, $this->checksum->for($persisted))) {
                throw ValidationException::withMessages(['outline' => '关系化 Outline 保存后的 Checksum 不一致，已回滚整个版本。']);
            }

            if ($basedOn?->status === NovelOutlineStatus::Draft) {
                $basedOn->update(['status' => NovelOutlineStatus::Superseded]);
            }

            return $persisted;
        }, 3);
    }

    /**
     * 确认 AI 来源 Artifact 属于当前小说且类型正确，保证版本来源可追踪。
     */
    private function assertSource(Novel $novel, NovelOutlineSource $source, ?GenerationArtifact $artifact): void
    {
        if ($source === NovelOutlineSource::Ai && $artifact === null) {
            throw ValidationException::withMessages(['source_artifact_id' => 'AI Outline 必须绑定最终 outline_blueprint Artifact。']);
        }
        if ($artifact === null) {
            return;
        }
        $run = $artifact->generationRun()->where('novel_id', $novel->getKey());
        $validAiFinalize = $source === NovelOutlineSource::Ai
            && $run->clone()
                ->where('scope_type', NovelOutlinePipeline::FINALIZE_SCOPE)
                ->where('scope_id', data_get($artifact->data, 'lineage.batch_run_id'))
                ->where('status', RunStatus::Succeeded)
                ->exists();
        $validRevision = $source === NovelOutlineSource::Revision && $run->clone()->exists();

        if ($artifact->type !== ArtifactType::OutlineBlueprint || (! $validAiFinalize && ! $validRevision)) {
            throw ValidationException::withMessages(['source_artifact_id' => '来源必须是当前小说的最终 outline_blueprint Artifact。']);
        }
    }
}
