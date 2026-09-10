# Generation Pipeline Design — 单人精简版

> 路径：`docs/architecture/generation-pipeline.md`
>
> 基线：`AGENTS.md`、`docs/PRD.md`、`docs/architecture/data-model.md`、`docs/architecture/story-engine.md`

## 1. 目标

将 Chapter Plan 稳定转换为通过 Review 的章节候选，并在用户确认后通过 Canonical Commit 成为正式章节。自动流水线到 Review PASS 停止；整个流程必须可追踪、可重试、可恢复、可暂停，且 Draft 永不修改 Canonical State。

Laravel 控制 Workflow；LLM 只负责 Planning、Writing、Semantic Review、Event Extraction、Summary。不同 Novel 可并行；同一 Novel 的章节 MVP 串行。

## 2. 权威流水线

新建小说先完成一次初始化规划：

```text
Novel.status = draft
→ NovelPlanner 生成结构化 Blueprint Artifact
→ 用户预览并采用
→ 写入包含完整叙事与文风设置的 Bible / Character / World / Volume / Arc / Foreshadowing
→ 初始化 Story State
→ Planning Readiness Check
→ Novel.status = generating
```

Blueprint 在采用前不得修改规划表；初始规划只能应用到尚无规划、章节和正式事件的小说，避免覆盖人工内容。首次 Blueprint 生成发生在 Current Bible 创建前，是唯一不能读取 Current Bible 的生成入口；采用后创建的 Current Bible 是后续章节叙事与文风的唯一权威来源。

进入 `generating` 后执行章节流水线：

```text
GenerateNextChapterAction
→ PlanChapterJob
→ ContextBuilder
→ GenerateSceneJob × N（顺序）
→ AssembleChapterJob
→ ExtractStoryEventsJob
→ StatePatchBuilder
→ StateValidator
→ ReviewChapterJob
→ ReviewGate
   ├ PASS → Stop，等待用户确认“提交正式章节”
   ├ REWRITE → RewriteChapterJob → Extract/Validate/Review
   └ NEEDS_ATTENTION/BLOCK → Stop

用户确认提交
→ CommitChapterJob → CanonicalCommitService
→ UpdateMemoryJob → GenerateEmbeddingJob → RollupSummaryJob
→ CheckNextAction
```

`PASS` 是 Review Decision，不是 Canonical 状态。`ReviewChapterJob` 不得因为 `auto_commit` 设置自动派发 Commit；只有用户确认动作可以启动 Canonical Commit。

## 3. Queue

MVP 只使用两个 Queue。

```text
generation:
  PlanChapterJob
  GenerateSceneJob
  AssembleChapterJob
  ExtractStoryEventsJob
  ReviewChapterJob
  RewriteChapterJob
  CommitChapterJob

default:
  UpdateMemoryJob
  GenerateEmbeddingJob
  RollupSummaryJob
  EndingAuditJob
```

`ContextBuilder` 作为 Service，不单独 Queue。只有出现真实拥堵后才拆更多 Queue。

## 4. GenerateNextChapterAction

Filament、Artisan、Scheduler 共用统一入口。

Preflight：

```text
Novel.status ∈ generating/completing
Novel 未暂停
Current Story State 存在
Current Volume 存在
Current Bible 存在且叙事与文风资料完整
当前小说已完成旧 Editorial 迁移
无 Blocking Review
无同 Novel 的另一个活跃 Chapter Workflow
Budget 未达到 Hard Limit
```

下一章 `sequence = current_chapter_sequence + 1`。已有同 sequence 非 Canonical Chapter 时恢复，不重复创建。

## 5. GenerationRun / Artifact

独立 Run Stage：

```text
chapter_planning
scene_generation
chapter_assembly
event_extraction
review
rewrite
commit
memory_summary
embedding
```

Run 状态：

```text
queued / running / succeeded / failed / cancelled
```

每个 Run 固定记录：

```text
novel_id
chapter_id
scene_id?
stage
attempt
idempotency_key
input_hash
state_version
bible_version
prompt_version
model_policy
context_snapshot
```

Artifact 不可变，类型：

```text
chapter_plan
scene_draft
chapter_draft
rewrite_draft
event_candidate
state_patch
review_result
summary
context
```

`input_hash` 只包含真正影响输出的 Prompt、Model、State、Plan、Context、Source Artifact checksum。

通用幂等：

```text
计算 idempotency_key + input_hash
→ 查 succeeded Run/Artifact
→ hash 相同：reuse
→ hash 不同：new attempt
```

