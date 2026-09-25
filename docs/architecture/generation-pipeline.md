# Generation Pipeline Design — 单人精简版

> 路径：`docs/architecture/generation-pipeline.md`
>
> 基线：`AGENTS.md`、`docs/PRD.md`、`docs/architecture/data-model.md`、`docs/architecture/story-engine.md`

## 1. 目标

将 Chapter Plan 稳定转换为通过 Review 的章节候选，并按小说级策略自动或经用户确认后，通过 Canonical Commit 成为正式章节。`auto_commit` 默认关闭；整个流程必须可追踪、可重试、可恢复、可暂停，且 Draft 永不修改 Canonical State。

Laravel 控制 Workflow；LLM 只负责 Planning、Writing、Semantic Review、Event Extraction、Summary。不同 Novel 可并行；同一 Novel 的章节 MVP 串行。

## 2. 权威流水线

新建小说先完成一次可人工确认的全书大纲与初始化规划：

```text
Novel.status = draft
→ 用户手工创建，或 GenerateNovelOutlineJob 在 generation 队列调用 NovelPlanner
→ 生成结构化 Blueprint / Outline Artifact
→ Outline Draft Version
→ 用户逐项编辑、校验并采用
→ Current Novel Outline
→ 写入包含完整叙事与文风设置的 Bible / 初始 Character / World / Volume / Arc / Foreshadowing
→ 初始化 Story State
→ Planning Readiness Check
→ Novel.status = generating
```

Blueprint / AI Outline Candidate 在采用前不得修改规划表；初始规划只能应用到尚无规划、章节和正式事件的小说，避免覆盖人工内容。首次 Blueprint 生成发生在 Current Bible 创建前，是唯一不能读取 Current Bible 的生成入口；采用后创建的 Current Bible 是后续章节叙事与文风的唯一权威来源，Current Novel Outline 是后续 Chapter Planning 的顺序和主线权威。Laravel 选择当前节点；LLM 不拥有 Beat 排序、主线切换、跳过或删除节点的权限。

AI 全书大纲必须异步执行。Filament Action 只向 `generation` 队列投递 `GenerateNovelOutlineJob` 并立即返回；Job 调用 `NovelPlanner` 创建 Generation Run、Artifact 和 Draft Outline。这样长耗时模型调用不会受 PHP-FPM Web 请求时限影响，同一 Novel 的重复投递由唯一 Job 合并，可恢复的 Provider 错误按 Job 策略重试。规划请求使用 24,000 completion token；推理程度读取 `planner` 模型路由。新增该字段的迁移会把已有 `planner` 路由回填为 `low`，延续原有全书大纲行为，并避免推理 token 耗尽预算后没有结构化正文。

长篇结构化输出采用分层超时：Provider 请求最多 300 秒，AI Job 330 秒，Horizon Worker 360 秒，Redis `retry_after` 420 秒，停滞 Run 判定 480 秒。外层必须晚于内层终止，避免仍在生成的请求被误判为 Worker 丢失或重复投递。

进入 `generating` 后执行章节流水线：

Chapter Planner 的 completion token 上限独立于 Scene Writer：首次请求默认 12,000，失败后的新 Run 默认 16,000，并把实际采用值冻结到 `context_snapshot.generation_preferences.max_completion_tokens`。OpenAI 的推理 Token 与结构化正文共用 completion 额度；若 `finish_reason=length`，Provider 必须返回实际 Token 用量供费用追踪，再由结构化输出层标记为可重试的 `plan_output_truncated`，不能让同一低上限重复消耗全部重试次数。

Scene Writer 同样为推理 Token 和结构化正文保留独立额度：首次请求默认 12,000，后续 Run 默认 16,000，实际值冻结进 Run Snapshot。Provider 连接请求超时默认 150 秒；Scene 最多包含一次长度修复，因此两次最坏请求仍须小于 330 秒 Job timeout。旧版默认值创建且仍保持 60 秒的 DeepSeek 连接在迁移时提升到 150 秒，后台人工设置为其他值的连接不覆盖。

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
   ├ PASS + auto_commit=false → Stop，等待用户确认“提交正式章节”
   ├ PASS + auto_commit=true → CommitChapterJob
   ├ REWRITE → RewriteChapterJob → Extract/Validate/Review
   └ NEEDS_ATTENTION/BLOCK → Stop

