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

`AdvanceChapterPipelineAction` 是章节生成的唯一阶段推进规则。它在 `GenerationStageGate` 的 Novel 行锁内读取 PostgreSQL 中的 Plan、Scene 当前指针、Artifact 来源链、State Version 和 Review Decision，只派发下一个合法 Job；State Patch 由它在 Event Candidate 成功后同步构建。Plan、Scene、Assembly、Event Extraction、Review 和 Rewrite Job 成功后都调用该动作，不再各自维护后续分支。

推进器使用 `GenerationJobDispatcher` 的短期待执行标记消除重复入队窗口，但断点判断只依赖 PostgreSQL。Scene 按 sequence 严格串行；Chapter Draft 必须对应当前 Scene Artifact，Event Candidate 必须来源于当前 Chapter Draft，State Patch 必须来源于当前 Event Candidate 且匹配当前 State Version，Review 必须来源于当前 Chapter Draft。任一来源不匹配时，从最早失效阶段恢复，不复用失效的下游结果。PASS、NEEDS_ATTENTION、BLOCK、暂停、正式提交或废弃章节都不会继续派发生成 Job。

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

Filament 的章节生成和长跑入口必须捕获可预期的 Preflight、Budget 与 Validation 异常，以危险通知展示具体原因；这些业务拒绝不得变成 HTTP 500。

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

技术 Retry（timeout/429/5xx/network）与内容 Rewrite 必须分开。Provider 返回 `finish_reason=length` 且没有可解析结构化结果时，必须记录为对应阶段的 `*_output_truncated` 技术故障并交由 Queue 重试，不能把它误记为普通 Schema 内容错误后立即阻塞。明确拒绝与未截断的 Schema 错误仍是终止错误，避免对确定性无效输出无脑重试。

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

字数控制使用统一的多字节字符计数，并排除所有 Unicode 空白和换行。非末尾 Scene 可以按叙事需要短于平均值，未使用的字数预算由后续 Scene 承接；每个 Scene 同时受动态硬上限约束，且在计算当前上限时，必须按章节下限和 Scene 总数为每个尚未生成的后续 Scene 保留最低字数空间，不能让前置 Scene 用完章节硬上限后再把最后一个完整剧情任务压缩成极短文本。最后一个待生成 Scene 负责将场景总量补足至章节下限。Scene 和 Assembler 输出超出当前上下限时最多进行一次定向扩写或压缩；Rewrite 可进行最多两次，解决首次修复后仍轻微欠长或超长的问题。整章 Rewrite 的长度修复不得再次返回整章替换稿，而应返回逐字唯一命中的 `search` / `replacement` 局部补丁；Laravel 负责应用补丁和重新计数，并拒绝让过长稿跨越下限成为过短稿、或让过短稿跨越上限成为过长稿，以避免扩写和压缩在硬范围两侧振荡。修复提示使用目标字数 95%～105% 的窄目标区间提供安全余量，最终硬范围仍为 85%～115%。修复后仍不合规则不得提升为当前 Artifact。最终审校与 Canonical Commit 均由 Laravel 确定性检查该范围，超出范围必须进入 Rewrite，不能因模型评分较高而自动 PASS。人工确需接受超限版本时，必须使用独立的“接受超限版本”动作，保留原字数 Finding、正文实际字数、严格上限和原因，不得把它记录成清空问题的普通 Override。Assembler 和 Rewrite 可以补足既定场景的表现细节，但不得用重复内容凑字或新增重大事实。