技术 Retry（timeout/429/5xx/network）与内容 Rewrite 必须分开。

Filament 发起生成任务时，必须在派发前写入带 TTL 的临时待执行标记，并在标记存在或数据库已有 `queued / running` Run 时禁用本章的生成操作。Queue Job 同时使用按阶段与业务对象定义的唯一键，防止页面刷新、多标签页或并发请求重复入队。Job 成功或最终失败后清除临时标记；Worker 异常退出时由 TTL 自动释放。该标记只用于弥补 Job 入队到 `GenerationRun` 创建之间的可见性窗口，业务恢复与执行进度仍以 PostgreSQL 中的 Run 和 Artifact 为准。

## 6. PlanChapterJob

输入：

```text
Novel
Current Bible
Current Volume
Active Arcs
Current Story State
Due Foreshadowings
Recent Summaries
Previous Canonical Chapter Ending
Ending Contract
Closure Debt（completing 时）
```

输出：`chapter_plans` + `chapter_plan` Artifact。

幂等键：

```text
plan:{chapter_id}:{state_version}:{bible_version}:{prompt_version}:{model_policy_hash}
```

Plan 至少包含：

```text
chapter_function
arc_contribution
reader_promise
target_words
pov_character
tone
time_anchor
hook_type
must_reveal / may_hint / must_not_reveal
required_facts / forbidden_conflicts
due_foreshadowings
scene_plans
```

`scene_plans[*].transition_from_previous` 明确记录衔接安排。存在上一章正式版本时，第一场景必须说明如何承接上一章结尾；发生时间、地点或行动跳跃时，正文必须呈现必要的抵达、安置或时间流逝过程，不能直接从上一章行动跳到次日新地点。

小说级 `Style Profile` 只从 Current Bible Version 构建，由 Bible 的 tone、pov、tense、主文风 Preset、最多两种辅助文风、语言时代感、故事节奏及六项可选参数组成。Prompt Config 只负责将稳定 code 展开为指令，不能成为第二个小说级来源。Chapter Planner 使用小说设置确定 `target_words`；Scene Writer 共享章节总字数预算，按其他场景实际字数和剩余场景数动态计算当前参考字数；Assembler 继续遵守同一总字数与 Style Profile。题材、故事基调和人物属性不得混入文风名称。

字数控制使用统一的多字节字符计数，并排除所有 Unicode 空白和换行。非末尾 Scene 可以按叙事需要短于平均值，未使用的字数预算由后续 Scene 承接；每个 Scene 同时受动态硬上限约束，最后一个待生成 Scene 负责将场景总量补足至章节下限。Scene、Assembler 和 Rewrite 输出超出当前上下限时最多进行一次定向扩写或压缩，修复后仍不合规则不得提升为当前 Artifact。Chapter Draft 的严格可接受范围默认为目标字数的 85%～115%；最终审校与 Canonical Commit 均由 Laravel 确定性检查该范围，超出范围必须进入 Rewrite，不能因模型评分较高而自动 PASS。人工确需接受超限版本时，必须使用独立的“接受超限版本”动作，保留原字数 Finding、正文实际字数、严格上限和原因，不得把它记录成清空问题的普通 Override。Assembler 和 Rewrite 可以补足既定场景的表现细节，但不得用重复内容凑字或新增重大事实。

Schema 校验实体引用、Scene 数量和目标字数；业务校验 Arc 推进、Critical Foreshadowing、Locked Fact、Knowledge Boundary 和 Current State。

`completing` 时禁止无批准新增核心主线、核心人物、硬规则、高重要度伏笔，并要求推进 Ending Plan 或降低 Closure Debt。

技术失败 Retry；业务计划错误定向 Replan。成功后 `chapter.status = generating`。

## 7. ContextBuilder

每个 Scene 调用前按优先级组装：

```text
1 System / Output Schema
2 Bible Hard Constraints
3 Ending Contract
4 Volume / Arc / Chapter Plan
5 Current Canonical Story State
6 Relevant Characters
7 Relevant World Entities
8 Due Foreshadowings
9 Required / Forbidden Facts
10 Recent Chapter Summaries
11 Previous Accepted Scene Tail
12 Long-term Memory
13 L4 Style Contract（Current Bible Version）
14 Scene Task
```

Token 不足时先缩减 Long-term Memory、较旧 Summary、Style Example；不得删除 Hard Constraints、Current State、Required/Forbidden Facts、Ending Constraints。