自动策略或用户确认提交
→ CommitChapterJob → CanonicalCommitService
→ UpdateMemoryJob
→ GenerateCanonicalChapterSummaryJob
→ RefreshNovelProjectionJob
→ ContinueAutoGenerationJob → CheckNextAction
```

`GenerateEmbeddingJob` 由 Memory 更新独立派发并重试，不在阻塞自动续写的 Queue Chain 中。

`PASS` 是 Review Decision，不是 Canonical 状态。`ReviewChapterJob` 只在 Review Artifact 对应当前 Draft、小说未暂停、`settings.auto_commit_configured=true` 且 `settings.auto_commit=true` 时自动派发 Commit；遗留的未确认 `auto_commit` 键保持惰性。关闭时只允许用户确认动作启动 Commit。两条路径都调用同一 `CommitChapterJob → CanonicalCommitService`，不得复制或绕过提交门禁。

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
  GenerateCanonicalChapterSummaryJob
  RefreshNovelProjectionJob
  ContinueAutoGenerationJob
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

技术 Retry（timeout/429/5xx/network）与内容 Rewrite 必须分开。Provider 返回 `finish_reason=length` 且没有可解析结构化结果时，必须记录为对应阶段的 `*_output_truncated` 技术故障，不能把它误记为普通 Schema 内容错误。使用固定预算的全书大纲请求遇到截断时终止当前 Job，避免相同参数自动重试并重复计费；其他阶段只有在提高后续请求预算时才允许重试。明确拒绝与未截断的 Schema 错误仍是终止错误，避免对确定性无效输出无脑重试。

Filament 发起生成任务时，必须在派发前写入带 TTL 的临时待执行标记，并在标记存在或数据库已有 `queued / running` Run 时禁用本章的生成操作。Queue Job 同时使用按阶段与业务对象定义的唯一键，防止页面刷新、多标签页或并发请求重复入队。Job 成功或最终失败后清除临时标记；Worker 异常退出时由 TTL 自动释放。该标记只用于弥补 Job 入队到 `GenerationRun` 创建之间的可见性窗口，业务恢复与执行进度仍以 PostgreSQL 中的 Run 和 Artifact 为准。

## 6. PlanChapterJob

输入：

```text
Novel
Current Bible
Current Novel Outline
Current Volume
Active Arcs
Current Outline Target
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
plan:{chapter_id}:{state_version}:{bible_version}:{outline_id}:{outline_checksum}:{prompt_version}:{model_policy_hash}
```

调用模型前，`OutlineProgressResolver` 必须确定性执行：

```text
读取 novels.current_outline_id
→ 选择 Active Volume
→ 选择该 Volume 中 sequence 最小的 Active Main Arc
→ 从 Active story_arc_beat_completed Events 取得已完成 Beat Keys
→ 合并经人工确认的历史 baseline_completions（仅用于迁移起点）
→ 选择 sequence 最小的未完成 Beat
→ 统计该 Beat 已占用的 Canonical Chapter 数
→ 生成 Current Outline Target
```

`baseline_completions` 不会创建 Story Event，也不会推进 Arc Progress；新 Outline 生效后的 Beat 只能由 Canonical Commit 完成。Current Outline Target 必须包含 Volume / Arc / Beat Key、标题、Sequence、预算、验收条件、必须/禁止内容以及已解析 Candidate。

若当前 Beat 的 Canonical Chapter 使用数已经达到非空 `chapter_budget.max` 且仍无正式 Completion，系统必须在创建 Planning Run 和调用 Provider 前停止自动生成，进入 `NEEDS_ATTENTION`。用户只能通过延长预算、修订未来 Outline、人工调整新 Plan 或处理历史映射后再继续；LLM 不能自行跳到下一 Beat。

Plan 至少包含：

```text
chapter_function
arc_contribution
arc_contributions
character_candidates
world_entity_candidates
reader_promise
target_words
pov_character
tone
time_anchor
hook_type
must_reveal / may_hint / must_not_reveal
required_facts / forbidden_conflicts
foreshadowing_actions
scene_plans
```

`chapter_plans.novel_outline_id` 冻结本次规划采用的精确 Outline Version。`arc_contributions` 以 `arc_id + beat_key + beat_index + role` 引用该版本的真实 Beat，并冻结目标 Scene 和验收条件；每个新 Plan 必须恰有一个 `role=primary`，且只能是 Current Outline Target。Secondary 允许推进获准支线，但不能替代 Primary 或提前完成后续 Main Beat。自然语言 `arc_contribution` 仅作说明。

`character_candidates` 与 `world_entity_candidates` 以稳定临时键记录正文确实需要且 Canonical Domain 中尚不存在的对象，并包含去重依据、潜在重复对象、引入理由和目标 Scene。它们在 Review PASS 和 Canonical Commit 前都只是 Draft 契约。Planner 只能选择 Current Beat 授权的 Candidate；不得仅因模型认为剧情需要就新增核心人物或世界规则。

PlanValidator 必须拒绝缺失 Primary、Outline Version 不一致、已完成 Beat、顺序跳跃、预算耗尽、必须内容缺失、规划禁止内容和非法 Character Candidate。若一章不能完成当前 Beat，Plan 必须给出可验证的中间结果，不能重复背景说明。

Generation Run 的 Context Snapshot 固定记录：

```text
novel_outline_id / outline_version / outline_checksum
primary_arc_id / primary_beat_key / primary_beat_sequence
canonical_completed_beat_keys
chapters_used_for_current_beat
chapter_budget
```

新 Plan 的每个 Scene 使用 `continuity_requirements` 和稳定 `key` 区分 `establish / persist / change / callback`。相同持续状态只能首次建立一次；`persist` 只要求当前 Scene 的增量影响，`change` 要求真实状态变化，`callback` 只允许在章末回扣。Plan Validator 在 Writer 前拒绝跨 Scene 重复的 goal/conflict/turn/outcome 或错误的连续性阶段；历史 Plan 可缺少该字段并保持只读兼容。

`due_foreshadowings` 的历史整数数组只用于兼容解释，不能满足新 Plan 的伏笔动作校验。`chapter-planner-v10` 写入 `foreshadowing_actions`；每项包含 `foreshadowing_id`、`action`、`target_scene_sequence`、`acceptance_criteria` 和可空 `reason`。模型 Schema 只允许 `plant / reinforce / pay_off`。`defer / abandon` 只能由用户从人工计划编辑或伏笔管理入口执行；Plan 中的授权同时保存原因、操作者、授权时间、当时 Canonical 章节与 State Version，`defer` 还保存晚于旧窗口的新窗口。人工编辑创建新的 Plan Version并保留旧版本。伏笔管理页直接延期时更新窗口并追加 `management_history`；直接放弃时通过 `ManualCorrection` 创建新 State Version。

`due_from_chapter`～`due_to_chapter` 是兑现窗口。Planning 以目标章序号选择本章机会，但正式 `upcoming / due / overdue` 只按最新 Canonical 章节计算。Planner 同时接收完整伏笔定义、Canonical 生命周期来源、领域投影状态、允许动作和已发生的重要 Active Events。Critical 在窗口内必须有动作；目标章为 `due_to` 时不能只做 `reinforce`；窗口结束后，`ForeshadowingPlanningGate` 在创建 Planning Run 和调用模型前阻止自动 Planner。此时只有人工建立包含 `pay_off`，或具有有效人工授权的 `defer / abandon` 动作契约，才构成可继续执行的修复计划。非 Critical 逾期只产生 Warning。

`scene_plans[*].transition_from_previous` 明确记录衔接安排。存在上一章正式版本时，第一场景必须说明如何承接上一章结尾；发生时间、地点或行动跳跃时，正文必须呈现必要的抵达、安置或时间流逝过程，不能直接从上一章行动跳到次日新地点。

小说级 `Style Profile` 只从 Current Bible Version 构建，由 Bible 的 tone、pov、tense、主文风 Preset、最多两种辅助文风、语言时代感、故事节奏及六项可选参数组成。Prompt Config 只负责将稳定 code 展开为指令，不能成为第二个小说级来源。Chapter Planner 使用小说设置确定 `target_words`；Scene Writer 共享章节总字数预算，按其他场景实际字数和剩余场景数动态计算当前参考字数；Assembler 继续遵守同一总字数与 Style Profile。题材、故事基调和人物属性不得混入文风名称。

字数控制使用统一的多字节字符计数，并排除所有 Unicode 空白和换行。非末尾 Scene 可以按叙事需要短于平均值，未使用的字数预算由后续 Scene 承接；每个 Scene 同时受动态硬上限约束，且在计算当前上限时，必须按章节下限和 Scene 总数为每个尚未生成的后续 Scene 保留最低字数空间，不能让前置 Scene 用完章节硬上限后再把最后一个完整剧情任务压缩成极短文本。最后一个待生成 Scene 负责将场景总量补足至章节下限。Scene 和 Assembler 输出超出当前上下限时最多进行一次定向扩写或压缩；Rewrite 可进行最多两次，解决首次修复后仍轻微欠长或超长的问题。Scene 长度修复必须把原草稿的 `foreshadowing_coverage` 身份列表视为冻结模板；模型只能重新判断状态和逐字证据。Laravel 丢弃其他 Scene 的额外动作，并将漏项、重复或错写身份的模板项保守降级为 `missing`，使叙事问题进入 Rewrite/Review，而不是以 Coverage 结构错误终止 Run。整章 Rewrite 的长度修复不得再次返回整章替换稿，而应返回逐字唯一命中的 `search` / `replacement` 局部补丁；Laravel 负责应用补丁和重新计数，并拒绝让过长稿跨越下限成为过短稿、或让过短稿跨越上限成为过长稿，以避免扩写和压缩在硬范围两侧振荡。修复提示使用目标字数 95%～105% 的窄目标区间提供安全余量，最终硬范围仍为 85%～115%。修复后仍不合规则不得提升为当前 Artifact。最终审校与 Canonical Commit 均由 Laravel 确定性检查该范围，超出范围必须进入 Rewrite，不能因模型评分较高而自动 PASS。人工确需接受超限版本时，必须使用独立的“接受超限版本”动作，保留原字数 Finding、正文实际字数、严格上限和原因，不得把它记录成清空问题的普通 Override。Assembler 和 Rewrite 可以补足既定场景的表现细节，但不得用重复内容凑字或新增重大事实。

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
8 Frozen Foreshadowing Action Contract
9 Required / Forbidden Facts
10 Recent Chapter Summaries
11 Previous Accepted Scene Tail
12 Long-term Memory
13 L4 Style Contract（Current Bible Version）
14 Scene Task
```

