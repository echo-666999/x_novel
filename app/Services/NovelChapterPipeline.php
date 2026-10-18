<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Data\GenerationFailure;
use App\Data\StoryEventCandidate;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Models\AIModelPrice;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\NovelGenerationTask;
use App\Models\NovelOutline;
use App\Models\Review;

/** 当前章推进器负责草稿与审校，PASS 交给生命周期入口并由统一服务正式提交。 */
final class NovelChapterPipeline
{
    /** 按已采用窗口创建唯一当前章；不预建未来章节，不要求 Scene/Volume/Arc。 */
    public function start(NovelGenerationTask $task): ?GenerationRun
    {
        if ($next = app(NovelPlanningService::class)->adoptPendingBlueprint($task)) {
            return $next;
        }
        if (GenerationRun::where('novel_id', $task->novel_id)->where('pipeline_version', 'pipeline-v1')->where('status', 'running')->exists()) {
            return null;
        }
        if ($pending = data_get($task->progress_snapshot, 'pending_window_id')) {
            app(NovelPlanningService::class)->adoptWindow($task->id, $pending, $task->control_version);
            $progress = $task->progress_snapshot;
            unset($progress['pending_window_id']);
            $task->update(['progress_snapshot' => $progress]);
        }
        $novel = $task->novel->fresh();
        $window = NovelOutline::findOrFail($novel->current_goal_window_id);
        $sequence = ((int) $novel->chapters()->where('status', 'canonical')->max('sequence')) + 1;
        $goals = array_values(array_filter($window->sourceArtifact->data['goals'], fn ($g) => $g['sequence'] >= $sequence));
        if (count($goals) < (int) config('generation.book.window_refill_threshold', 2)
            && $task->target_words - (int) $novel->chapters()->where('status', 'canonical')->sum('word_count') > array_sum(array_column($goals, 'target_words'))) {
            return app(NovelPlanningService::class)->window($task, NovelOutline::findOrFail($novel->current_blueprint_id));
        }
        $goal = collect($goals)->firstWhere('sequence', $sequence);
        if (! $goal) {
            throw new AiProviderException('book_goal_missing', '近期目标缺少当前章，请重新生成目标窗口。', false);
        }
        $chapter = Chapter::firstOrCreate(['novel_id' => $novel->id, 'sequence' => $sequence], ['title' => '待规划', 'status' => ChapterStatus::Planned, 'word_count' => 0]);
        $task->update(['current_chapter_id' => $chapter->id]);
        $context = app(ContextBuilder::class)->forBook($novel, $goal);

        return app(NovelStageContract::class)->enqueue($task, 'plan', ['context' => $context, 'goal' => $goal, 'sequence' => $sequence],
            [app(NovelPlanningService::class)->source($window->sourceArtifact)], ['chapter_id' => $chapter->id, 'goal_refs' => [['key' => $goal['key'], 'hash' => $goal['content_hash']]]]);
    }