Snapshot 必须记录版本、实体 IDs、Fact/Memory IDs、Recent Chapters、Previous Artifact、Prompt/Model、Token Budget，以及 Current Bible Version 和 Style Contract checksum。同一 Chapter Pipeline 冻结一个 Bible Version，不在中途静默切换。

## 8. Temporary Chapter State

Scene 之间允许 Temporary State，但绝不是 Canonical State。

MVP 不新增表。建议每个 Scene Artifact 的 `data` 保存 `temporary_state_delta`，下一 Scene 构建 Context 时按顺序应用。

## 9. GenerateSceneJob

每个 Scene 一个 Run，严格顺序执行。

输入 Scene Plan、Chapter Plan、Canonical State、Temporary State、Context Snapshot、Previous Scene Tail；输出 `scene_draft`。

第一场景同时读取 `previous_chapter_ending` 和 `transition_from_previous`，保证正文实际写出跨章衔接。

建议 Envelope：

```json
{"content":"...","declared_events":[],"uncertainties":[],"self_check":{}}
```

`declared_events` 仅辅助，不是正式 Story Event。

幂等键：

```text
scene:{scene_id}:{input_hash}:{prompt_version}:{model}
```

轻量校验正文非空、长度合理、Envelope 合法、参与角色有效、无明显 must_not_reveal 违规。

Provider Retry 默认 2~3 次，指数退避 + jitter，并尊重 Retry-After。最后一个待生成场景若无法补足章节最低字数，使用返回的短稿进行最多一次定向扩写，而不是重复发送相同原始请求。耗尽后 Run failed、Chapter blocked、Auto Generation stop。成功 Scene Artifact 保留，Resume 从失败 Scene 继续。

人工重试或重新生成某个 Scene 时，从该 Scene 开始清空当前产物指针并将它及后续 Scene 重置为 `planned`；历史 Artifact 保留。系统使用同一个重生成批次标识按顺序投递这些 Scene，后续 Scene 根据前面实际完成字数承接章节剩余预算。同一批次重复投递时复用成功 Artifact。

同一 Scene 每次成功生成的 `scene_draft` Artifact 按生成顺序递增 `version`。Scene 只通过 `current_artifact_id` 指向当前版本，历史版本保持不可变；章节工作台必须同时标明当前版本与历史版本，并显示关联 Run 和生成时间。

MVP 不做 Scene Parallel。

## 10. AssembleChapterJob

输入 Ordered Scene Artifacts + Chapter Plan + Style Constraints；输出 `chapter_draft`。

只负责衔接、过渡、语气统一、重复清理、局部语言修正，不得主动改变 Scene Outcome、增加重大事实/能力/世界规则/人物知识。

幂等键：

```text
assemble:{chapter_id}:{ordered_scene_checksums}:{prompt_version}
```

技术失败只 Retry Assembly。

## 11. Event Extraction / State Validation

`ExtractStoryEventsJob` 输入 Chapter Draft、Plan、Current State、Locked Facts；输出 `event_candidate`。

`subject_type` 必须同时通过 Provider JSON Schema 和 Laravel 业务校验。`current_state.world.entities` 中的实体统一引用为 `world_entity`，不得把实体内部的 `concept`、`rule`、`location` 或 `faction` 分类直接作为 `subject_type`。Laravel 还必须校验事件类型与主体类型匹配，例如 `foreshadowing_*` 只能引用 `foreshadowing`。校验失败信息必须包含候选事件序号、字段、错误值和允许值。

幂等键：

```text
events:{draft_checksum}:{state_version}:{prompt_version}
```

之后 Laravel `StatePatchBuilder` 确定性生成 `state_patch` Artifact；`StateValidator` 校验 Current State、Candidates、Patch、Locked Facts、Plan Overrides。Hard Conflict 必须阻止 PASS。

人工重新提取事件也必须继续执行 State Patch 和 Review。Review 开始前若缺少当前草稿对应的 Event Candidate 或 State Patch，流程以 `review_prerequisite_missing` 终止，不调用模型，也不创建错误的 BLOCK Review。历史上由缺失补丁造成的 BLOCK 在章节工作台提供“补建状态补丁并重新审校”恢复入口，原 Review 保持不可变。