Scene Draft 的正文、临时状态、声明事件和 Coverage 先经过本地校验。`temporary_state_delta` 或 `declared_events` 仅发生 JSON 语法或对象结构错误时，流水线最多执行两次 `scene-support-fields-repair-v1` 定向修复；该修复不得改写正文和 Coverage，也不得引入输入之外的新事实。无法可靠结构化的临时状态返回空对象，无法可靠结构化的声明事件丢弃。Coverage 引用先执行空白、引号和高置信连续重合片段的确定性归位，再执行独立的证据修复。证据修复耗尽后，不得把未经验证的引用当成事实，也不得仅因引用格式阻塞整章；系统将对应 Coverage 保守降为 `missing`，生成可自动 Rewrite 的计划覆盖 Finding。

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
{
  "content": "...",
  "temporary_state_delta": "{}",
  "declared_events": [],
  "uncertainties": [],
  "self_check": {
    "goal": {"status": "fulfilled", "evidence": "正文原句"},
    "conflict": {"status": "fulfilled", "evidence": "正文原句"},
    "turn": {"status": "fulfilled", "evidence": "正文原句"},
    "outcome": {"status": "fulfilled", "evidence": "正文原句"}
  }
}
```

`declared_events` 仅辅助，不是正式 Story Event。

`self_check` 的四项状态只能是 `fulfilled`、`missing` 或 `contradicted`。`fulfilled` 与 `contradicted` 必须引用当前 Scene 正文中的原句，`missing` 的 evidence 必须为 `null`。Laravel 校验固定结构与原文引用；仅有空白或外层引号差异时，将 evidence 映射回正文中的连续原句，仍无法逐字命中时只修复 Coverage evidence，不重新生成正文，也不得改变原 status。轻量修复响应因输出 Token 用尽而没有正文时，使用提高后的修复预算重试一次；错误信息必须区分输出截断与 Schema 非法。修复后仍不能逐字命中则本次 Scene Run 失败。缺失或反转项写为 Scene Artifact 的稳定 `plan_findings`；这些结果属于生成质量证据，不是 Canonical Fact，也不单独决定最终 Review Decision。

Scene Plan 的 `outcome` 由 `outcome_allowed` 和 `outcome_forbidden` 补充行为边界。边界保存在 Chapter Plan 的 `scene_plans` JSON 中，不新增 Scene 表字段；Scene Writer 从当前冻结 Plan 读取它们。

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

Assembler 使用结构化响应：`content`、按 Scene 顺序返回的 `scene_coverage`，以及必须为空的 `introduced_major_facts`。每个 coverage 固定检查 goal/conflict/turn/outcome，并执行与 Scene self-check 相同的 evidence 引用校验；Scene ID 必须完整、顺序一致、不得重复或跨章引用。若 Scene Draft 已把某项报告为 missing/contradicted，Assembler 不得将该项直接提升为 fulfilled。缺失或反转项写入 Chapter Draft Artifact 的 `plan_findings`，供后续最小范围修复使用。

上述校验可以确定响应结构、引用关系和模型是否声明新增重大事实；它不能只凭模型自报确定语义真实性。重大事实是否被隐性新增仍由后续 Event/State Validation 与最终 Review 检查。本阶段只允许修正无法逐字命中的 evidence quote，不修复 coverage 语义、不改变 status，也不改变最终 Review Decision。

幂等键：

```text
assemble:{chapter_id}:{ordered_scene_checksums}:{prompt_version}
```

技术失败只 Retry Assembly。

## 11. Event Extraction / State Validation

`ExtractStoryEventsJob` 输入 Chapter Draft、Plan、Current State、Locked Facts；输出 `event_candidate`。

`subject_type` 必须同时通过 Provider JSON Schema 和 Laravel 业务校验。`current_state.world.entities` 中的实体统一引用为 `world_entity`，不得把实体内部的 `concept`、`rule`、`location` 或 `faction` 分类直接作为 `subject_type`。Laravel 还必须校验事件类型与主体类型匹配，例如 `foreshadowing_*` 只能引用 `foreshadowing`。校验失败信息必须包含候选事件序号、字段、错误值和允许值。若候选事件只有 evidence quote 未逐字命中，系统可以在保持事件类型、主体、payload、时间和置信度不变的前提下单独修复 quote；外层引号、空白或省略号差异可以确定性映射回连续原文，模型省略说话人插入语时只保留与原文至少 80% 高度重合且不少于 8 字的连续片段。无法可靠定位的 Evidence Item 丢弃；事件至少保留一项逐字证据，否则拒绝创建 Event Candidate。轻量修复响应被 Token 截断时允许以更高预算重试一次。

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

Narrative Review 必须在一次响应中完成七个维度的全量审计，不得发现首个问题后提前结束。结构化输出为每个维度保存 `dimension_audits.status = pass|issues_found` 与简短结论；状态必须与该维度 Findings 一致，否则拒绝持久化。同一根因合并为一个 Finding，一轮内返回当前正文全部有明确证据的问题。Rewrite 后的 Review 同时接收当前 Chapter Plan 下上一轮全部可修复 Narrative Findings 作为回归清单，逐项确认是否消除，并继续执行七维全量检查；State、Plan Coverage 与字数问题仍由 Laravel 重新确定性检查。

Chapter Draft 中的结构化 `plan_findings` 与 Narrative/State/字数 Finding 一起进入 Laravel 决策。`RewriteScopeResolver` 使用确定性规则选择最小安全范围：单一有效 `scene_id` 进入 Scene Rewrite；Paragraph Finding 只在 evidence 能唯一命中一个当前 Scene Artifact 时映射到该 Scene；任一 Chapter Finding 或多个 Scene 受影响时进入 Chapter Rewrite。无效 Scene 引用、不支持的 scope 或不唯一的段落证据会追加 `REWRITE_SCOPE_UNRESOLVED` 并转为 `NEEDS_ATTENTION`，不猜测修复位置。Review Artifact 固定保存 `rewrite_scope`，队列派发与暂停恢复均优先使用该不可变路由。

`ReviewChapterJob` 保存结果后调用 `AdvanceChapterPipelineAction`。推进器只在 Decision 为 REWRITE 且范围、预算和状态允许时派发 `RewriteChapterJob`；PASS、NEEDS_ATTENTION 与 BLOCK 均停止。预算到限时保留已完成的 Review，写入 `budget_limit` 自动停止原因，不派发下一阶段；小说在结果保存后被暂停时同样不得派发。每个 Review Job 带有稳定的 operation ID，使同一强制审校投递被重复执行时复用已完成 Run，而新的人工强制审校仍可创建新 Run。

## 13. RewriteChapterJob

输入 Source Artifact、Review Findings 及 evidence、Plan 验收项、Current State、Locked Facts 和冻结 Style Contract；输出新的 `rewrite_draft`，不覆盖原 Artifact。

重写跨章连续性问题时同时输入 `previous_chapter_ending`，使模型能依据真实上一章结尾补写过渡，而不是只依赖 Finding 的概述。

Rewrite Brief 必须明确问题、证据、必须保留、预期修复和禁止改变内容。

同一 Review 的全部可修复 Findings 构成一个不可拆分的批量修复合同。Rewrite 必须在一次响应中逐项解决全部问题，随后对七个维度、计划边界、时间地点、人物状态、物品位置、动作因果和 Style Contract 进行全量自检，并在返回前修复发现的连带问题；不得只处理首个或最严重的 Finding。

整章 Rewrite 的首轮提示同时给出 95%～105% 的优选字数区间。若首轮仍越界，长度修复只请求最多 12 项精确局部替换，并给出进入优选区间所需的净增减字符数。`search` 必须在当前正文中逐字且唯一命中；压缩补丁的 replacement 必须更短，扩写补丁必须更长。Laravel 逐项应用并确定性校验方向和硬上下限，跨越另一侧硬边界的补丁拒绝应用，拒绝原因反馈给下一次修复。这样任何无效补丁都不会覆盖当前 Draft，也不会在压缩和扩写之间切换输入。

优先 Scene Rewrite，再 Whole Chapter Rewrite。Paragraph 在当前 Artifact 粒度下不单独产生半个 Scene 的 Artifact；可唯一定位的 Paragraph Finding 改写所属的完整 Scene。默认 `max_rewrite_attempts = 2`。

Scene Rewrite 返回结构化 `content + self_check`，保留 goal/conflict/turn/outcome 验收证据，仅更新目标 Scene 的 `current_artifact_id`；其他 Scene 指针保持不变，然后重新 Assembly 并继续 Event/Patch/Review。Chapter Rewrite 产生整章替换稿，然后直接重新 Event/Patch/Review。

自动 Rewrite 次数只统计当前成功 Chapter Plan 之后生成且不含 `manual_edit = true` 的 `rewrite_draft`；Reviewer、Rewriter 与章节工作台共用同一统计口径。人工修改不会消耗自动次数，但 Artifact 展示版本仍按全部不可变重写稿连续递增。自动派发读取 Review Artifact 中冻结的 `rewrite_scope`，按其选择 Scene 或 Chapter Rewrite。

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

Filament 的“生成下一章”会创建或恢复当前目标章，并立即调用 `AdvanceChapterPipelineAction`；“开始自动生成”完成相同的创建或恢复与推进，确认没有前置或断点错误后再开启 `auto_generate`。`auto_generate` 只表示用户手动提交后允许续接下一章，不改变 PASS 的停止规则。设置页不提供 `auto_commit`，历史数据库中尚未清理的同名键不参与运行时判断。

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

恢复操作先在事务内还原小说的生成状态，再在事务提交后调用统一推进器，避免在数据库事务完成前派发 Job。PASS 的恢复点标记为“等待提交正式章节”；恢复只解除暂停，不派发 `CommitChapterJob`。章节工作台同时显示当前 Stage、停止原因和下一可执行操作，分阶段按钮只用于调试、指定重跑和故障恢复。

不要根据 Redis Queue 中是否还有 Job 判断业务进度。

## 19. Crash Recovery

Worker Crash 可能留下 `generation_runs.status=running`。维护任务识别超时 Run，并标记 failed，例如 `error_code=worker_lost`。

恢复时根据 Artifact + input_hash 决定 reuse 或 retry。若经过 Schema/业务校验的不可变 Artifact 已经持久化，但 Worker 在把 Run 标记为 succeeded 前崩溃，恢复流程复用该 Artifact 并从其后续合法阶段继续；没有 Artifact 的失败 Run 才重试当前阶段。Chapter Draft 恢复后仍必须依次完成 Event Candidate、State Patch 和 Review。

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
chapter-planner-v6
scene-writer-v10
assembler-v8
event-extractor-v4
reviewer-v8
rewrite-v9
rewrite-length-patch-v1
summary-v1
```