Token 不足时先缩减 Long-term Memory、较旧 Summary、Style Example；不得删除 Hard Constraints、Current State、Required/Forbidden Facts、Ending Constraints，也不得删除结构化伏笔动作契约。若强制层本身超过预算，应明确失败，不能静默删去 Critical、Due 或 Overdue 伏笔。

Snapshot Schema v3 必须记录版本、实体 IDs、Fact/Memory IDs、Recent Chapters、Previous Artifact、Prompt/Model、Token Budget，以及 Current Bible Version、Style Contract checksum 和 Foreshadowing Contract checksum。同一 Chapter Pipeline 冻结一个 Bible Version，不在中途静默切换。

`ForeshadowingContextContract` 从冻结的 Bible Version、Canonical State Version 和 Chapter Plan Version 确定性构建完整动作契约。每项包含伏笔定义、promised payoff、Canonical 优先的内容状态、时限、兑现窗口、重要度、所属 Arc、本章动作、目标 Scene、验收条件，以及不晚于冻结 State Version 的最近 Active Story Event 和证据。只有 `foreshadowing_actions` 中的目标具有本章处理权限；历史 `due_foreshadowings` 只记录为 legacy reference，未来未选中伏笔不会进入 actions。

Scene Writer 从 `l0.foreshadowing_contract` 读取契约；Assembler、Event Extractor、Reviewer 和 Rewriter 从各自 Run 的 `foreshadowing_contract` 读取同一结构并保存 checksum。`promised_payoff` 是作者侧约束，仍受 `must_not_reveal` 限制，不能被模型解释为允许提前揭晓。