    /** 原始 JSON 通过 Schema 后再做领域约束，证据命中不冒充语义审校通过。 */
    public function validateOutput(GenerationRun $run, array $data): array
    {
        $c = $run->execution_contract;
        $phase = $c['book_stage'];
        if ($phase === 'plan') {
            return [...$data, 'target_words' => $c['input']['goal']['target_words'], 'goal_refs' => $c['goal_refs'], 'source_state_version_id' => $c['state_version_id']];
        }
        if (in_array($phase, ['write', 'fragment'], true)) {
            $data['body'] = trim(str_replace("\r\n", "\n", $data['body']));
            if ($data['body'] === '') {
                throw new AiProviderException('book_body_empty', '正文为空，不能进入提取。', false);
            }

            return [...$data, 'sequence' => $c['fragment_sequence'], 'previous_fragment_id' => $c['previous_fragment_id'] ?? null,
                'previous_fragment_hash' => $c['previous_fragment_hash'] ?? null, 'plan_id' => $c['chapter_plan_id'], 'continuation' => $c['continuation'] ?? 0];
        }
        $draft = GenerationArtifact::findOrFail($c['draft_artifact_id']);
        if ($phase === 'rewrite') {
            $body = app(ChapterDraftService::class)->replace($draft, $data['replacements'], $c['allowed_paragraphs']);

            return [...$data, 'body' => $body, 'source_artifact_id' => $draft->id, 'paragraph_index' => app(ChapterDraftService::class)->index($body)];
        }
        if ($phase === 'extract') {
            $events = [];
            foreach ($data['events'] as $event) {
                $event['evidence'] = array_map(fn ($e) => app(ChapterDraftService::class)->evidence($draft, $e), $event['evidence']);
                $event['confidence'] = 1.0;
                $candidate = StoryEventCandidate::fromArray($event);
                $this->validateEntity($run, $candidate);
                $events[] = $candidate->toArray();
            }

            return ['events' => $events, 'source_artifact_id' => $draft->id, 'draft_checksum' => $draft->checksum,
                'chapter_plan_id' => $c['chapter_plan_id'], 'source_state_version_id' => $c['state_version_id']];
        }
        $expected = array_column($c['goal_refs'], 'key');
        $seen = [];
        foreach ($data['goal_checks'] as $check) {
            if (! in_array($check['goal_key'], $expected, true) || in_array($check['goal_key'], $seen, true) || ($check['fulfilled'] && $check['evidence'] === [])) {
                throw new AiProviderException('book_review_coverage_invalid', '审校目标覆盖或依据不完整，只修复审校合同。', false);
            }
            foreach ($check['evidence'] as $reference) {
                app(ChapterDraftService::class)->evidence($draft, $reference);
            }
            $seen[] = $check['goal_key'];
        }
        if (array_diff($expected, $seen)) {
            throw new AiProviderException('book_review_coverage_invalid', '审校遗漏当前章目标。', false);
        }
        foreach ($data['findings'] as $finding) {
            foreach ($finding['evidence'] as $reference) {
                app(ChapterDraftService::class)->evidence($draft, $reference);
            }
        }
        $hard = collect($c['input']['rule_findings'] ?? [])->contains('severity', 'hard') || collect($data['findings'])->contains('severity', 'hard');
        $decision = $hard ? 'BLOCK' : ((collect($data['findings'])->contains('severity', 'uncertain') || collect($c['input']['rule_findings'] ?? [])->contains('severity', 'ambiguous')) ? 'NEEDS_ATTENTION'
            : (collect($data['findings'])->contains('severity', 'important') || collect($data['goal_checks'])->contains('fulfilled', false) ? 'REWRITE' : 'PASS'));

        return [...$data, 'decision' => $decision, 'rule_findings' => $c['input']['rule_findings'], 'source_artifact_id' => $draft->id,
            'chapter_plan_id' => $c['chapter_plan_id'], 'event_artifact_id' => $c['event_artifact_id'], 'patch_artifact_id' => $c['patch_artifact_id'], 'source_state_version_id' => $c['state_version_id']];
    }

    /** 引用必须属于本小说；未来新实体保持候选，不能把字符串 ID 直接送 bigint 查询。 */
    private function validateEntity(GenerationRun $run, StoryEventCandidate $event): void
    {
        $novel = $run->novel;
        if (str_contains((string) $event->subjectId, '.')) {
            throw new AiProviderException('book_entity_reference_invalid', '实体标识不能包含状态路径分隔符。', false);
        }
        foreach (['character_id', 'owner_id', 'from_owner_id', 'to_owner_id', 'world_entity_id', 'location_id'] as $field) {
            $id = $event->payload[$field] ?? null;
            if ($id === null) {
                continue;
            }
            $query = in_array($field, ['world_entity_id', 'location_id'], true) ? $novel->worldEntities() : $novel->characters();
            if (! ctype_digit((string) $id) || ! $query->whereKey($id)->exists()) {
                throw new AiProviderException('book_entity_reference_invalid', '事件载荷 '.$field.' 引用了不存在或其他小说的实体。', false);
            }
        }
        $relation = match ($event->subjectType) {
            'character' => $novel->characters(), 'world_entity', 'world', 'location', 'item', 'faction' => $novel->worldEntities(),
            'foreshadowing' => $novel->foreshadowings(), 'chapter' => $novel->chapters(), default => null,
        };
        if ($relation && (! ctype_digit((string) $event->subjectId) || ! $relation->whereKey($event->subjectId)->exists())) {
            throw new AiProviderException('book_entity_reference_invalid', '事件引用的实体不存在或不属于本小说；请修正提取候选，不修改正文。', false);
        }
        $statePath = match ($event->subjectType) {
            'thread', 'conflict' => 'open_threads', 'reader_promise' => 'reader_promises',
            'relationship' => 'relationships', default => ($event->subjectType ?? '').'s',
        };
        if (! $relation && ! data_get($novel->canonicalStateVersion?->state ?? [], $statePath.'.'.$event->subjectId)) {
            throw new AiProviderException('book_entity_reference_invalid', '事件引用没有正式状态依据，不能直接建立补丁。', false);
        }
    }