章节工作台中的“状态变化”页签用于展示当前 Draft → Event Candidate → State Patch 的只读待提交预览。正常事件提取完成后自动构建 State Patch；只有当前最新 Event Candidate 缺少匹配 Patch 时才显示“补建状态补丁”。页面和 `StateValidator` 不得把旧 Event Candidate 对应的 Patch 显示或校验为当前补丁。State Patch 不修改 Canonical Story State，只有 Review PASS 后的 Canonical Commit 才使其生效。

## 12. ReviewChapterJob

Review 汇总：

```text
Narrative Review
State Findings
Plan Adherence
Character Consistency
Plot Progress
Repetition
Pacing
Style
Previous Canonical Chapter Ending
```

连续性维度必须比较上一章正式结尾与本章开头，对未交代的时间、地点或行动跳跃给出可执行 Finding。

输出 `reviews` + `review_result`。

幂等键：

```text
review:{draft_checksum}:{state_version}:{review_policy_version}
```

Decision：

```text
PASS             无 Hard Conflict 且评分达标
REWRITE          问题可定位、次数和预算允许
NEEDS_ATTENTION  Rewrite 耗尽、重大歧义、Ending 冲突或需人工决策
BLOCK            Locked Fact 或其他不可接受硬冲突
```

普通 warning 按可执行性分流：可自动修复则 REWRITE；不影响发布且无需修复可 PASS；真正需要用户选择或 Rewrite 耗尽才 NEEDS_ATTENTION。Hard Conflict 必须 BLOCK，模型顶层建议不能覆盖 Laravel 的确定性决策。

Narrative Finding 使用固定 code，并包含 `dimension`、`severity`、`scene_id`、`scope`、`auto_fixable`、`requires_human_decision`、`message`、`evidence`。`scene_id` 非空时必须属于本章，`scope = scene` 时必须提供；模型不能创建 hard finding。低于通过分数却没有可自动修复或需要人工决策的 Finding，属于不一致的 Reviewer 响应，应拒绝持久化。最终 Review Artifact 保存命中的决策规则和 Finding code，模型的 `recommended_decision` 仅作为审校证据保存。

## 13. RewriteChapterJob

输入 Source Artifact、Review Findings、Plan、Current State、Locked Facts；输出 `rewrite_draft`。

重写跨章连续性问题时同时输入 `previous_chapter_ending`，使模型能依据真实上一章结尾补写过渡，而不是只依赖 Finding 的概述。

Rewrite Brief 必须明确问题、证据、必须保留、预期修复和禁止改变内容。

优先 Scene Rewrite，再 Whole Chapter Rewrite。默认 `max_rewrite_attempts = 2`。

最后一次 Rewrite 后若重新审校仍应为 `REWRITE`，Laravel 必须在该次 Review 中直接将最终决策转为 `NEEDS_ATTENTION`，不得等待一次无法从 UI 发起的额外 Rewrite 才标记耗尽。

`NEEDS_ATTENTION` 必须提供明确的人工处理入口：人工修改正文时创建新的不可变 `rewrite_draft`，记录修改原因，并重新执行事件提取、状态补丁和 Review；没有 Hard Conflict 时允许填写原因后人工 Override，创建新的 PASS Review，同时保留原 Review、Findings 和操作原因。

Rewrite 后必须重新：

```text
Extract Events → Build State Patch → State Validation → Review
```

不得沿用旧 Candidate/Patch。

幂等键：

```text
rewrite:{source_artifact_id}:{finding_hash}:{attempt}:{prompt_version}
```

## 14. CommitChapterJob

只加载冻结 Commit Inputs 并调用 `CanonicalCommitService`，不调用 Writer、Reviewer、Extractor。

该 Job 只由用户在 PASS 后确认“提交正式章节”时派发；Review PASS、自动生成开关或 Resume 本身都不得自动派发 Commit。

前置：

```text
Novel 未暂停且 status ∈ generating/completing
Chapter 未 canonical
Review PASS
无 Hard Finding
Artifact checksum 未变化
Expected State Version 匹配
```

幂等键：

```text
commit:{chapter_id}:{artifact_checksum}:{expected_state_version}
```

事务：

```text
BEGIN
SELECT novel FOR UPDATE
Validate state version
SELECT chapter FOR UPDATE
Validate review/artifact
Persist Story Events
Apply Fact Changes
Create State Version N+1
Update Chapter
Update Novel pointers
COMMIT
```

相同 Commit 重复执行返回已有结果；不同 Artifact 覆盖已 Canonical Chapter必须 BLOCK。Commit 阶段禁止外部 LLM 调用。

## 15. Post-Commit

事务内：