同一 Context 还必须携带冻结的 `arc_contributions` 和 `world_entity_candidates`。Writer 只能使用这些候选临时键引入重大世界资料；Reviewer 对每个计划 Beat、Arc Completion Condition 和 Entity Candidate 输出逐字证据审计，并单独列出未批准的重大实体。缺少、错序、引用其他小说或 Volume 的 Arc、与现有实体冲突、证据无法逐字命中，都会形成确定性 Finding 并阻止其进入 Canonical Commit。

Review 的 `foreshadowing_audits` 必须与冻结动作契约逐项、同序对应，并同时读取当前 Chapter Draft 的最终 Coverage 与绑定该 Draft 的 Event Candidate。审校结果只有 `fulfilled / rewrite_required / needs_attention`：fulfilled 需要正文逐字 evidence、fulfilled Coverage 和匹配事件共同支持；rewrite_required 表示可在不改变契约的前提下修复正文；needs_attention 表示必须由用户决定延期、放弃或改变兑现承诺。Laravel 将同一伏笔 ID、动作和目标 Scene 的 Coverage 与语义问题合并为一个 Finding，并以确定性 Finding 阻止到期 Critical 在未解决时 PASS。

整章 Rewrite 使用与 Assembly 相同的结构化 `content + scene_coverage + introduced_major_facts` 契约，但允许重写在实际修正文后把原先 missing/contradicted 的 Coverage 重新判为 fulfilled；证据仍须逐字校验，必要时只修复证据引用。整章 Rewrite Artifact 保存新的 `scene_coverage` 和 `plan_findings`，随后推进器因 source artifact 已变化而重新执行 Event Extraction、State Patch 和 Review。Scene 级 Rewrite 仍先回到 Assembly，再执行同一完整下游链。Rewrite 不能写 Story Event、伏笔领域投影或 Canonical State。

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

`self_check` 的四项状态只能是 `fulfilled`、`missing` 或 `contradicted`。`fulfilled` 与 `contradicted` 必须引用当前 Scene 正文中的原句，`missing` 的 evidence 必须为 `null`。Laravel 校验固定结构与原文引用；仅有空白或外层引号差异时，将 evidence 映射回正文中的连续原句，仍无法逐字命中时只修复 Coverage evidence，不重新生成正文，也不得改变原 status。轻量修复响应因输出 Token 用尽而没有正文时，使用提高后的修复预算重试一次；错误信息必须区分输出截断与 Schema 非法。修复后仍不能逐字命中则本次 Scene Run 失败。缺失或反转项写为 Scene Artifact 的稳定 `plan_findings`；这些结果是结构化自报证据，不是不可推翻的语义事实或 Canonical Fact，也不单独决定最终 Review Decision。

`foreshadowing_coverage` 按冻结契约顺序列出分配给当前 Scene 的全部动作，每项固定包含 `foreshadowing_id`、`action`、`status` 和 `evidence`。Laravel 对照 `target_scene_sequence` 校验 ID、动作、顺序与 Scene 归属，并执行同样的逐字证据规则。只有正文具体结果满足 `acceptance_criteria` 时才允许模型声明 fulfilled；主题相近但缺少动作结果时必须声明 missing。证据修复只允许替换 evidence，不得改变 ID、动作、顺序或 status；修复耗尽时，将无法验证证据的声明降为 missing 并生成 `FORESHADOWING_COVERAGE_MISSING` Finding，不伪造正文结果。

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

