<?php

namespace App\Services;

use App\Data\CurrentOutlineTarget;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use App\Models\Review;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SixRingOutlineMigrationDryRun
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly NovelOutlineChecksum $checksum,
        private readonly OutlineProgressResolver $progressResolver,
    ) {}

    /** @return array<string, mixed> */
    public function build(Novel $novel): array
    {
        $novel = Novel::query()->with(['canonicalStateVersion', 'currentOutline'])->findOrFail($novel->getKey());
        if ($novel->title !== '六环余光') {
            throw ValidationException::withMessages(['novel' => 'OUT-009 只允许为《六环余光》生成迁移 Dry Run。']);
        }
        if ($novel->canonicalStateVersion === null) {
            throw ValidationException::withMessages(['state' => '小说缺少当前 Canonical Story State，无法冻结迁移报告。']);
        }

        $chapters = $this->canonicalChapters($novel);
        $chapterTwelve = $novel->chapters()->where('sequence', 12)->firstOrFail();
        $chapterTwelveSource = $this->chapterTwelveSource($chapterTwelve);
        $nodes = $this->evidenceMap($chapters);
        $characters = $novel->characters()->reorder('id')->get();
        $worldEntities = $novel->worldEntities()->reorder('id')->get();
        $actualCurrentBeat = $this->actualCurrentBeat($novel);

        $frozen = [
            'schema_version' => self::SCHEMA_VERSION,
            'novel' => [
                'id' => $novel->getKey(),
                'title' => $novel->title,
                'status' => $novel->status->value,
                'current_chapter_sequence' => $novel->current_chapter_sequence,
            ],
            'expected_state' => [
                'id' => $novel->canonicalStateVersion->getKey(),
                'version' => $novel->canonicalStateVersion->version,
                'checksum' => $novel->canonicalStateVersion->checksum,
            ],
            'current_outline' => [
                'id' => $novel->current_outline_id,
                'version' => $novel->currentOutline?->version,
                'checksum' => $novel->currentOutline?->checksum,
            ],
            'canonical_chapters' => $chapters->map(fn (array $item): array => [
                'chapter_id' => $item['chapter']->getKey(),
                'sequence' => $item['chapter']->sequence,
                'canonical_artifact_id' => $item['artifact']->getKey(),
                'canonical_artifact_checksum' => $item['artifact']->checksum,
                'summary_checksum' => $item['chapter']->summary === null
                    ? null
                    : hash('sha256', $item['chapter']->summary),
            ])->values()->all(),
            'chapter_12' => $chapterTwelveSource,
            'target_outline_nodes' => collect($this->targetNodes())->map(
                fn (array $node): array => collect($node)->except('evidence_terms')->all(),
            )->all(),
            'characters' => $characters->map(fn ($character): array => [
                'id' => $character->getKey(),
                'name' => $character->name,
                'role' => $character->role,
                'status' => $character->status->value,
            ])->all(),
            'world_entities' => $worldEntities->map(fn ($entity): array => [
                'id' => $entity->getKey(),
                'type' => $entity->type->value,
                'name' => $entity->name,
                'status' => $entity->status->value,
            ])->all(),
        ];

        $planHash = $this->checksum->for($frozen);
        $missingSummaries = $chapters
            ->filter(fn (array $item): bool => blank($item['chapter']->summary))
            ->map(fn (array $item): array => [
                'chapter_id' => $item['chapter']->getKey(),
                'sequence' => $item['chapter']->sequence,
                'canonical_artifact_id' => $item['artifact']->getKey(),
                'canonical_artifact_checksum' => $item['artifact']->checksum,
            ])->values()->all();

        return [
            'report_type' => 'six_ring_outline_migration_dry_run',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toISOString(),
            'read_only' => true,
            'plan_hash' => $planHash,
            'novel' => $frozen['novel'],
            'expected_state_version' => $frozen['expected_state']['version'],
            'expected_state_id' => $frozen['expected_state']['id'],
            'expected_state_checksum' => $frozen['expected_state']['checksum'],
            'current_outline_id' => $frozen['current_outline']['id'],
            'current_outline_version' => $frozen['current_outline']['version'],
            'current_outline_checksum' => $frozen['current_outline']['checksum'],
            'chapter_12_artifact_id' => $chapterTwelveSource['selected_artifact_id'],
            'chapter_12_artifact_checksum' => $chapterTwelveSource['selected_artifact_checksum'],
            'chapter_12_artifact_selection' => $chapterTwelveSource['selected_artifact_basis'],
            'source_snapshot' => $frozen,
            'historical_evidence_mapping' => $nodes,
            'missing_canonical_summaries' => $missingSummaries,
            'actual_current_beat' => $actualCurrentBeat,
            'proposed_current_beat' => [
                'if_academy_meet_su_li_confirmed' => 'academy-professor-training',
                'otherwise' => 'academy-meet-su-li',
                'automatic_baseline_completion' => false,
                'reason' => '历史 Evidence 只形成候选映射；用户确认 Baseline Completion 前不得把 academy-meet-su-li 当成已完成。',
            ],
            'professor_candidate' => [
                'candidate_key' => 'character-professor-mentor',
                'name' => '待用户命名的教授',
                'role' => '导师',
                'motivation' => '确认林墨的特殊性并训练其控制魔法代价',
                'unresolved_fields' => ['name', 'profile', 'personality', 'abilities', 'knowledge', 'limitations'],
                'possible_existing_characters' => $characters
                    ->filter(fn ($character): bool => str_contains((string) $character->name, '教授')
                        || str_contains((string) $character->role, '教授')
                        || str_contains((string) $character->role, '导师'))
                    ->map->only(['id', 'name', 'role'])->values()->all(),
                'will_write' => false,
            ],
            'future_world_entity_candidates' => [
                [
                    'candidate_key' => 'item-cultivation-accelerator',
                    'type' => 'item',
                    'source_beat_key' => 'academy-shopping-cultivation-item',
                    'known_requirement' => '外出购物时意外获得能够加快魔法修行的神奇物品。',
                    'unresolved_fields' => ['name', 'description', 'abilities', 'rules', 'cost', 'limitations'],
                    'will_write' => false,
                ],
                [
                    'candidate_key' => 'location-first-academy-mission',
                    'type' => 'location',
                    'source_beat_key' => 'field-arrive-mission-location',
                    'known_requirement' => '第一次学院任务的目标地点。',
                    'unresolved_fields' => ['name', 'description', 'rules', 'current_state'],
                    'will_write' => false,
                ],
                [
                    'candidate_key' => 'organization-other-academy',
                    'type' => 'organization',
                    'source_beat_key' => 'field-conflict-other-academy',
                    'known_requirement' => '在任务地点与林墨一方发生矛盾的其他学院。',
                    'unresolved_fields' => ['name', 'description', 'rules', 'relationship_to_current_academy'],
                    'will_write' => false,
                ],
            ],
            'existing_world_entities' => $frozen['world_entities'],
            'chapter_12_options' => $this->chapterTwelveOptions($chapterTwelve, $chapterTwelveSource),
            'outline_scope_decision' => [
                'provided_volumes' => 2,
                'provided_beats' => 9,
                'blocker' => '用户尚未提供其余 Volume；OUT-010 前必须确认只先采用已提供的两个 Volume，或补齐完整大纲。',
            ],
            'unresolved_decisions' => [
                '确认 academy-meet-su-li 的历史 Baseline Completion 及逐字 Evidence。',
                '选择第 12 章保留现有来源链，或废弃并按新 Outline 重建。',
                '确认教授姓名、能力、动机限制及是否与现有角色重复。',
                '确认未来物品、任务地点和其他学院的 Candidate 细节。',
                '确认只先采用两个 Volume，或继续提供其余 Volume。',
            ],
            'database_writes' => [],
            'provider_calls' => 0,
        ];
    }

    /** @param array<string, mixed> $report */
    public function markdown(array $report): string
    {
        $lines = [
            '# 《六环余光》大纲迁移 Dry Run',
            '',
            '> 本报告只读取数据库并写入本地文件；没有修改 Outline、Chapter、Story Event、Story State、Memory 或其他业务数据，也没有调用 AI Provider。',
            '',
            '## 冻结边界',
            '',
            "- Novel：{$report['novel']['title']} (#{$report['novel']['id']})",
            "- Expected State Version：{$report['expected_state_version']}",
            "- Expected State Checksum：`{$report['expected_state_checksum']}`",
            '- Current Outline ID：'.($report['current_outline_id'] ?? 'null'),
            '- Chapter 12 Artifact ID：'.($report['chapter_12_artifact_id'] ?? 'null'),
            '- Chapter 12 Artifact Checksum：'.($report['chapter_12_artifact_checksum'] === null ? 'null' : "`{$report['chapter_12_artifact_checksum']}`"),
            "- Plan Hash：`{$report['plan_hash']}`",
            '',
            '## 历史节点 Evidence 候选',
            '',
            '| 节点 | 候选状态 | Canonical Chapters | 逐字 Evidence | 不确定项 |',
            '|---|---|---|---|---|',
        ];

        foreach ($report['historical_evidence_mapping'] as $item) {
            $chapterIds = collect($item['canonical_chapter_ids'])->map(fn ($id): string => '#'.$id)->implode(', ') ?: '—';
            $evidence = collect($item['evidence'])->map(
                fn (array $e): string => "Ch.{$e['chapter_sequence']}「{$this->cell($e['text'])}」",
            )->implode('<br>') ?: '—';
            $uncertainties = collect($item['uncertainties'])->map(fn (string $value): string => $this->cell($value))->implode('<br>');
            $lines[] = "| {$this->cell($item['title'])} (`{$item['beat_key']}`) | {$item['candidate_status']} | {$chapterIds} | {$evidence} | {$uncertainties} |";
        }

        $lines = [...$lines,
            '',
            '候选状态不等于正式完成记录。任何 `completed` Baseline Completion 都必须由用户审核逐字 Evidence 后确认；本报告不会创建 Story Event。',
            '',
            '## Summary 与 Current Beat',
            '',
            '- 缺失 Canonical Summary 的章节：'.(collect($report['missing_canonical_summaries'])->pluck('sequence')->implode(', ') ?: '无'),
            '- 当前系统可解析 Beat：'.($report['actual_current_beat']['beat_key'] ?? '无').'；'.$report['actual_current_beat']['reason'],
            '- 若用户确认 `academy-meet-su-li` 已完成，建议下一 Beat：`academy-professor-training`。',
            '- 若不确认，下一 Beat 仍为：`academy-meet-su-li`。',
            '',
            '## 第 12 章两种方案',
            '',
        ];

        foreach ($report['chapter_12_options'] as $option) {
            $lines[] = "### {$option['key']}. {$option['title']}";
            $lines[] = '';
            foreach ($option['effects'] as $effect) {
                $lines[] = '- '.$effect;
            }
            $lines[] = '';
        }

        $lines = [...$lines,
            '## 候选教授与未来实体',
            '',
            "- 教授 Candidate：`{$report['professor_candidate']['candidate_key']}`，名称仍为“{$report['professor_candidate']['name']}”；本次不会创建 Character。",
        ];
        foreach ($report['future_world_entity_candidates'] as $candidate) {
            $lines[] = "- `{$candidate['candidate_key']}` ({$candidate['type']})：{$candidate['known_requirement']} 本次不会创建 World Entity。";
        }

        $lines = [...$lines,
            '',
            '## OUT-010 前仍需决定',
            '',
        ];
        foreach ($report['unresolved_decisions'] as $decision) {
            $lines[] = '- '.$decision;
        }

        return implode("\n", $lines)."\n";
    }

    /** @return Collection<int, array{chapter: Chapter, artifact: GenerationArtifact}> */
    private function canonicalChapters(Novel $novel): Collection
    {
        $chapters = $novel->chapters()
            ->whereBetween('sequence', [1, 11])
            ->with('canonicalArtifact.generationRun')
            ->get()
            ->keyBy('sequence');
        $missing = collect(range(1, 11))->reject(fn (int $sequence): bool => $chapters->has($sequence))->values();
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['chapters' => '缺少 Chapter '.implode(', ', $missing->all()).'，无法生成 1～11 章迁移报告。']);
        }

        return collect(range(1, 11))->map(function (int $sequence) use ($chapters): array {
            /** @var Chapter $chapter */
            $chapter = $chapters->get($sequence);
            $artifact = $chapter->canonicalArtifact;
            if ($chapter->status !== ChapterStatus::Canonical
                || ! $artifact instanceof GenerationArtifact
                || blank($artifact->content)
                || $artifact->generationRun?->chapter_id !== $chapter->getKey()) {
                throw ValidationException::withMessages([
                    'chapters' => "Chapter {$sequence} 不是带有效所属关系和正文的 Canonical Artifact。",
                ]);
            }

            return ['chapter' => $chapter, 'artifact' => $artifact];
        });
    }

    /** @param Collection<int, array{chapter: Chapter, artifact: GenerationArtifact}> $chapters @return array<int, array<string, mixed>> */
    private function evidenceMap(Collection $chapters): array
    {
        return collect($this->targetNodes())->map(function (array $node) use ($chapters): array {
            $evidence = $chapters->flatMap(function (array $item) use ($node): array {
                $sentences = preg_split('/(?<=[。！？!?])/u', (string) $item['artifact']->content) ?: [];

                return collect($sentences)
                    ->map(fn (string $sentence): string => trim($sentence))
                    ->filter(fn (string $sentence): bool => $sentence !== '' && collect($node['evidence_terms'])->contains(
                        fn (string $term): bool => mb_stripos($sentence, $term) !== false,
                    ))
                    ->take(3)
                    ->map(fn (string $sentence): array => [
                        'chapter_id' => $item['chapter']->getKey(),
                        'chapter_sequence' => $item['chapter']->sequence,
                        'canonical_artifact_id' => $item['artifact']->getKey(),
                        'canonical_artifact_checksum' => $item['artifact']->checksum,
                        'text' => $sentence,
                    ])->all();
            })->take(8)->values();

            return [
                'beat_key' => $node['beat_key'],
                'volume' => $node['volume'],
                'sequence' => $node['sequence'],
                'title' => $node['title'],
                'candidate_status' => $evidence->isEmpty() ? 'not_started' : 'partial',
                'canonical_chapter_ids' => $evidence->pluck('chapter_id')->unique()->values()->all(),
                'evidence' => $evidence->all(),
                'uncertainties' => [
                    ...$node['uncertainties'],
                    ...($evidence->isEmpty()
                        ? ['关键词检索未找到逐字 Evidence；这不能证明剧情从未发生，仍需人工阅读确认。']
                        : ['命中词句只支持 partial 候选，不能自动证明全部验收条件已经满足。']),
                ],
                'automatic_completion' => false,
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function chapterTwelveSource(Chapter $chapter): array
    {
        $runs = $chapter->generationRuns()->with(['artifacts', 'review'])->orderBy('id')->get();
        $review = Review::query()
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->with(['artifact.generationRun'])
            ->latest('id')
            ->first();
        $selected = $review?->artifact;
        $basis = $selected instanceof GenerationArtifact ? 'latest_review_artifact' : null;

        if (! $selected instanceof GenerationArtifact) {
            $selected = GenerationArtifact::query()
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
                ->latest('id')
                ->first();
            $basis = $selected instanceof GenerationArtifact ? 'latest_chapter_or_rewrite_draft' : 'no_chapter_artifact';
        }

        if ($selected instanceof GenerationArtifact && $selected->generationRun->chapter_id !== $chapter->getKey()) {
            throw ValidationException::withMessages(['chapter_12' => '选中的第 12 章 Artifact 不属于该章。']);
        }

        return [
            'chapter_id' => $chapter->getKey(),
            'sequence' => $chapter->sequence,
            'status' => $chapter->status->value,
            'canonical_artifact_id' => $chapter->canonical_artifact_id,
            'selected_artifact_id' => $selected?->getKey(),
            'selected_artifact_type' => $selected?->type->value,
            'selected_artifact_checksum' => $selected?->checksum,
            'selected_artifact_basis' => $basis,
            'plans' => $chapter->plans()->get()->map(fn ($plan): array => [
                'id' => $plan->getKey(),
                'version' => $plan->version,
                'status' => $plan->status->value,
                'novel_outline_id' => $plan->novel_outline_id,
            ])->all(),
            'scenes' => $chapter->scenes()->get()->map(fn ($scene): array => [
                'id' => $scene->getKey(),
                'sequence' => $scene->sequence,
                'status' => $scene->status->value,
                'current_artifact_id' => $scene->current_artifact_id,
            ])->all(),
            'runs' => $runs->map(fn ($run): array => [
                'id' => $run->getKey(),
                'stage' => $run->stage->value,
                'status' => $run->status->value,
                'input_hash' => $run->input_hash,
                'artifact_ids' => $run->artifacts->pluck('id')->all(),
                'usage_records' => $run->usageRecords()->count(),
            ])->all(),
            'reviews' => $runs->pluck('review')->filter()->map(fn ($item): array => [
                'id' => $item->getKey(),
                'decision' => $item->decision->value,
                'artifact_id' => $item->artifact_id,
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $source @return array<int, array<string, mixed>> */
    private function chapterTwelveOptions(Chapter $chapter, array $source): array
    {
        return [[
            'key' => 'A',
            'title' => '保留第 12 章现有来源链，新 Outline 从第 13 章生效',
            'effects' => [
                "第 12 章保持 {$chapter->status->value}；不覆盖现有 Plan、Scene、Run、Artifact、Review 或 Usage。",
                '先按现有来源链处理第 12 章，再让新 Outline 从第 13 章开始约束 Planner。',
                '第 12 章不会自动补写新 Outline 的 Primary Beat 来源；需人工确认其内容与新大纲是否兼容。',
            ],
            'preserved_run_ids' => collect($source['runs'])->pluck('id')->all(),
            'will_write' => false,
        ], [
            'key' => 'B',
            'title' => '废弃第 12 章当前 Draft，从 Canonical Chapter 11 和新 Outline 重建',
            'effects' => [
                '采用新 Outline 后，将当前 Draft/Ready Plan 标记为 superseded，并清空 Scene current_artifact_id。',
                '第 12 章进入 void 后从 Chapter Planning 重新开始；旧 Run、Artifact、Review 和 Usage 保留审计。',
                '重新生成会产生新的 Provider 调用和费用；本次 Dry Run 不派发 Job。',
            ],
            'preserved_run_ids' => collect($source['runs'])->pluck('id')->all(),
            'will_write' => false,
        ]];
    }

    /** @return array<string, mixed> */
    private function actualCurrentBeat(Novel $novel): array
    {
        if ($novel->current_outline_id === null) {
            return ['beat_key' => null, 'reason' => '当前没有 Current Outline。'];
        }

        $target = $this->progressResolver->resolve($novel);
        if (! $target instanceof CurrentOutlineTarget) {
            return ['beat_key' => null, 'reason' => 'Current Outline 没有可解析的 Active Main Beat。'];
        }

        return [
            'beat_key' => data_get($target->beat, 'key'),
            'title' => data_get($target->beat, 'title'),
            'outline_id' => $target->outlineId,
            'chapters_used' => $target->chaptersUsedForCurrentBeat,
            'reason' => '由当前 Outline、活动 Volume/Arc、Baseline Completion 和正式完成事件确定。',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function targetNodes(): array
    {
        return [
            ['beat_key' => 'academy-meet-su-li', 'volume' => 1, 'sequence' => 1, 'title' => '与苏璃结识', 'evidence_terms' => ['苏璃'], 'uncertainties' => ['需要确认是否存在正式见面及明确的后续联系，不能因两人共同出现就判定完成。']],
            ['beat_key' => 'academy-professor-training', 'volume' => 1, 'sequence' => 2, 'title' => '教授指导', 'evidence_terms' => ['教授', '导师'], 'uncertainties' => ['需要确认教授正式登场、先考察、指出问题、带代价训练及可验证提升。']],
            ['beat_key' => 'academy-examination', 'volume' => 1, 'sequence' => 3, 'title' => '学院考核', 'evidence_terms' => ['学院考核', '考核'], 'uncertainties' => ['需要确认考核结果及其对学院成长阶段的推进。']],
            ['beat_key' => 'academy-shopping-cultivation-item', 'volume' => 1, 'sequence' => 4, 'title' => '购物获得修炼物品', 'evidence_terms' => ['购物', '商店', '修炼物品', '神奇物品'], 'uncertainties' => ['物品名称、能力、代价和限制尚未由用户确认。']],
            ['beat_key' => 'field-accept-academy-mission', 'volume' => 2, 'sequence' => 1, 'title' => '接受学院任务', 'evidence_terms' => ['学院任务', '接受任务', '接下任务'], 'uncertainties' => ['需要确认任务由学院正式发布且林墨明确接受。']],
            ['beat_key' => 'field-help-bullied-stranger', 'volume' => 2, 'sequence' => 2, 'title' => '途中拔刀相助', 'evidence_terms' => ['拔刀相助', '欺凌', '欺负'], 'uncertainties' => ['需要确认发生在任务途中，且确有弱者受到欺凌。']],
            ['beat_key' => 'field-conflict-other-academy', 'volume' => 2, 'sequence' => 3, 'title' => '与其他学院学员冲突', 'evidence_terms' => ['其他学院', '学院学员', '外院'], 'uncertainties' => ['其他学院名称、涉事学员与冲突原因尚未确认。']],
            ['beat_key' => 'field-fight-other-academy', 'volume' => 2, 'sequence' => 4, 'title' => '双方互斗', 'evidence_terms' => ['双方互斗', '互斗', '交手'], 'uncertainties' => ['需要确认冲突确已升级为双方互斗，而不是普通争执。']],
            ['beat_key' => 'field-complete-first-mission', 'volume' => 2, 'sequence' => 5, 'title' => '经历磨难并完成任务', 'evidence_terms' => ['完成任务', '任务完成'], 'uncertainties' => ['需要确认磨难、任务目标和完成结果均有正式正文 Evidence。']],
        ];
    }

    private function cell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], ['', '<br>', '\\|'], $value);
    }
}