```text
Canonical Artifact Pointer
Story Events
Fact Changes
Story State Version
Chapter Canonical State
Novel Canonical Pointers
```

事务后：

```text
Memory
Embedding
Chapter Summary
Projection Refresh
Cache Refresh
Metrics
```

Derived Work 失败不得回滚正式章。

## 16. Memory / Embedding / Summary

`UpdateMemoryJob` 只从 Canonical Chapter + Active Story Events + New State 创建正式 Memory。

幂等键：

```text
memory:{canonical_artifact_checksum}:{memory_policy_version}
```

`GenerateEmbeddingJob` 幂等键：

```text
embedding:{memory_id}:{embedding_model}
```

Embedding 失败只 Retry。

`RollupSummaryJob` 更新 `chapters.summary`；幂等键：

```text
summary:{canonical_artifact_checksum}:{summary_prompt_version}
```

摘要失败不回滚正文。

## 17. Auto Generate

Auto Generate 不能预先 Queue 100 章。

Auto Generate 只自动推进当前章到 Review PASS，不自动 Canonical Commit。用户手动提交 Chapter N 后，才执行 Post-Commit 并启动 Chapter N+1。

```text
Chapter N Commit
→ Post-Commit
→ Check Stop Conditions
→ GenerateNextChapterAction
→ Chapter N+1
```

下一章必须基于上一章最新 Canonical State。

停止条件：

```text
User Pause
Emergency Stop
Hard Conflict
NEEDS_ATTENTION
BLOCK
Provider Retry Exhausted
Repeated Rewrite Failure
Budget Hard Limit
State Version Conflict
Volume Gate Failure
Ending Audit Block / Critical Closure Debt
Novel completed/failed/archived
```

## 18. Pause / Resume

Pause 停止派发新 Stage；已发出的 Provider 调用可结束并保存 Artifact，但不得继续 Canonical Commit。Commit 前必须再次检查 Pause。

Resume 通过数据库状态和 Artifact 判断恢复点：

```text
Chapter canonical → Post-Commit / Next Action
Review PASS、Commit 未完成 → 停止并等待用户手动 Commit
Review = REWRITE → Rewrite
Draft 存在、无有效 Review → Extract/Validate/Review
Scenes 全部完成、无 Chapter Draft → Assemble
部分 Scene 完成 → 下一个未完成 Scene
Plan 已完成 → Scene 1
无 Plan → Plan
```

不要根据 Redis Queue 中是否还有 Job 判断业务进度。

## 19. Crash Recovery

Worker Crash 可能留下 `generation_runs.status=running`。维护任务识别超时 Run，并标记 failed，例如 `error_code=worker_lost`。

恢复时根据成功 Artifact + input_hash 决定 reuse 或 retry。

错误分类：

```text
Retryable: timeout / 429 / 5xx / network
Rebuild: state_version_conflict / stale_context / stale_plan
Rewrite: continuity / plan / style / repetition
Human/Block: locked_fact / ambiguity / rewrite_exhausted / budget / ending_conflict
```

## 20. Timeout / Retry Budget

统一配置 planning、scene_generation、assembly、event_extraction、review、rewrite、embedding timeout。

默认超时链：

```text
Provider request timeout = 60s
单次 Provider 调用 Job = 90s
可能执行一次字数修复的 Scene / Assembly / Rewrite Job = 180s
Horizon worker timeout = 210s
Redis retry_after = 240s
Generation Run stalled threshold = 300s
```

必须始终满足：

```text
Provider 调用预算 < Job timeout < Horizon worker timeout < Redis retry_after < stalled threshold
```

本地 Horizon 为 `generation` 与 `default` 各保留一个 Worker，总进程数固定为两个，避免短任务结束后频繁缩容产生无业务失败含义的 `Worker STOPPED Interrupted`。生产环境可以继续自动扩缩容。

同时限制：

```text
max_provider_retries
max_rewrite_attempts
chapter_max_cost
```

避免 Retry × Rewrite 导致调用失控。

## 21. Cost Tracking

每个真实 Provider Request 必须写 `usage_records`，可追踪：

```text
Novel → Chapter → Scene → Run → Stage → Provider/Model → Tokens/Cost
```

Artifact Reuse 不产生新的 Provider Usage。

Hard Budget 至少在 Chapter 开始、每个新 Provider Request、Rewrite、自动进入下一章前检查。

## 22. Prompt / Model Policy

每个 AI Stage 记录 Prompt Version，例如：