Assembler 使用结构化响应：`content`、按 Scene 顺序返回的 `scene_coverage`，以及必须为空的 `introduced_major_facts`。每个 coverage 固定检查 goal/conflict/turn/outcome，并包含该 Scene 的 `foreshadowing_coverage`；两类 Coverage 都执行原文 evidence 校验。Scene ID 必须完整、顺序一致、不得重复或跨章引用；伏笔 Coverage 必须与冻结契约中的目标 Scene、伏笔 ID、动作和顺序一致。若 Scene Draft 已把普通计划项或伏笔动作报告为 missing/contradicted，Assembler 不得将其直接提升为 fulfilled。若组装删除了 Scene Draft 中唯一的伏笔完成证据，最终状态必须报告 missing/contradicted 并写入 Chapter Draft Artifact 的 `plan_findings`，供后续自动 Rewrite 使用。跨 Scene 的动作以各 Scene Coverage 保留并在 Chapter Draft 中聚合。

上述校验可以确定响应结构、引用关系和模型是否声明新增重大事实；它不能只凭模型自报确定语义真实性。重大事实是否被隐性新增仍由后续 Event/State Validation 与最终 Review 检查。Evidence Repair 只修正 quote 且不能改变 status；当最终 Coverage 报 missing 时，Review 阶段可以执行一次 `coverage-judgment-repair-v1` 聚焦复核，只允许重判 status/evidence，不得改正文、Plan 或 Canonical State。复核仍不确定或失败时保留原 Finding 和 `ai_request_log_id`；相同输入复用不可变 Context Artifact，不重复付费。

幂等键：

```text
assemble:{chapter_id}:{ordered_scene_checksums}:{prompt_version}
```

技术失败只 Retry Assembly。

## 11. Event Extraction / State Validation

`ExtractStoryEventsJob` 输入 Chapter Draft、Plan、Current State、Locked Facts；输出 `event_candidate`。

伏笔候选事件只能引用本章冻结动作契约授权的目标，事件类型必须与 `plant / reinforce / pay_off / abandon` 动作一致，目标 Scene 的最终 `foreshadowing_coverage` 必须为 fulfilled。事件 evidence 至少有一项必须引用动作目标 Scene，并与 Coverage 的逐字证据互相包含；未选中伏笔、missing/contradicted Coverage、动作不匹配或只有主题相似内容都不能生成事件。`defer` 不产生正文 Story Event，`abandon` 必须带有与冻结 State Version 一致的人工授权。`foreshadowing_due` 不再生成，因为时间推进不是正文事件。

`ForeshadowingEventValidator` 在 Event Candidate 保存前按候选顺序模拟内容生命周期，并在 StateValidator 阶段使用 Event Candidate Run 中冻结的契约再次执行。允许 `idea → planted`、`planted/reinforced → reinforced`、`planted/reinforced → paid_off`，重复 `reinforced → reinforced` 合法；同章 `planted → reinforce` 和 `planted → paid_off` 必须依次输出两个证据充分的事件。新 `idea` 尚未写入 Canonical State 时可以使用领域记录作为铺设起点；其他内容状态必须来自冻结 Canonical State，不能把领域投影当作事件生命周期证据。`paid_off / abandoned` 终态不能由生成事件重新开启。旧 Event Candidate 保持不可变；若包含伏笔事件但缺少匹配的冻结契约 checksum，StateValidator 返回 Hard Finding，不能进入 Canonical Commit。

确定性校验能证明动作授权、Coverage 结论、证据来源和生命周期顺序一致，不能仅凭字符串证明正文语义真正满足 `acceptance_criteria` 或 `promised_payoff`；该语义验收由 Reviewer 执行。

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

普通 warning 按分数和可执行性分流：评分达标且没有其他门禁时允许 PASS，并作为非阻塞建议保留；评分未达标且 warning 可执行时进入 REWRITE。auto-fixable error 进入 REWRITE；真正需要用户选择、没有安全修复路径或 Rewrite 耗尽时进入 NEEDS_ATTENTION。Hard Conflict 必须 BLOCK，模型顶层建议不能覆盖 Laravel 的确定性决策。

Narrative Finding 使用固定 code，并包含 `dimension`、`severity`、`scene_id`、`scope`、`auto_fixable`、`requires_human_decision`、`message`、`evidence`。`scene_id` 非空时必须属于本章，`scope = scene` 时必须提供；模型不能创建 hard finding。低于通过分数却没有可自动修复或需要人工决策的 Finding，属于不一致的 Reviewer 响应，应拒绝持久化。最终 Review Artifact 保存命中的决策规则和 Finding code，模型的 `recommended_decision` 仅作为审校证据保存。

Narrative Review 必须在一次响应中完成七个维度的全量审计，不得发现首个问题后提前结束。`findings` 是问题集合的权威来源；Laravel 根据最终 Findings 确定性派生 `dimension_audits.status = pass|issues_found`，并同时保存模型原始状态和归一化状态。原状态声称有问题但没有 Finding 时，执行一次 `review-schema-repair-v3` 单维结构修复；修复绑定相同 Draft、State Version、Bible Version 与 Reviewer Prompt 来源链，不重新运行完整 Review，也不消耗正文 Rewrite 配额。修复失败生成带 `ai_request_log_id` 的 NEEDS_ATTENTION，不把 Chapter 标为 blocked。同一根因合并为一个 Finding，一轮内返回当前正文全部有明确证据的问题。