    /** 完成后的确定性交接可重放；正式采用和后继意图由外层小说事务一起提交。 */
    public function advance(NovelGenerationTask $task, GenerationRun $run): ?GenerationRun
    {
        $c = $run->execution_contract;
        $phase = $c['book_stage'];
        $artifact = GenerationArtifact::findOrFail($run->result_artifact_id);
        $chapter = Chapter::whereKey($run->chapter_id)->lockForUpdate()->firstOrFail();
        if ($phase === 'plan') {
            return $this->admit($task, $chapter, $run, $artifact);
        }
        $plan = ChapterPlan::findOrFail($chapter->current_plan_id);
        if (in_array($phase, ['write', 'fragment'], true)) {
            return $this->afterFragment($task, $chapter, $plan, $artifact);
        }
        if ($phase === 'rewrite') {
            $body = $artifact->data['body'];
            $draft = $this->draft($run, $body, [$this->source($artifact)], ArtifactType::RewriteDraft);
            $chapter->update(['current_draft_artifact_id' => $draft->id, 'status' => ChapterStatus::Review]);

            return $this->downstream($task, $chapter, $plan, $draft, 'extract', ids: ['repair_chain_issue_id' => $c['repair_chain_issue_id'] ?? null]);
        }
        $draft = GenerationArtifact::findOrFail($chapter->current_draft_artifact_id);
        if ($phase === 'extract') {
            $patch = app(StatePatchBuilder::class)->build($chapter->id, $artifact->id);
            $rules = app(StateValidator::class)->validate($chapter->id, $artifact->id, $patch->id, $draft->id);
            $findings = array_map(fn ($f) => $f->toArray(), $rules->findings);

            return $this->downstream($task, $chapter, $plan, $draft, 'review', ['event_candidate' => $artifact->data, 'state_patch' => $patch->data, 'rule_findings' => $findings],
                ['event_artifact_id' => $artifact->id, 'patch_artifact_id' => $patch->id, 'repair_chain_issue_id' => $c['repair_chain_issue_id'] ?? null], [$this->source($artifact), $this->source($patch)]);
        }
        $data = $artifact->data;
        $review = Review::firstOrCreate(['generation_run_id' => $run->id], ['novel_id' => $task->novel_id, 'artifact_id' => $artifact->id, 'decision' => $data['decision'],
            'findings' => [...$data['rule_findings'], ...$data['findings']], 'source_manifest' => $run->source_manifest, 'rules_version' => 'book-review-v1']);
        $chapter->update(['status' => $data['decision'] === 'PASS' ? ChapterStatus::Review : ChapterStatus::Blocked]);
        if ($data['decision'] === 'PASS') {
            app(BookChapterRepair::class)->closeChain($run);

            return app(BookLifecycle::class)->reviewed($task, $review);
        }
        if (! empty($c['repair_chain_issue_id'])) {
            // 复验仍失败时保留原问题和累计预算，交由人工继续处理。
            GenerationIssue::whereKey($c['repair_chain_issue_id'])->update(['status' => 'awaiting_user']);
            $task->update(['status' => 'needs_attention', 'stop_reason' => 'book_repair_exhausted']);

            return null;
        }
        app(GenerationIssueService::class)->record($run, new GenerationFailure('book_review_'.strtolower($data['decision']), '审校发现需要处理的问题；正文、证据与规则结果已保留。', false,
            ['findings' => $review->findings], '修复当前稿'));

        return app(BookChapterRepair::class)->attempt($run);
    }