模型按 Stage 从 Novel Settings / config 解析，不在 Job 中写死。解析优先级固定为：小说级非空 Stage Override → 全局非空 Stage Override → 全局 `AI_MODEL`。小说表单或 `.env` 中的 Stage Override 为空时必须继承全局模型，空字符串不是独立模型值。

当前只注册 `AI_PROVIDER=openai`，Provider 通过可配置的 `AI_BASE_URL` 调用 OpenAI-compatible Chat Completions 与 Embeddings。代码和 `.env.example` 当前将 `gpt-5.6-luna` 作为生成阶段默认模型，将 `text-embedding-3-small` 作为 Embedding 默认模型。仓库配置本身不能证明任意 `AI_BASE_URL`、账户或兼容服务都提供这些模型；部署者必须按实际端点核实可用性。示例值不代表历史 Run，历史实际模型以 `generation_runs.model_policy` 和 `usage_records.model` 为准。

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

### 23.1 操作与恢复

正常操作只有两个明确的人工作业点：在小说概览启动“生成下一章”，Laravel 自动推进 Plan、顺序 Scene、Assembly、Event Candidate、State Patch、Review 和必要的 Rewrite；到 Review PASS 后停止。操作人员确认当前草稿、事件和状态变化后，在章节工作台点击“提交正式章节”。PASS 本身不会提交，也不会写入正式 Story State、Story Event 或 Memory。