Rewrite 后的 Review 同时接收当前 Chapter Plan 下上一轮全部可修复 Findings 作为回归清单，为每项保存 `resolved / still_present / replaced` 结果，并继续执行七维全量检查；State、Plan Coverage 与字数问题仍由 Laravel 重新确定性检查。

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

自动 Rewrite 次数只统计当前成功 Chapter Plan 之后成功创建、且不含 `manual_edit = true` 的自动正文 `rewrite_draft`；Reviewer、Rewriter 与章节工作台共用同一统计口径。人工修改、长度 Repair、Coverage Repair、Review Schema Repair、Provider 失败与 Schema 失败都不消耗正文 Rewrite 次数。Artifact 展示版本仍按全部不可变重写稿连续递增。自动派发读取 Review Artifact 中冻结的 `rewrite_scope`，按其选择 Scene 或 Chapter Rewrite。

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

该 Job 由用户在 PASS 后确认“提交正式章节”，或由 `auto_commit=true` 的 Review PASS 安全分支派发。两条路径都必须验证当前 Review/Draft 来源、Pause、Expected State Version 和幂等键，并调用同一 `CanonicalCommitService`；Resume 本身不得绕过这些条件直接提交。

Canonical Commit 的数据库事务固定已验收 Character / World Entity Candidate、Story Events、Facts、State Version、Chapter、Novel 指针和 Story Arc 进度。Candidate 使用 `(novel_id, source_chapter_id, source_candidate_key)` 幂等创建，临时键在写 Event、State 和后续 Memory 前解析为正式 ID。`story_arc_beat_completed` 必须对应冻结 Primary Beat，并由 Reviewer 对全部验收条件给出 `fulfilled` 和正文逐字证据；Draft、Review 或 Rewrite 不能直接完成 Beat。Story Arc Progress 只根据 Active Completion Events 中的唯一 Beat 重算。事务成功后派发唯一键为 `novel:{novel_id}:state:{state_version_id}` 的 `RefreshNovelProjectionJob`；其他领域投影刷新不进入 Commit 事务，失败由 Queue/Horizon 按独立 Job 重试，因此不能回滚已经成功的正式章节。Job 只处理仍为当前 Canonical 指针的 State Version，过期任务直接结束，避免旧投影覆盖新状态。

投影重建以最新的无章节 State Version 为基线，按 `state_version, id` 重放不晚于目标版本的 Active 伏笔事件，并复核结果与目标 Canonical State 的 `status` 和 `reinforce_count` 一致。`setup_chapter_id` 只取有效 `foreshadowing_planted` 事件，`payoff_chapter_id` 只取有效 `foreshadowing_paid_off` 事件；不存在相应有效事件时字段必须为 `null`。它计算目标值后整体覆盖漂移字段，不在表当前值上执行 `+1`。

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
Validate planning acceptance and verbatim evidence
Create approved World Entities and resolve temporary keys
Persist Story Events
Apply Fact Changes
Create State Version N+1
Update Chapter
Update Novel pointers
Recalculate Story Arc progress
COMMIT
```

相同 Commit 重复执行返回已有结果；不同 Artifact 覆盖已 Canonical Chapter必须 BLOCK。Commit 阶段禁止外部 LLM 调用。

Latest Canonical Chapter Rollback 在同一事务中失效该章事件和记忆、恢复 State 指针、删除只由该章首次引入且没有后续 Active Canonical Event 引用的 Entity，并从剩余 Canonical Arc Events 重算进度。存在后续正式引用时必须阻止简单删除。历史 Plan 没有结构化 Beat 引用时保持可读，但不得据此猜测完成度；`story:rebuild-arc-progress` 默认只输出 dry-run，只有显式 `--execute` 才写入重算结果。

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

`GenerateCanonicalChapterSummaryJob` 调用 `CanonicalChapterSummaryService` 更新 `chapters.summary`；幂等键：

```text
summary:{canonical_artifact_checksum}:{summary_prompt_version}
```

Canonical Commit 后按以下 Queue Chain 执行：

```text
UpdateMemoryJob
→ GenerateCanonicalChapterSummaryJob
→ RefreshNovelProjectionJob
→ ContinueAutoGenerationJob
```

Memory、摘要或 Projection 失败不回滚正文，但阻止本次自动续写并在 Generation 恢复中心留下恢复点。Embedding 独立重试，不阻塞下一章。Summary Job 必须核对任务携带的 Canonical Artifact ID；来源已经变化时安全结束，不覆盖新摘要。

## 17. Auto Generate

Auto Generate 不能预先 Queue 100 章。

Auto Generate 自动推进当前章到 Review PASS。`auto_commit=false` 时等待用户提交；`auto_commit=true` 时安全派发 Canonical Commit。Chapter N 提交成功并完成 Post-Commit 后，才启动 Chapter N+1。

Filament 的“生成下一章”会创建或恢复当前目标章，并立即调用 `AdvanceChapterPipelineAction`；“开始自动生成”完成相同的创建或恢复与推进，确认没有前置或断点错误后再开启 `auto_generate`。`auto_generate` 表示 Canonical Commit 成功后允许续接下一章；`auto_commit` 单独控制 PASS 后是否自动发起该 Commit，默认关闭并在小说设置中显式展示。

```text
Chapter N Commit
→ Post-Commit
→ Check Stop Conditions
→ GenerateNextChapterAction
→ Chapter N+1
```

手动“生成下一章”同样要求上一正式章节摘要已就绪；缺失时返回 `previous_chapter_summary_missing` 并引导到摘要恢复操作。

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
Review PASS、Commit 未完成、auto_commit=false → 停止并等待用户手动 Commit
Review PASS、Commit 未完成、auto_commit=true → 校验来源链与 Pause 后派发 Commit
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
Provider request timeout <= 300s
AI Job timeout = 330s
Horizon worker timeout = 360s
Redis retry_after = 420s
Generation Run stalled threshold = 480s
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
chapter-planner-v10+natural-prose-v1
scene-writer-v15+natural-prose-v1
assembler-v12+natural-prose-v1
event-extractor-v6
reviewer-v14+natural-prose-v1
rewrite-v13+natural-prose-v1
review-schema-repair-v3
arc-completion-repair-v2
coverage-judgment-repair-v1
rewrite-length-patch-v2
summary-v2+natural-prose-v1
```