    /** 全链预检在 Writer 前完成：先裁剪可选记忆，保留所有硬约束和状态。 */
    public function admit(NovelGenerationTask $task, Chapter $chapter, GenerationRun $run, GenerationArtifact $artifact): GenerationRun
    {
        $data = $artifact->data;
        $context = $run->execution_contract['input']['context'];
        $contract = app(NovelStageContract::class);
        $writerRequest = $contract->request($task, 'write', []);
        $capacity = AIModelPrice::findEnabledForRoute($writerRequest->provider, $writerRequest->model)->capacitySnapshot();
        $ratio = (int) config('generation.book.writer_tokens_per_character', 3);
        $overhead = (int) config('generation.book.writer_output_overhead', 512);
        $limit = max(1, intdiv($capacity['max_output_tokens'] - $overhead, $ratio));
        $parts = (int) ceil($data['target_words'] / $limit);
        if ($parts > (int) config('generation.book.max_fragments', 8)) {
            throw new AiProviderException('book_capacity_exceeded', '写作输出容量无法覆盖本章，必要拆分也超过上限；请选择容量更高的已授权模型。', false);
        }
        $output = min($capacity['max_output_tokens'], (int) ceil($data['target_words'] / $parts) * $ratio + $overhead);
        $routes = [];
        do {
            try {
                foreach (['write', 'extract', 'review', 'rewrite'] as $phase) {
                    $input = ['context' => $context, 'plan' => $data];
                    if ($phase !== 'write') {
                        $input['body'] = str_repeat('文', (int) ceil($data['target_words'] * 1.2));
                    }
                    if ($phase === 'review') {
                        $input['event_candidate_capacity_reserve'] = str_repeat('x', (int) config('generation.book.structured_max_tokens', 6000) * 4);
                    }
                    if ($phase === 'write' && $parts > 1) {
                        $input['previous_tail'] = str_repeat('文', 1000);
                    }
                    $frozen = app(GenerationRequestContract::class)->freeze($contract->request($task, $phase, $input, $phase === 'write' ? $output : null));
                    $routes[$phase] = $frozen;
                }
                break;
            } catch (AiProviderException $e) {
                if ($context['optional_memories'] === [] || ! str_contains($e->errorCode, 'capacity')) {
                    throw $e;
                }
                array_pop($context['optional_memories']);
            }
        } while (true);
        $admission = ['schema_version' => 'book-admission-v1', 'plan_artifact_id' => $artifact->id, 'context' => $context, 'routes' => $routes,
            'writer_strategy' => $parts > 1 ? 'segments' : 'whole', 'reason' => $parts > 1 ? '整章输出超出核实容量，顺序拆分' : '整章与提取审校容量均允许',
            'fragment_count' => $parts, 'writer_output_tokens' => $output, 'goal_refs' => $data['goal_refs'], 'plan' => $data];
        $plan = ChapterPlan::where('chapter_id', $chapter->id)->where('input_hash', $run->input_hash)->first();
        if (! $plan) {
            $plan = ChapterPlan::create(['chapter_id' => $chapter->id, 'novel_id' => $task->novel_id, 'version' => ((int) $chapter->plans()->max('version')) + 1,
                'blueprint_id' => $task->novel->current_blueprint_id, 'goal_window_id' => $task->novel->current_goal_window_id, 'goal_refs' => $data['goal_refs'], 'source_state_version_id' => $data['source_state_version_id'],
                'schema_version' => 'book-plan-v1', 'chapter_title' => $data['chapter_title'], 'chapter_function' => $data['chapter_function'], 'reader_promise' => $context['goal']['objective'],
                'target_words' => $data['target_words'], 'tone' => mb_substr($data['tone'], 0, 255), 'time_anchor' => mb_substr($data['time_anchor'], 0, 255), 'hook_type' => mb_substr($data['ending_point'], 0, 255),
                'must_reveal' => $data['must_reveal'], 'must_not_reveal' => $data['must_not_reveal'], 'required_facts' => $data['required_facts'], 'forbidden_conflicts' => $data['forbidden_conflicts'],
                'checksum' => app(GenerationInputIdentity::class)->hash($data), 'input_hash' => $run->input_hash, 'admission_snapshot' => $admission, 'admitted_at' => now(), 'status' => 'ready']);
        }
        // 上下文和容量算式另存不可变产物，便于解释本章为何使用这些输入。
        $run->artifacts()->firstOrCreate(['type' => ArtifactType::Context, 'checksum' => app(GenerationInputIdentity::class)->hash($admission)], ['data' => $admission, 'source_manifest' => [$this->source($artifact)], 'validation_status' => 'validated']);
        $chapter->update(['current_plan_id' => $plan->id, 'title' => $data['chapter_title'], 'status' => ChapterStatus::Generating]);

        return $this->write($task, $chapter, $plan, [], 0);
    }