```text
chapter-planner-v5
scene-writer-v9
assembler-v7
event-extractor-v4
reviewer-v6
rewrite-v6
summary-v1
```

模型按 Stage 从 config / Novel Settings 解析，不在 Job 中写死。MVP 只实现当前实际使用的 Provider。

## 23. Observability

从任意 Chapter 必须能追踪：

```text
Plan
→ Runs
→ Context Snapshots
→ Scene Artifacts
→ Chapter Draft
→ Event Candidate
→ State Patch
→ Review
→ Commit
→ State Version
→ Usage
```

Horizon Tags：

```text
novel:{id}
chapter:{id}
scene:{id}
run:{id}
stage:{stage}
```

## 24. 测试

Workflow：

```text
normal full chapter
multiple scenes sequential
review pass then stop without commit
manual commit after pass
rewrite then pass
rewrite exhausted
needs attention
block
```

Idempotency：

```text
duplicate Plan
duplicate Scene
duplicate Review
duplicate Commit
duplicate Memory
duplicate Embedding
```

Recovery：

```text
Scene 3 timeout after Scene 1/2 success
worker crash after artifact persisted
pause during provider call
resume from assembled draft
resume from PASS waits for manual commit
state version conflict before commit
```

Canonical Safety：

```text
Draft never changes state
Pause blocks commit
Hard Conflict blocks commit
Duplicate Commit exactly-once
Embedding failure does not rollback chapter
```

Cost：

```text
reuse adds no Provider Usage
hard budget blocks new request
rewrite respects limit
```

## 25. Implementation Batches

```text
G1 GenerateNextChapterAction + Preflight + Run helpers
G2 PlanChapterJob + Plan validation
G3 ContextBuilder + Snapshot + Token Budget
G4 GenerateSceneJob + Temporary State + Resume
G5 AssembleChapterJob
G6 Event Extraction + StatePatch integration
G7 Review + Rewrite loop
G8 CommitChapterJob integration
G9 Memory / Embedding / Summary
G10 Auto Generate + Pause/Resume + Recovery
```

每个 Batch 完成测试后再进入下一批。

## 26. 暂不实现

```text
Scene Parallel Generation
Multi-Agent Workflow
Distributed Workflow Engine
Kafka / RabbitMQ
Complex Provider Router
预先批量 Queue 大量未来章节
跨 Novel 共享 Story State
```

## 27. Codex 第一任务

```text
阅读：

AGENTS.md
docs/PRD.md
docs/architecture/data-model.md
docs/architecture/story-engine.md
docs/architecture/generation-pipeline.md

当前只实现 Generation Pipeline Batch G1：

- GenerateNextChapterAction
- Preflight
- GenerationRun 基础 helper
- Chapter 创建/恢复逻辑

要求：

1. 先检查现有项目。
2. 先输出实现计划，不修改代码。
3. 不实现 LLM Provider。
4. 不实现 PlanChapterJob。
5. 不实现 ContextBuilder。
6. 不实现 Queue 后续阶段。
7. 确保同 Novel 不会创建两个活跃 Chapter Workflow。
8. 已存在同 sequence 非 Canonical Chapter 时恢复。
9. 添加 Preflight / duplicate / resume 基础测试。
10. 遵守 AGENTS.md 的单人精简原则。
```

## 28. Definition of Done

完整 Generation Pipeline 完成时必须满足：

```text
一章可从 Plan 自动运行到 Review PASS
PASS 后只有用户确认才能启动 Canonical Commit
Scene 可从中断点恢复
成功 Artifact 可复用
Provider Retry 与 Rewrite 分离
Review Gate 可阻断错误
Duplicate Commit exactly-once
Pause 后无新 Commit
Resume 能识别正确恢复点
Post-Commit 失败不回滚正文
Auto Generate 每次只推进一章
Hard Stop 条件可靠
所有 Provider Cost 可追踪
```

## 29. Final Invariants

```text
1. Laravel controls workflow.
2. Draft never mutates canonical state.
3. Same Novel chapters are sequential in MVP.
4. Successful expensive work is reusable.
5. Every important stage is traceable by Run/Artifact.
6. Technical Retry is different from content Rewrite.
7. Rewrite always re-runs extraction/validation/review.
8. Commit performs no creative LLM calls.
9. Canonical Commit is atomic and idempotent.
10. Post-commit derived failures never rollback canonical text.
11. Resume is determined from persisted state, not queue presence.
12. Auto generation stops on hard errors and advances only after a user-confirmed commit.
```

# END OF generation-pipeline.md