`NarrativeProsePolicy::VERSION = natural-prose-v1` 是规划、正文和审校共用的自然表达契约。Novel Planner 与 Chapter Planner 要把抽象主题落成可验证的人物行动、阻力和后果；Scene Writer、Assembler、Rewrite 及其长度修复要通过动作、对白、POV 感知和具体后果呈现信息，抑制解释性套句、机械同构、空泛升华、设定复述和人人同声；Reviewer 及单维审计修复只在这些特征反复出现或实质损害叙事时生成 `STYLE_MISMATCH`；Canonical Summary 只记录事件、状态变化和未决后果。

`PromptVersionResolver` 为 Planner、Writer、Assembler、Reviewer、Rewrite 和 Summary 返回 `{stage_prompt_version}+{narrative_policy_version}`。该有效版本同时进入 Run、Context Snapshot、Input Hash、Idempotency Key 和 Provider 请求诊断；修改任一组成部分都会形成新的复用边界。Extractor 等不注入自然文风策略的结构化阶段保持独立版本。`AiDebugService` 原样执行用户输入，不注入该策略，因此使用基础阶段版本。

当前 21 个 `AiRequest` 调用点均已核查。Story Event 提取、Coverage/Event 逐字证据校对、Scene 辅助 JSON 修复、Arc 完成审计、Coverage 判定复核只承担结构化判断或原文引用，因此保持精确任务 Prompt，不附加正文写作规则。`AiDebugService` 原样执行用户输入，便于诊断 Provider，不注入小说文风。该边界避免“去 AI 味”规则改变证据文本、事件事实或修复结构。

文本模型按 Stage 从 Novel Settings / `ai_model_routes` / config 解析，不在 Job 中写死。解析优先级固定为：小说级非空 Stage Override → 数据库模型路由 → 旧 `system_settings.ai` Stage 配置兼容值 → 环境默认配置。`ai_model_routes` 同时保存各 Stage 的可选 `reasoning_effort`，允许值为 `low`、`medium`、`high`；留空表示采用 Provider 默认行为。小说级 Provider/Model 覆盖仍继承同一 Stage 路由的推理程度。Embedding 同样优先读取数据库模型路由，但当前只允许 OpenAI Provider，且不使用推理程度。每个新 Run 在创建时冻结 `provider`、`model_policy` 与推理程度；Provider、Model 或推理程度都参与 `input_hash`，避免错误复用采用不同推理策略生成的旧 Artifact。后台设置变更只影响之后创建的请求和 Run，历史 Run 不改写。

文本生成固定注册 `openai` 与 `deepseek` 两个 Provider，由 Laravel Router 按已冻结 Provider 精确分发，不做动态选型、跨 Provider Fallback 或价格路由。Base URL、API Key 和 Timeout 优先读取 `ai_provider_connections` 中对应的启用连接，API Key 使用 Eloquent `encrypted` cast，后台不回显；连接不存在时才兼容回退环境配置。成本按实际 Provider 和响应 Model 从 `ai_model_prices` 读取启用价格，按 `billing_unit` 计算并保存到 Usage；没有匹配价格时才回退旧全局环境单价。DeepSeek 结构化任务使用 JSON Output，Laravel 在创建 Artifact 前检查空内容、JSON 合法性和响应 Schema。Embedding 固定使用 OpenAI 配置，不随文本 Stage 切换。