    /** 容量变化只重建准入，不重写已保存正文；新 Admission 和计划均独立版本。 */
    public function rebuildAdmission(NovelGenerationTask $task, GenerationRun $completed): GenerationRun
    {
        $chapter = Chapter::findOrFail($completed->chapter_id);
        $draft = GenerationArtifact::find($chapter->current_draft_artifact_id);
        if (! $draft) {
            if (($completed->execution_contract['book_stage'] ?? null) !== 'plan') {
                throw new \LogicException('没有可复用计划，请修复目标后重建。');
            }

            return $this->admit($task, $chapter, $completed, GenerationArtifact::findOrFail($completed->result_artifact_id));
        }
        $old = ChapterPlan::findOrFail($chapter->current_plan_id);
        $a = $old->admission_snapshot;
        // 实际正文和索引参与容量计算；只可裁剪可选记忆，不能丢正文或硬约束。
        do {
            try {
                foreach (['extract', 'review', 'rewrite'] as $phase) {
                    $input = ['context' => $a['context'], 'plan' => $a['plan'], 'body' => $draft->content, 'paragraph_index' => $draft->data['paragraph_index']];
                    if ($phase === 'review') {
                        $input['event_reserve'] = str_repeat('x', (int) config('generation.book.structured_max_tokens', 6000) * 4);
                    }
                    $a['routes'][$phase] = app(GenerationRequestContract::class)->freeze(app(NovelStageContract::class)->request($task, $phase, $input));
                }
                break;
            } catch (AiProviderException $e) {
                if (empty($a['context']['optional_memories']) || ! str_contains($e->errorCode, 'capacity')) {
                    throw $e;
                }
                array_pop($a['context']['optional_memories']);
            }
        } while (true);
        $plan = $old->replicate();
        $plan->version = ((int) $chapter->plans()->max('version')) + 1;
        $plan->admission_snapshot = $a;
        $plan->admitted_at = now();
        $plan->input_hash = app(GenerationInputIdentity::class)->hash([$old->input_hash, $a]);
        $plan->save();
        $chapter->update(['current_plan_id' => $plan->id]);

        return $this->downstream($task, $chapter, $plan, $draft, 'extract');
    }

    /** 前驱引用、尾文和临时状态冻结在本片段合同，重复执行不会重写成功前缀。 */
    private function write(NovelGenerationTask $task, Chapter $chapter, ChapterPlan $plan, array $fragments, int $continuation): GenerationRun
    {
        $last = $fragments === [] ? null : end($fragments);
        $a = $plan->admission_snapshot;
        $input = ['context' => $a['context'], 'plan' => $a['plan'], 'fragment_number' => count($fragments) + 1,
            'fragment_count' => $a['fragment_count'], 'target_words' => max(1, min((int) ceil($plan->target_words / $a['fragment_count']), $plan->target_words - array_sum(array_map(fn ($f) => mb_strlen(preg_replace('/\s/u', '', $f->content)), $fragments)))),
            'segment_goal' => $a['plan']['segments'][max(0, count($fragments) - $continuation)] ?? ($a['fragment_count'] === 1 ? $a['plan']['ending_point'] : '按全章计划顺序推进到本片段比例边界，最后一段完成章末落点'),
            'previous_tail' => $last ? mb_substr($last->content, -1000) : '', 'temporary_state' => $last?->data['temporary_state'] ?? []];
        $identity = $this->identity($chapter, $plan) + ['fragment_sequence' => count($fragments) + 1, 'previous_fragment_id' => $last?->id, 'previous_fragment_hash' => $last?->checksum, 'continuation' => $continuation,
            'fragment_ids' => array_map(fn ($f) => $f->id, $fragments)];

        return app(NovelStageContract::class)->enqueue($task, $a['writer_strategy'] === 'whole' && ! $last ? 'write' : 'fragment', $input,
            [$this->source(GenerationArtifact::findOrFail($a['plan_artifact_id'])), ...array_map(fn ($f) => $this->source($f), $fragments)], $identity, $a['writer_output_tokens']);
    }