常见停止原因按以下方式处理：

| 停止状态或错误 | 已确认行为 | 操作入口 |
|---|---|---|
| `current_bible_incomplete` | 生成前置检查拒绝启动，不读取旧 `settings.editorial` 兜底 | 打开该小说的“小说圣经”，创建新的完整 Bible Version；明确填写 tone、POV、tense 和全部 Style Profile 后重新启动。当前没有独立的 Bible 迁移 Artisan 命令 |
| Stage/Provider 失败 | 成功的 Run/Artifact 保留；不得靠重放全部流水线覆盖历史产物 | 在“Generation → 恢复中心”查看错误和可重试性；可重试失败使用“重试”，暂停小说使用“恢复”。章节工作台的分阶段按钮仅用于定位后的调试或恢复 |
| Rewrite 耗尽 | 最后一次复审转为 `NEEDS_ATTENTION`，不再自动派发 Rewrite | 在章节工作台“审校”中“人工修改正文”并自动重走 Event/Patch/Review；无 Hard Conflict 且符合 Override 条件时可填写原因“人工通过（Override）” |
| Review PASS | 流水线停止在等待提交，不受 `auto_commit` 影响 | 在章节工作台点击“提交正式章节”；小说仍暂停时先在小说概览执行“继续” |
| NEEDS_ATTENTION / BLOCK | 自动推进停止；Hard Finding 不允许普通 Override | 按 Findings 修订正文或修复状态前置条件，再重新审校；历史 Review 和 Artifact 保留 |

`php artisan generation:mark-stalled` 只把超时的 queued/running Run 标记为失败，之后仍应通过“Generation → 恢复中心”按持久化状态恢复。`php artisan story:rebuild-state NOVEL_ID --dry-run` 和 `php artisan memory:rebuild NOVEL_ID` 分别用于正式状态校验和 Canonical Memory 重建，不用于绕过 Review 或提交草稿。

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