Scene Generation 是一个拥有多个实际 Provider 调用的复合 Run。Run 顶层 `provider` / `model_policy` 表示正文 Writer 路由；正文、结构与 Coverage 修复、字数修复的实际路由分别冻结在 `context_snapshot.generation_preferences.substage_routes`。每个请求必须携带明确的 `route_key`，Router 按该键校验冻结的 Provider 与 Model；不得把修复请求错误地与 Run 顶层 Writer Provider 比较，也不得在重试时静默采用最新全局路由。`usage_records.request_metadata` 同步记录 `route_key`，用于区分同一 Run 内的实际调用和费用。

代码和 `.env.example` 当前将 `gpt-5.6-luna` 作为文本生成默认模型，将 `text-embedding-3-small` 作为 Embedding 默认模型。部署者必须按实际账户和端点核实模型可用性。历史实际调用以 `generation_runs.provider`、`generation_runs.model_policy`、`usage_records.provider` 和 `usage_records.model` 为准；Migration 前的 Run 允许 `provider = null`，界面明确显示为旧记录未保存 Provider。

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

正常操作从小说概览启动“生成下一章”，Laravel 自动推进 Plan、顺序 Scene、Assembly、Event Candidate、State Patch、Review 和必要的 Rewrite。`auto_commit=false` 时到 Review PASS 后停止，操作人员确认当前草稿、事件和状态变化后在章节工作台点击“提交正式章节”；`auto_commit=true` 时由系统安全派发同一提交作业。PASS 本身不会写入正式 Story State、Story Event 或 Memory，只有 Canonical Commit 事务成功才会写入。

常见停止原因按以下方式处理：

| 停止状态或错误 | 已确认行为 | 操作入口 |
|---|---|---|
| `current_bible_incomplete` | 生成前置检查拒绝启动，不读取旧 `settings.editorial` 兜底 | 打开该小说的“小说圣经”，创建新的完整 Bible Version；明确填写 tone、POV、tense 和全部 Style Profile 后重新启动 |
| 已启动章节必须立即采用新 Bible | 旧来源链保持不可变，不能把旧 Bible 的 Rewrite/Review 提交到新 Bible | 先执行 `novel:recover-bible-chapter` dry-run；审核完整差异、来源链、State 基线和 plan hash 后，使用相同 Expected Bible/State 与 hash 显式 `--execute`。系统创建新 Bible Version，并从 Chapter Planning 重新推进 |
| Stage/Provider 失败 | 成功的 Run/Artifact 保留；不得靠重放全部流水线覆盖历史产物 | 在“Generation → 恢复中心”查看错误和可重试性；可重试失败使用“重试”，暂停小说使用“恢复”。章节工作台的分阶段按钮仅用于定位后的调试或恢复 |
| Rewrite 耗尽 | 最后一次复审转为 `NEEDS_ATTENTION`，不再自动派发 Rewrite | 在章节工作台“审校”中“人工修改正文”并自动重走 Event/Patch/Review；无 Hard Conflict 且符合 Override 条件时可填写原因“人工通过（Override）” |
| Review PASS | `auto_commit=false` 时等待提交；开启时仅在未暂停且来源链有效时派发 Canonical Commit | 关闭时在章节工作台点击“提交正式章节”；开启时查看 Commit 结果；小说暂停时不会自动提交 |
| NEEDS_ATTENTION / BLOCK | 自动推进停止；Hard Finding 不允许普通 Override | 按 Findings 修订正文或修复状态前置条件，再重新审校；历史 Review 和 Artifact 保留 |

“生成下一章”的前置检查会先对当前小说执行一次原子化停滞清理：只把超过 `stalled_run_after_seconds` 的 `running` Run 标记为 `failed / worker_lost`，再判断是否仍有 `queued / running` Run 或其他活跃章节；近期 Run 与仍在排队的任务不得误清理。该清理独立提交，即使后续因另一个真实活跃工作流而拒绝生成，过期 Run 也不会回滚成 `running`。`php artisan generation:mark-stalled` 继续承担全局定时清理，之后仍应通过“Generation → 恢复中心”按持久化状态恢复。`php artisan story:rebuild-state NOVEL_ID --dry-run` 和 `php artisan memory:rebuild NOVEL_ID` 分别用于正式状态校验和 Canonical Memory 重建，不用于绕过 Review 或提交草稿。

Bible 变更恢复命令默认只读：

```bash
php artisan novel:recover-bible-chapter NOVEL_ID CHAPTER_SEQUENCE \
  --expected-bible=BIBLE_VERSION \
  --expected-state=STATE_VERSION \
  --target-pov=第一人称
```

输出中的 `plan_hash` 同时覆盖章节状态、Plan 状态、Scene 当前 Artifact 指针、Run 和 Artifact checksum。执行阶段还要求现有 User 的 `--actor`，并在事务锁内重新计算报告；任一来源发生漂移都会拒绝旧 hash。执行只允许当前 Canonical 章的下一章，保留所有旧 Run、Artifact 和 Usage，重置 Draft 指针后派发新的 Chapter Planning。该命令不能绕过 Review 或 Canonical Commit。

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
PASS 后按小说级 auto_commit 策略自动派发或等待用户确认
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