    /** 只补缺段或有限截断后继；全部有效片段到齐才生成统一全章草稿。 */
    private function afterFragment(NovelGenerationTask $task, Chapter $chapter, ChapterPlan $plan, GenerationArtifact $artifact): GenerationRun
    {
        $c = $artifact->generationRun->execution_contract;
        $fragments = array_map(fn ($id) => GenerationArtifact::findOrFail($id), $c['fragment_ids']);
        $fragments[] = $artifact;
        $continuation = (int) ($c['continuation'] ?? 0);
        if (! $artifact->data['complete']) {
            if ($continuation >= (int) config('generation.book.max_continuations', 2)) {
                throw new AiProviderException('book_continuation_exhausted', '截断续写次数已耗尽，片段均已保留，请人工编辑或局部修复。', false);
            }

            return $this->write($task, $chapter, $plan, $fragments, $continuation + 1);
        }
        if (count($fragments) - $continuation < $plan->admission_snapshot['fragment_count']) {
            return $this->write($task, $chapter, $plan, $fragments, $continuation);
        }
        $assembled = app(ChapterDraftService::class)->assemble($fragments);
        $draft = $this->draft($artifact->generationRun, $assembled['body'], $assembled['sources']);
        $chapter->update(['current_draft_artifact_id' => $draft->id, 'status' => ChapterStatus::Review]);

        return $this->downstream($task, $chapter, $plan, $draft, 'extract');
    }

    /** 确定性组装拥有独立 Artifact，保存完整来源清单和段落身份。 */
    public function draft(GenerationRun $run, string $body, array $sources, ArtifactType $type = ArtifactType::ChapterDraft): GenerationArtifact
    {
        $data = ['paragraph_index' => app(ChapterDraftService::class)->index($body), 'word_count' => mb_strlen(preg_replace('/\s/u', '', $body)),
            'chapter_plan_id' => $run->execution_contract['chapter_plan_id'], 'source_state_version_id' => $run->execution_contract['state_version_id']];
        $hash = app(GenerationInputIdentity::class)->hash([$body, $data, $sources]);

        return GenerationArtifact::firstOrCreate(['generation_run_id' => $run->id, 'type' => $type, 'checksum' => $hash],
            ['version' => ((int) GenerationArtifact::where('generation_run_id', $run->id)->where('type', $type)->max('version')) + 1, 'content' => $body, 'data' => $data, 'source_manifest' => $sources, 'validation_status' => 'validated']);
    }

    /** 提取与审校均绑定同一当前正文指针；换稿后旧请求只能留下隔离证据。 */
    public function downstream(NovelGenerationTask $task, Chapter $chapter, ChapterPlan $plan, GenerationArtifact $draft, string $phase, array $extra = [], array $ids = [], array $sources = []): GenerationRun
    {
        $a = $plan->admission_snapshot;

        return app(NovelStageContract::class)->enqueue($task, $phase, ['context' => $a['context'], 'plan' => $a['plan'], 'body' => $draft->content,
            'paragraph_index' => $draft->data['paragraph_index'], 'actual_words' => $draft->data['word_count'], ...$extra],
            [[...$this->source($draft), 'pointer' => 'current_draft_artifact_id', 'chapter_id' => $chapter->id], ...$sources],
            $this->identity($chapter, $plan) + ['draft_artifact_id' => $draft->id, ...$ids]);
    }

    /** 当前章只依赖实际使用目标的内容身份，远期新增目标不使它失效。 */
    private function identity(Chapter $chapter, ChapterPlan $plan): array
    {
        return ['chapter_id' => $chapter->id, 'chapter_plan_id' => $plan->id, 'goal_refs' => $plan->goal_refs];
    }

    private function source(GenerationArtifact $artifact): array
    {
        return ['artifact_id' => $artifact->id, 'checksum' => $artifact->checksum];
    }
}
