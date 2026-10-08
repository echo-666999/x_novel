# AI 长篇网络小说自动生成平台 PRD v1.2（单人精简版）

> 面向单人开发、单人运营的百万字级网络小说自动生成系统。  
> 核心目标不是建设复杂 SaaS 平台，而是构建一个稳定、可控、可恢复、可完结的 AI 长篇小说生产工具。

---

## 0. 文档信息

| 字段 | 内容 |
|---|---|
| 文档版本 | v1.2 Solo Edition |
| 基线来源 | PRD v1.1 + NGC-001 Source of Truth 对齐 |
| 产品形态 | 单用户、单租户、单体应用 |
| 开发/运营方式 | 单人开发、单人使用 |
| 技术栈 | Laravel + Filament + PostgreSQL/pgvector + Redis + Laravel Queue |
| 核心目标 | 小而精、低运维成本、长期可维护 |
| 非目标 | SaaS、多租户、多人协作、复杂审批、微服务化 |
| 状态 | 已完成 NGC-001～NGC-012 产品基线与 OGR-001～OGR-007 Outline 可靠性收尾；Outline 已具备 queued 主批次、Foundation → Structure → 逐 Arc Beats → 确定性 Skeleton Assembly → 逐 Main Beat Detail → Finalize、PostgreSQL 进度投影、Filament 失败反馈与领域 Resume。遗留 v2 未完成批次已显式退役，新批次使用 v4 逐任务冻结路由合同，历史 v3 批次保留原执行语义 |

---

# 1. 一句话定义

以 **结构化 Story State 为事实源**，以 **pgvector 长期记忆为检索补充**，通过 **规划 → 上下文构建 → 生成 → Review → Canonical Commit → 状态/记忆更新** 的可恢复流水线，持续生成百万字级长篇网络小说。

系统首先服务于一个人：

- 一个人创建和维护小说；
- 一个人启动、暂停、重写和审核；
- 一个人维护部署环境；
- 不为尚未存在的团队协作需求提前增加复杂度。

---

# 2. 核心设计原则

## 2.1 Simplicity First

任何设计首先选择满足需求的最简单方案。

优先级：

```text
Laravel 内解决
    ↓
PostgreSQL 内解决
    ↓
Redis 辅助
    ↓
最后才考虑新增基础设施
```

禁止仅为了未来假设引入：

- 微服务；
- Kafka / RabbitMQ；
- 独立 Workflow Engine；
- Elasticsearch；
- Neo4j；
- 独立 Vector Database；
- Kubernetes；
- 多租户；
- 复杂 RBAC；
- 多级审批流；
- 多人实时协作。

---

## 2.2 PostgreSQL 是唯一权威事实库

以下内容必须以 PostgreSQL 为最终事实源：

- 小说结构；
- Canonical Chapter；
- Story Event；
- Story State；
- 人物；
- 世界设定；
- 伏笔；
- Generation Run；
- Review；
- Memory 元数据；
- 使用量与成本。

Redis 只能用于：

- Queue；
- Cache；
- Lock；
- Rate Limit；
- 临时进度。

Redis 不得成为唯一事实源。

---

## 2.3 Draft 与 Canonical 严格分离

任何 Draft：

- 不得更新 Story State；
- 不得更新人物正式状态；
- 不得改变正式伏笔进度；
- 不得成为后续章节的事实依据。

只有经过 Review Gate 的内容，才能通过 Canonical Commit 更新正式状态。

---

## 2.4 pgvector 不是事实数据库

pgvector 负责：

- 找回早期情节；
- 找回相关人物历史；
- 找回相关地点/物品事件；
- 找回伏笔；
- 找回风格样例。

但事实判断必须优先读取：

1. Bible / Locked Facts；
2. 当前 Story State；
3. Canonical Story Events；
4. 结构化人物/世界/伏笔数据。

---

## 2.5 复杂度只投入到真正重要的地方

允许复杂：

1. Story State；
2. Context Builder；
3. Review / Continuity；
4. Canonical Commit；
5. Failure Recovery。

其他模块默认保持简单。

---

# 3. 产品目标

## G1 长篇连续生成

支持：

```text
Novel Bible
  ↓
Current Novel Outline（Volume → Arc → Beat → Milestone）
  ↓
Volume
  ↓
Story Arc
  ↓
Current Main Beat
  ↓
Current Milestone
  ↓
Chapter Plan
  ↓
Scene
  ↓
Chapter
```

最终目标：

- ≥100 万中文字；
- 数百到数千章；
- 支持单章一次启动自动运行到 Review PASS；小说级 `auto_commit` 默认关闭。关闭时由用户确认 Canonical Commit，开启时由 Laravel 在 PASS 后安全派发同一提交服务。

人工确认的 Current Novel Outline 是 Chapter Planning 的上游约束。AI 可以生成候选规划、当前 Beat 的 Milestone/Handoff 候选、Scene 表达和章节建议，但不能拥有节点排序、主线切换、跳过节点、删除节点或宣告完成的决定权。Laravel 必须选择顺序最早的未完成 Main Beat 及其最早未完成 Milestone，并把精确 Outline Version、Primary Arc/Beat/Milestone 和 Handoff 冻结到 Chapter Plan。

---

## G2 连续性控制

系统必须降低：

- 人物能力冲突；
- 人物位置冲突；
- 人物知识边界冲突；
- 死亡角色无解释复活；
- 世界规则冲突；
- 时间线冲突；
- 物品归属冲突；
- 伏笔遗忘；
- 主线漂移。

---

## G3 自动化程度

正常章节目标流程：

```text
自动规划
→ 自动生成
→ 自动 Review
→ 自动重写
→ Review PASS
→ 按小说级 auto_commit 选择自动提交或用户确认
→ Canonical Commit
```

`auto_commit` 是小说级运行策略，默认关闭。恢复该设置前遗留的同名键不自动生效；只有设置页显式保存并写入 `auto_commit_configured=true` 后，运行时才读取 `auto_commit`。PASS 只表示章节通过审校；在 Canonical Commit 事务成功前仍不是 Canonical Chapter，也不得更新 Story State、Story Events 或正式 Memory。关闭时流水线停在 PASS 等待用户确认；开启时 Laravel 只能把真实 PASS 的当前 Draft 派发给现有 `CanonicalCommitService`，并继续执行 Pause、State Version、Artifact 来源、事务和幂等门禁。

除等待提交或安全自动提交外，只有以下异常才提前进入人工处理：

- 硬事实冲突；
- 连续多次 Rewrite 失败；
- Ending 路径发生重大变化；
- 成本达到硬限制；
- 模型输出无法解析；
- 用户主动暂停。

---

## G4 可恢复

任何阶段失败后：

- 已成功步骤不得无意义重复；
- 不得产生重复正式章节；
- 不得重复更新状态；
- 能从最近 Artifact / Run 恢复。

恢复必须以 PostgreSQL 中的 Run、Artifact 和章节状态为依据。Current Bible 的叙事基线或完整 Style Profile 缺失时，生成前置检查必须以 `current_bible_incomplete` 停止；用户在“小说圣经”创建新的完整版本后才能重试，不得回退到旧 Editorial。Rewrite 达到上限后必须停在 NEEDS_ATTENTION，由用户人工修改后重新审校，或在没有 Hard Conflict 且满足 Override 条件时明确填写原因后人工通过。

章节阶段产物成功后，如果选择或派发下一阶段发生异常，当前 `Generation Run` 必须继续保持 `succeeded`，并在独立的 `progression_failure` 中持久化错误码、原始原因、分类、可恢复性、推荐动作、发生时间和恢复时间。章节工作台与 Generation 页面必须显示“阶段成功 · 推进异常”，不得只显示成功 Run，也不得把已经验证的 Artifact 反向标记为失败。恢复时复用该成功 Run / Artifact，推进成功后标记异常已恢复，不能再次请求同一阶段 Provider。

全书 Outline 生成必须具备持久化的可见进度和领域恢复入口。用户点击“AI 生成候选”时，Web 请求必须先在 PostgreSQL 创建或复用 `queued` 主批次，再在事务提交后投递协调 Job；Worker 激活批次时改为 `running`。任一子阶段最终失败必须把主批次收口为 `failed` 并保存可展示的错误码、用户文案、技术信息和可恢复性；Finalize 成功后才可变为 `succeeded`。页面不能依赖一次性 Toast、Redis、Horizon 或 `failed_jobs` 判断业务进度。

全书大纲页面在批次为 `queued` 或 `running` 时轮询 PostgreSQL 中的 Run 与 Artifact 投影，并展示当前阶段、尝试次数和已知分母下的 `x/y` 进度；终态停止轮询。Structure 完成前不得伪造整体百分比，完成后可显示 Arc Beats 进度，Skeleton Assembly 完成后可显示 Main Beat Detail 进度。失败原因必须持久显示；仅临时 Provider 或基础设施故障提供“继续 AI 生成”，由领域 Resume Action 校验暂停、版本、活动 Run、输入指纹和 Artifact 来源链后，从最早缺失的有效 Artifact 继续，并保持原批次冻结路由和预算。Provider 配置、冻结路由、推理预算耗尽、可见输出截断或完成预算证据不足等必须修改配置或预算的失败不得 Resume；页面提供“重新生成候选”作为 Restart 入口，使用当前配置创建新的 v4 主批次，同时保留旧批次、子 Run、Artifact、Usage 和冻结快照，不得静默改写。禁止把 `queue:retry` 作为产品恢复入口。

若已启动章节必须立即采用新的 Bible 内容，恢复操作必须先 dry-run 并冻结 Expected Bible Version、Expected State Version、章节/Plan/Scene 来源链和 Artifact checksum；用户审核同一 plan hash 后才能显式执行。执行时创建新的不可变 Bible Version，保留旧 Run、Artifact、原始响应和 Usage 审计，并从最早受 Bible 变化影响的阶段重新生成。旧 Bible 的 Draft、Review 或 Rewrite Artifact 不得进入新来源链的 Canonical Commit。

---

## G5 可完结

系统不仅负责“继续写”，还必须负责“结束”。

必须支持：

- Volume Goal；
- Story Arc；
- Foreshadowing Due；
- Closing Horizon；
- Closure Debt；
- Ending Contract；
- Ending Audit。

---

# 4. 非目标

MVP 明确不实现：

- 多租户；
- 多 Workspace；
- 团队管理；
- 作者/编辑/审核员角色体系；
- 多级审批；
- 实时协作；
- Git 式小说分支合并；
- 自动外部平台发布；
- 图片/视频/音频生成；
- 读者社区；
- SaaS 计费；
- Kubernetes；
- 微服务；
- Neo4j；
- Elasticsearch。

---

# 5. 用户模型

系统只有一个实际用户：

```text
Owner / Admin
```

拥有全部权限：

- 小说创建；
- Bible 编辑；
- 人物/世界编辑；
- 启停生成；
- Review；
- 重写；
- 锁定事实；
- 修改计划；
- 回滚最新章节；
- 修改模型；
- 调整预算；
- 查看日志和成本。

如果项目已经存在 Filament Shield：

- 可以保留；
- 默认只使用 `super_admin`；
- 不设计复杂角色模型。

---

# 6. 核心业务流程

权威流水线：

```text
Novel Bible
    ↓
Current Novel Outline
    ↓
Laravel 选择当前最早未完成的 Main Beat
    ↓
Laravel 选择该 Beat 最早未完成的 Milestone
    ↓
Chapter Plan + Plan Admission Gate
    ↓
Chapter Plan
    ↓
Context Builder
    ↓
Scene Generation
    ↓
Laravel Deterministic Assembly
    ↓
Coverage Judgment（存在计划 Coverage Finding 时）
    ↓
Event Extraction + State Validation
    ↓
Compact Narrative Review
    ↓
Paragraph / Scene Rewrite（必要时）
    ↓
Review PASS
    ↓
auto_commit 开启时自动派发；否则用户确认提交
    ↓
Canonical Commit
    ↓
Story State Update
    ↓
Milestone / Beat Progress Update
    ↓
Memory / Summary / Projection
```

---

# 7. Chapter 生命周期

```text
planned
   ↓
generating
   ↓
review
   ├── rewrite ──→ review
   ├── blocked
   └── Review PASS ──→ 自动或用户确认 Canonical Commit ──→ canonical
```

`PASS` 是 Review Decision，不新增 Chapter 状态；Commit 前 Chapter 仍不是 Canonical。

状态：

| 状态 | 含义 |
|---|---|
| planned | 已生成 Chapter Plan |
| generating | 正在生成 Scene / Chapter |
| review | 等待自动 Review |
| rewrite | 自动修复中 |
| blocked | 需要人工处理 |
| canonical | 正式章节 |
| void | 废弃的预留章节 |

---

# 8. Novel 生命周期

```text
draft
 ↓
planning
 ↓
generating
 ↕
paused
 ↓
completing
 ↓
completed
```

附加：

```text
failed
archived
```

---

# 9. 单人精简版领域模型

目标：

> NGC-001 目标基线为 23 张核心业务表：原 19 张基线增加 4 张版本化 Outline 子表，不继续拆分 Handoff 表。

---

## 9.1 Novel

### novels

核心字段：

```text
id
title
genre
premise
target_words
status
current_volume_id
current_outline_id
current_chapter_sequence
canonical_state_version_id
ending_mode
settings JSONB
created_at
updated_at
```

`settings` 只保存运行策略和技术设置，例如章节目标字数、预算、模型覆盖和自动生成开关。叙事与文风不再以 `settings` 为权威来源。

```text
generation.chapter_target_words
budget / model / automation settings
```

迁移窗口内可以只读访问历史 `settings.editorial`，仅用于迁移预览、冲突对照和数据复制；章节生成不得以其作为 Style Profile 回退来源。

---

### novel_bibles

```text
id
novel_id
version
logline
themes JSONB
tone
pov
tense
taboos JSONB
hard_constraints JSONB
ending_contract JSONB
style_profile JSONB nullable
status
created_at
updated_at
```

唯一约束：

```text
unique(novel_id, version)
```

Current Bible Version 是叙事与文风的唯一权威来源。它统一承载 `tone`、`pov`、`tense`、子题材、目标平台、主文风、最多两种辅助文风、语言时代感、节奏和六项高级文风参数。主文风使用有限 Preset，辅助文风与 1～5 级参数用于微调；生成前展开为明确的 Style Contract。

没有 Current Bible 时，初始 Blueprint 和首个手工 Bible 使用 `NARRATIVE_DEFAULT_TARGET_PLATFORM`，默认 `fanqie`。已有 Current Bible 的目标平台优先，环境配置不得覆盖已保存版本。Outline 规划批次必须在首次 Provider 请求前验证并冻结平台；无效配置直接阻断且不得静默回退，Foundation Schema 只接受该批次冻结的平台。

`style_profile` 使用可空 JSONB：新创建的 Bible Version 必须保存通过结构校验的完整对象，历史版本允许为 `null`，不得通过默认值伪造旧文风。现有小说必须创建新 Bible Version 完成迁移。

---

# 9.2 Story Structure

### novel_outlines

`novel_outlines` 只保存不可变 Outline Version 头和全局约束，不保存完整树形 `content`。完整规划定义由四张版本化关系表唯一表达；Canonical Story State 与 Active Story Events 仍是已经发生之故事事实和完成进度的权威来源。

```text
id
novel_id
version
status draft | current | superseded
source ai | manual | revision
schema_version
title
summary
must_include JSONB
must_not_include JSONB
checksum char(64)
source_artifact_id nullable
based_on_outline_id nullable
created_by nullable
applied_at nullable
created_at
updated_at
```

约束：

```text
unique(novel_id, version)
version > 0
schema_version > 0
每个 novel 最多一个 status=current
```

`novels.current_outline_id` 指向当前采用版本。更换 Current Outline 必须在锁定 Novel 的事务中完成，并保留旧版本为 `superseded`。AI 生成结果只能先成为不可变 Artifact 或 Draft Outline；用户确认采用前不得写入运行态 Volume、Story Arc、Character、World Entity 或 Canonical Story State。

开始正文生成前，系统必须通过同一份只读准备度检查确认：Current Outline、Current Bible、主角、世界设定、Active Volume、Active Story Arc、Initial State，以及上一正式章需要的派生摘要均已就绪。Filament 在用户操作前直接显示各项状态和修复提示；`StartNovelGenerationAction` 在锁定 Novel 的事务中再次执行同一检查，页面状态不能替代领域校验。AI Outline 正常采用时自动初始化故事状态；手工规划仍保留显式初始化恢复入口。

### novel_outline_volumes

```text
id
novel_outline_id
volume_key
sequence
title
goal
climax
target_words
```

### novel_outline_arcs

```text
id
novel_outline_id
novel_outline_volume_id
arc_key
sequence
mainline_sequence nullable
type
title
goal
stakes
completion_conditions JSONB
```

### novel_outline_beats

```text
id
novel_outline_id
novel_outline_arc_id
beat_key
sequence
mainline_sequence nullable
title
summary
chapter_budget_min
chapter_budget_max nullable
acceptance_criteria JSONB
must_include JSONB
must_not_include JSONB
character_candidates JSONB
world_entity_candidates JSONB
handoff_next_beat_id nullable
handoff_transition_mode nullable
handoff_exit_result nullable
handoff_next_trigger nullable
handoff_carried_states JSONB
handoff_open_threads JSONB
handoff_required_transition JSONB
handoff_forbidden_jump JSONB
```

### novel_outline_milestones

```text
id
novel_outline_id
novel_outline_beat_id
milestone_key
sequence
title
objective
acceptance_criteria JSONB
must_include JSONB
must_not_include JSONB
```

正式规划层级固定为：

```text
Volume → Arc → Beat → Milestone → Chapter → Scene
```

四层定义必须通过非空外键和组合外键归属于同一 Outline Version。Volume、Arc、Beat、Milestone 的稳定 Key 在各自类型内对整个 Outline Version 唯一，同级 Sequence 在父节点作用域内唯一；每个 Main Beat 至少一个 Milestone；除最后一个 Main Beat 外必须有且只有一个指向相邻下一 Main Beat 的 Handoff。Structure Provider 只返回 Volume / Arc 稳定 Key；相互隔离的 Arc Beats 与 Beat Detail Provider 不得生成全书级 Beat、Candidate、Milestone Key 或 Handoff 目标 Key。Laravel 必须在确定性 Skeleton Assembly 和 Beat Detail 校验中按冻结结构与响应数组顺序分配这些 Key，并在 `FinalizeNovelOutlineJob` 中解析数据库 ID；无法唯一解析时整批失败。

首版 Milestone/Handoff 只控制按 `mainline_sequence` 排列的 Main Beat。Subplot 继续通过 Arc Completion Conditions 和 Chapter Plan Secondary Contribution 推进，不建立第二套独立进度游标。

`chapter_budget_min` 是规模提示和偏差观测，不能阻止剧情自然完成；`chapter_budget_max` 是异常停留保护，达到后进入 `NEEDS_ATTENTION`。Milestone/Beat 是否完成只由 Canonical 正文证据、验收条件和 Handoff 决定，不能由章节数量或模型声明决定。

`story_events` 保存正式完成进度，Outline 定义表不保存 mutable completion 状态。新数据不支持 `baseline_completions`；本轮不迁移、不 Backfill 旧 Outline，实施和验收从 `migrate:fresh` 后的空数据库开始。

新建 Outline 必须使用可恢复的有界流程：

```text
Foundation（Bible / 初始人物 / 世界 / 伏笔）
→ Structure（一次生成 Volume / Arc，不生成 Beat）
→ Arc Beats × Arc（一次只生成一个 Arc 的 Beat，严格串行）
→ Skeleton Assembly（Laravel 确定性合并 Structure 与全部 Arc Beats）
→ Beat Detail × Main Beat（一次只生成一个 Main Beat 的 Milestones / Handoff）
→ Finalize（Laravel 确定性合并、校验并事务写入）
```

禁止一次 Provider 请求同时生成 Bible、全书 Volume/Arc/Beat、全部 Milestone 和全部 Handoff。Foundation、Structure、每个 Arc Beats 和每个 Beat Detail 都保存独立 Run、不可变 Artifact、输入指纹和恢复点；每个 Provider Job 最多一次模型请求。Skeleton Assembly 与 Finalize 均由 Laravel 确定性执行，不调用 Provider。任何 Arc 失败只恢复该 Arc，已经成功且来源链匹配的 Artifact 不重复计费。

### volumes

```text
id
novel_id
source_outline_volume_id
sequence
title
goal
climax
target_words
status
summary
created_at
updated_at
```

唯一：

```text
unique(novel_id, sequence)
unique(source_outline_volume_id)
```

---

### story_arcs

```text
id
novel_id
volume_id nullable
source_outline_arc_id
sequence
type
title
goal
stakes
completion_conditions JSONB
progress
status
created_at
updated_at
```

`story_arcs.progress` 只由 Canonical Chapter 中经过验证的 Milestone/Beat Completion Events 投影计算。Chapter Plan、Scene Draft、Review 或 Rewrite 只能保存候选贡献，不得直接推进 Arc；最新 Canonical Chapter 回滚时必须用剩余 Active Canonical Events 重算进度。

Story Arc 的 Outline 投影约束为 `unique(source_outline_arc_id)` 与 `unique(volume_id, sequence)`。`story_arcs` 不再保存 `beats`；章节规划只查询 `novel_outline_*` 关系表，运行态表不能成为第二份 Outline Source of Truth。

新出现的 Character / World Entity 在 Review PASS 前只能作为 Candidate 保存。只有 Canonical Commit 可以在同一事务中把经过验证的候选转为正式 `characters` / `world_entities`；系统可以显示类型覆盖缺口，但不得要求每本小说机械包含所有实体类型。

---

### chapters

```text
id
novel_id
volume_id
sequence
title
status
canonical_artifact_id nullable
word_count
summary
created_at
updated_at
```

唯一：

```text
unique(novel_id, sequence)
```

---

### chapter_plans

暂不单独建立 Scene Plan 表。

Scene Plans 保存为 JSONB。

```text
id
chapter_id
novel_outline_id
novel_outline_arc_id
novel_outline_beat_id
novel_outline_milestone_id
version
chapter_function
arc_contribution
arc_contributions JSONB
character_candidates JSONB
reader_promise
target_words
pov_character_id
tone
time_anchor
hook_type

must_reveal JSONB
may_hint JSONB
must_not_reveal JSONB
required_facts JSONB
forbidden_conflicts JSONB
due_foreshadowings JSONB
foreshadowing_actions JSONB
world_entity_candidates JSONB
scene_plans JSONB

checksum char(64)
input_hash char(64)
admission_snapshot JSONB
admitted_at

status
created_at
updated_at
```

`due_foreshadowings` 只保留历史整数 ID；新版本 Plan 使用 `foreshadowing_actions`。人工编辑保存为新的 Plan Version，旧版本不得原地覆盖。

Primary Contribution 使用 `novel_outline_arc_id + novel_outline_beat_id + novel_outline_milestone_id` 引用冻结 Outline 的完整父链，并记录目标 Scene 与验收条件；每个新 Plan 必须恰有一个 Primary，Secondary 只能推进获准支线，不能替代或提前完成后续 Main Beat/Milestone。`character_candidates` 与 `world_entity_candidates` 保存稳定临时键、名称或类型、描述、去重依据、潜在重复对象、引入理由与目标 Scene。三者在 Review PASS 前都只是 Draft 契约。

Chapter Plan 必须冻结 Outline ID、Checksum、Primary Arc/Beat/Milestone ID 和 Handoff 契约。`admission_snapshot` 在 Scene 1 前同时冻结 Bible/State/Outline/Plan 来源，以及 `writer`、`extractor`、`reviewer`、`rewrite`、`summary` 五个下游 AI Stage 的 Route、Prompt Version、模型容量和分档请求预算；相同 `input_hash` 复用 Ready Plan，语义输入变化创建新 Plan Version。Generation Run 的 Context Snapshot 同时记录已完成 Milestone/Beat IDs、当前 Beat 已使用的 Canonical Chapter 数和章节预算；后续大纲修订不得把旧 Plan 静默改挂到新版本。

Plan Admission v2 必须把模型静态容量与工作流请求预算分开保存。模型容量来自与冻结 Provider + Model 精确匹配且已启用的 `ai_model_prices` 记录；每档请求预算分别包含 `output_tokens`、`reasoning_reserve_tokens` 和两者之和 `max_completion_tokens`。任一 Stage 缺少已核实容量、缺少结构化输出能力、配置了不受支持的推理程度、预算超过模型最大输出或输入后的剩余上下文时，必须在 Provider 请求前失败。Chapter Planner 位于 Admission 之前，因此在其首个 Run 中独立冻结同样的路由、容量和预算合同。

---

### scenes

Scene 保留独立表，因为：

- 可以逐 Scene 生成；
- 可以局部 Rewrite；
- 可以进行事件抽取；
- 可以恢复。

字段：

```text
id
chapter_id
sequence
pov_character_id
location
time_anchor
goal
conflict
turn
outcome
status
current_artifact_id
created_at
updated_at
```

---

# 9.3 Characters & World

## characters

人物基础定义与当前轻量状态先放一张表。

```text
id
novel_id
name
aliases JSONB
role
profile JSONB
motivation
personality JSONB
abilities JSONB
knowledge JSONB
current_state JSONB
locked_fields JSONB
status
source_chapter_id nullable
source_candidate_key nullable
created_at
updated_at
```

MVP 暂不建立：

```text
character_states
relationships
```

人物关系暂时存在：

```text
characters.current_state.relationships
```

或 Story State。

当未来需要大量关系查询时再拆表。

---

## world_entities

统一承载：

- 地点；
- 组织；
- 物品；
- 世界概念；
- 特殊规则。

字段：

```text
id
novel_id
type
name
description
attributes JSONB
rules JSONB
current_state JSONB
locked_fields JSONB
status
created_at
updated_at
```

MVP 不建立：

```text
locations
items
factions
world_rules
```

独立表。

由 Canonical Commit 首次创建的实体使用 `(novel_id, source_chapter_id, source_candidate_key)` 保证幂等。Event 和 Memory 在提交事务完成后只引用正式 Entity ID；Latest Chapter Rollback 在没有其他正式章节引用时删除该章首次引入的实体。

---

# 9.4 Foreshadowing

### foreshadowings

```text
id
novel_id
title
description
setup_chapter_id
promised_payoff
due_from_chapter
due_to_chapter
importance
status
owner_arc_id
reinforce_count
payoff_chapter_id nullable
notes
created_at
updated_at
```

`status` 只表达内容生命周期：

```text
idea
planted
reinforced
paid_off
abandoned
```

允许的基础转换：

```text
idea → planted
planted → reinforced
reinforced → reinforced
planted/reinforced → paid_off
idea/planted/reinforced → abandoned（需要人工原因）
```

禁止 `idea → reinforced`、`idea → paid_off`，也禁止把 `paid_off` 或 `abandoned` 原地改回活跃状态。同章可以依次发生 `planted → paid_off`，但两个事件必须顺序明确，各自拥有能在当前 Canonical 正文中定位的证据，并分别通过状态校验。

`due_from_chapter` 与 `due_to_chapter` 是包含首尾章节的**兑现窗口**，不是铺设窗口。铺设事实由 `foreshadowing_planted` 及 `setup_chapter_id` 表达。时限状态不写入内容生命周期；对未终止伏笔，令 `C` 为最新 Canonical 章节序号（尚无正式章节时为 0），`N=C+1` 为下一个可规划章节：

```text
upcoming  N < due_from_chapter
due       due_from_chapter <= N <= due_to_chapter
overdue   N > due_to_chapter，且仍未 paid_off / abandoned
```

正在生成或阻塞中的草稿章节只单独展示，不推进正式时限状态。规划目标章仍按其目标序号判断是否处于兑现窗口：目标章等于 `due_to_chapter` 时，Critical 伏笔必须计划 `pay_off`；只计划 `reinforce` 不合格。

Critical 伏笔在兑现窗口内必须进入结构化 Chapter Plan；窗口结束仍未解决时阻止普通下一章规划，只允许用户明确授权的修复章、延期或放弃。High、Medium、Low 到期或逾期产生 Warning，不阻止普通章节，但 Volume Gate 与 Ending Audit 仍按其规则处理，Warning 不等于已经兑现。

延期、放弃和重新开启都是人工操作，模型不得自行决定：

- 延期保留当前内容状态，必须记录原因、旧/新窗口、操作时的 Canonical 章节与 State Version、操作者和时间；新窗口必须晚于旧窗口且有效。
- 放弃把活跃状态转为 `abandoned`，必须记录原因和相同审计上下文；管理决策不得伪造正文 Story Event 或 evidence。
- 重新开启不得把终态记录原地回退。应创建引用原记录的新伏笔，重新明确 `promised_payoff`、窗口和验收条件；初始内容状态必须由已有 Canonical 证据决定，不能无证据直接设为 `reinforced`。
- 具体审计数据的存储方式由后续领域/UI 任务在复用现有设施的前提下实现；本规则不要求新增独立审计系统。

历史持久化的 `due` 状态与 `foreshadowing_due` 事件仅作兼容读取和迁移识别，不再产生新值或新事件，也不得覆盖 `idea`、`planted`、`reinforced`。迁移必须保留历史可解释性。

概念边界：

- Foreshadowing 是指向具体未来揭示或结果的叙事义务，必须有明确承诺、兑现窗口和验收证据。
- Reader Promise 是对读者建立的期待，可以跨越多个情节或 Arc，不必具备隐藏线索和伏笔生命周期。
- World Rule 是持续成立的世界规律，不以一次 `paid_off` 结束；需要确定性校验时可再建立引用该规则的 Locked Fact。
- Locked Fact 是可精确查询、人工锁定且能确定性校验的事实，没有兑现窗口。
- Character Arc 是角色长期变化及其里程碑；伏笔可以服务于 Character Arc，但两者必须分别追踪。

例如“魔法必有代价”应归为 World Hard Rule；只有“某次具体代价将在何时、以什么结果显现”才是可兑现伏笔。

暂不建立：

```text
foreshadowing_events
```

相关变化直接通过 Story Event 记录。

---

# 9.5 Story State

## story_events

正式章节产生的事件日志。

```text
id
novel_id
chapter_id
scene_id nullable
event_type
subject_type nullable
subject_id nullable
payload JSONB
evidence JSONB
story_time nullable
state_version
created_at
```

要求：

- 只允许 Canonical Chapter 写入；
- 每个关键事件必须有文本证据；
- 永不直接覆盖历史事件。

主线正式进度使用两个 Canonical Event：

```text
story_arc_beat_milestone_completed
story_arc_beat_completed
```

Milestone 必须按 Sequence 完成。一章可以推进但不完成 Milestone；只有验收条件具有当前 Canonical 正文证据时才能写入 Milestone Completion。Beat Completion 还要求全部 Milestone 已有 Active Completion Event、Beat 验收条件全部满足，并且最终 Milestone 已满足 Handoff。前一 Beat 最后一章可以建立下一 Beat 的 Trigger，但不能在同一章提前完成下一 Beat Milestone。

---

## story_state_versions

保存完整 Story State 快照。

```text
id
novel_id
version
chapter_id
state JSONB
checksum
created_at
```

唯一：

```text
unique(novel_id, version)
```

`state` MVP 示例：

```json
{
  "characters": {},
  "relationships": {},
  "locations": {},
  "items": {},
  "world": {},
  "timeline": {},
  "open_threads": {},
  "foreshadowings": {},
  "reader_promises": {}
}
```

---

## facts

只保存真正需要结构化查询和锁定的事实。

```text
id
novel_id
subject_type
subject_id
predicate
value JSONB
hardness
confidence
status
locked
source_event_id nullable
created_at
updated_at
```

用途：

```text
主角不会游泳
某角色已经死亡
神器归属于某人
某秘密尚未被角色知道
```

---

# 9.6 Generation

## generation_runs

整个生成系统的运行记录中心。

```text
id
novel_id
chapter_id nullable
scene_id nullable

scope_type
scope_id

stage
status
attempt

idempotency_key
input_hash

state_version
bible_version
prompt_version
model_policy

context_snapshot JSONB

error_code nullable
error_message nullable
error_retryable nullable
error_metadata JSONB nullable
progression_failure JSONB nullable

started_at
finished_at
created_at
updated_at
```

唯一：

```text
unique(idempotency_key)
```

---

## generation_artifacts

统一替代：

- artifacts；
- rewrite_attempts；
- 独立 Context Snapshot 表。

```text
id
generation_run_id
type
version
content TEXT
data JSONB
checksum
created_at
```

类型：

```text
chapter_plan
scene_draft
chapter_draft
rewrite_draft
review_result
event_candidate
state_patch
summary
context
```

---

## reviews

```text
id
generation_run_id
artifact_id

decision
score

continuity_score
plan_score
character_score
progress_score
repetition_score
pacing_score
style_score

findings JSONB
created_at
```

decision：

```text
PASS
REWRITE
NEEDS_ATTENTION
BLOCK
```

---

# 9.7 Memory

## memories

```text
id
novel_id

type
source_type
source_id

summary
entities JSONB

salience
status

embedding vector(n)

embedding_model
valid_from_chapter
valid_to_chapter nullable

created_at
updated_at
```

MVP 直接将 embedding 放在 memories 表。

暂不拆：

```text
memory_embeddings
```

---

# 9.8 Cost

## usage_records

```text
id
generation_run_id
provider
model

input_tokens
output_tokens
cached_tokens

latency_ms
estimated_cost

request_id
created_at
```

---

# 10. MVP 核心表清单

共 23 张：

```text
1  novels
2  novel_bibles
3  novel_outlines
4  novel_outline_volumes
5  novel_outline_arcs
6  novel_outline_beats
7  novel_outline_milestones

8  volumes
9  story_arcs
10 chapters
11 chapter_plans
12 scenes

13 characters
14 world_entities
15 foreshadowings

16 story_events
17 story_state_versions
18 facts

19 generation_runs
20 generation_artifacts
21 reviews

22 memories
23 usage_records
```

---

# 11. 暂不拆表的内容

以下内容先使用 JSONB：

```text
relationships
timeline projections
scene plans
world rules
items
locations
factions
context snapshots
rewrite metadata
reader promises
open questions
model policy snapshot
```

当出现以下情况时才拆表：

1. 查询复杂度显著增加；
2. JSONB 更新频繁；
3. 需要独立唯一约束；
4. 需要大量 JOIN；
5. 性能压测证明有必要。

---

# 12. Story State Engine

核心流程：

```text
Canonical Draft
     ↓
Event Extractor
     ↓
StoryEventCandidate[]
     ↓
Validator
     ↓
State Patch
     ↓
Review Gate
     ↓
Canonical Commit
     ↓
story_events
     ↓
story_state_versions N+1
```

---

## 12.1 状态域

MVP 统一维护：

```text
characters
relationships
locations
items
timeline
world
plot_threads
foreshadowings
reader_promises
```

---

## 12.2 Hard Conflict

Hard Conflict 必须阻止 Commit。

示例：

- 死者无解释复活；
- 锁定规则被违反；
- 人物拥有尚未获得的信息；
- 时间倒置且计划未声明；
- 关键物品同时存在多个位置；
- expected state version 不一致。

---

## 12.3 Soft Conflict

允许进入评分：

- 性格轻微漂移；
- 情绪变化铺垫不足；
- 节奏异常；
- 对话重复；
- 冲突推进较弱。

---

# 13. Context Builder

Context Builder 是系统最核心组件之一。

上下文注入顺序：

```text
1 System / Output Schema
2 Bible Hard Constraints
3 Ending Contract
4 Current Outline Target / Volume / Arc / Chapter Plan
5 Current Story State
6 Characters / World
7 Due Foreshadowing
8 Recent Chapter Summaries
9 Previous Scene Tail
10 Long-term Memory RAG
11 Task Instruction
```

---

# 14. Memory System

## L0 — Hard Constraints

来源：

```text
Bible
Facts locked=true
Ending Contract
```

永远注入。

不经过 Vector Search。

---

## L1 — Current State

来源：

```text
story_state_versions
characters
world_entities
foreshadowings
```

SQL 获取。

---

## L2 — Recent Memory

例如最近：

```text
5～20 章
```

使用：

- chapter summary；
- Story Events；
- Reader Promise；
- 最近冲突。

---

## L3 — Long-term Memory

使用：

```text
pgvector
+
metadata filter
```

先过滤：

```text
novel_id
status
entity
type
chapter range
```

再执行向量召回。

---

## L4 — Style

权威来源固定为：

```text
Current Novel Bible Version
```

包含 tone、POV、tense、Narrative Voice、主/辅文风、语言时代感、节奏和高级文风参数。Prompt Config 只能把 Bible 中的稳定 code 展开成写作指令，不能保存另一套小说级权威值。

暂不单独设计 Style Memory 表。

首次 AI 小说蓝图生成发生在 Current Bible 创建前，是唯一不能读取 Current Bible 的生成入口。它只生成包含完整叙事与文风设置的 Bible 候选；用户采用并创建首个 Current Bible 后，章节相关阶段必须只读取该版本。

---

# 15. Chapter 生成策略

MVP 默认：

```text
Current Beat / Milestone
   ↓
Chapter Plan + Plan Admission Gate
   ↓
逐 Scene 生成
   ↓
Scene 临时接受
   ↓
Laravel 按顺序确定性 Assembly
   ↓
Coverage Judgment（存在计划 Coverage Finding 时）
   ↓
Event Extraction / State Validation
   ↓
Compact Narrative Review
```

Scene 之间允许使用：

```text
temporary chapter state
```

但只有整章通过 Commit 后才能成为 Canonical State。

---

# 16. Review Gate

质量维度：

| 维度 | 权重 |
|---|---:|
| 事实 / 连续性 | 25 |
| 计划遵循 | 15 |
| 人物一致性 | 15 |
| 剧情推进 | 15 |
| 重复度 | 10 |
| 节奏 / 悬念 | 10 |
| 文风 / 可读性 | 10 |

总分：

```text
100
```

---

## 16.1 Decision

### PASS

条件：

```text
无 Hard Conflict
+
总分达到阈值
```

---

### REWRITE

问题明确且可修复。

优先：

```text
局部段落
↓
Scene
```

自动流程禁止整章 Rewrite。无法定位到唯一段落/Scene、涉及多个 Scene 的结构问题，或需要改变 Milestone/Handoff/Canonical Fact 时，进入 Plan 修订、级联 Scene 重生成或 `NEEDS_ATTENTION`。

`NEEDS_ATTENTION` 与 `BLOCK` 的章节工作台必须根据已持久化错误和 Findings 显示确定性的修复建议，包括问题层级、确认依据、建议字段、影响范围和恢复入口。局部 paragraph 问题才允许人工修改 Scene 正文，并创建新的不可变 Scene Rewrite Artifact；Scene 结构问题从最早受影响 Scene 级联重建；Plan 修改创建新 Plan Version 和 Planning 来源链边界；Milestone/Handoff 进入 Outline/Plan 重建；Locked Fact 或 Canonical State 问题必须进入受控事实修复或最新正式章回滚，正文编辑不得绕过。

---

### NEEDS_ATTENTION

需要人工处理：

- 连续 Rewrite 失败；
- Hard Fact 存在歧义；
- Ending 发生重大变化；
- Cost 超限；
- Plan 无法满足。

---

### BLOCK

不可提交。

Review 调用前由 Laravel 确定性检查字数、Scene 顺序、Artifact Lineage、State Version、Locked Facts、Outline/Beat/Milestone/Handoff 身份、Coverage 和逐字 Evidence。Writer/Assembly 的 Coverage 是结构化自报，不得直接作为 Rewrite 事实；存在 `missing`、`contradicted` 或证据修复耗尽时，必须先按 Scene 创建独立 Coverage Judgment Run/Artifact。只有 Judgment 确认的缺失或反转才能成为 Rewrite 来源，Judgment 技术失败必须停止推进并显示失败 Run。模型只返回七维分数、紧凑语义审计和可执行 Findings，不重复生成权威 ID、顺序或完整 Coverage。`findings` 是语义问题集合的权威来源，最终 Decision 由 Laravel 合并确定性 Findings、分数和 Rewrite 预算后派生。

每个 Review Job 最多一次模型请求。状态声称有问题但没有 Finding 等 Schema/Evidence 修复必须作为具有独立 Input Hash、Run 和 Artifact 的子阶段执行，不得在 Review Job 内循环调用；失败进入 NEEDS_ATTENTION 并保留 `ai_request_log_id`。

普通 warning 按分数和可执行性分流：总分达到 `review_pass_score` 且没有其他门禁时允许 PASS，并保留为非阻塞建议；总分未达标且 warning 可执行时进入 REWRITE；auto-fixable error 进入 REWRITE；真正需要用户选择、没有安全修复路径或 Rewrite 耗尽时进入 NEEDS_ATTENTION。Hard Conflict 仍无条件 BLOCK。

每轮 Review 必须先完成全部七个质量维度的检查，再一次性返回当前正文中所有有明确证据的问题；不得发现一个问题后提前结束。同一 Scene 的可修复 Findings 可以合并为一个局部任务；跨 Scene 问题从最早受影响 Scene 开始按顺序有限修复；结构问题返回 Plan 层，不得为了“一次修完”输出完整章节。

每次 Rewrite 后的 Review 必须为上一轮每个可执行 Finding 保存 `resolved / still_present / replaced` 结果，再执行七维全量检查。`max_rewrite_attempts` 只统计成功创建的自动正文 Rewrite Draft；长度 Repair、Coverage Repair、Review Schema Repair、失败的 Provider 请求和失败的 Schema 输出不计入正文 Rewrite 配额。

---

# 17. Rewrite 策略

默认：

```text
MAX_REWRITE_ATTEMPTS = 2
```

第三次仍失败：

```text
NEEDS_ATTENTION
```

避免无限循环：

```text
Generate
 ↓
Review
 ↓
Rewrite
 ↓
Review
 ↓
Rewrite
 ↓
Review
 ↓
STOP
```

Paragraph Rewrite 必须以逐字唯一命中的 `search/replacement` 补丁更新所属 Scene Artifact；Scene Rewrite 只替换一个目标 Scene。任何局部 Rewrite 都重新执行 Deterministic Assembly、Event Extraction、State Patch 和 Review，旧下游 Artifact 不得复用。

人工处理必须先标明问题层级、建议修改字段、影响范围和恢复路径。Beat/Milestone 目标错误修订未来 Outline Version；Chapter/Scene Plan 错误创建新 Plan Version并从最早受影响 Scene 重建；Canonical Fact/State 错误使用受控 Correction 或回滚；只有不改变计划结果和正式事实的措辞、节奏或文风问题才允许直接修改正文。人工正文修改同样创建不可变 Artifact，并重建所有下游派生数据。

---

# 18. Queue 设计

MVP 只使用：

```text
generation
default
```

---

## generation

```text
PlanChapterJob
GenerateSceneJob
AssembleChapterJob
ReviewChapterJob
RewriteChapterJob
CommitChapterJob
```

---

## default

```text
UpdateMemoryJob
GenerateEmbeddingJob
GenerateCanonicalChapterSummaryJob
RefreshNovelProjectionJob
ContinueAutoGenerationJob
EndingAuditJob
```

以后出现拥堵再拆 Queue。

---

# 19. Job 流水线

```text
PlanChapterJob
        ↓
Plan Admission Gate
        ↓
BuildContext
        ↓
GenerateSceneJob
        ↓
GenerateSceneJob
        ↓
...
        ↓
AssembleChapterJob
        ↓
ExtractStoryEventsJob
        ↓
StatePatchBuilder / StateValidator
        ↓
ReviewChapterJob
        ↓
   ├ REWRITE → Paragraph/Scene Rewrite → Assembly → Event/Patch/Review
   ├ 规划问题 → 新 Plan Version → 从最早受影响 Scene 重建
   ├ NEEDS_ATTENTION / BLOCK → 停止
   └ PASS → 按 novel.settings.auto_commit 判断
              ├ false → 停止并等待用户确认
              └ true  → 安全派发
                         ↓
                    CommitChapterJob
              ↓
         UpdateMemoryJob
              ↓
GenerateCanonicalChapterSummaryJob
              ↓
 RefreshNovelProjectionJob
              ↓
 ContinueAutoGenerationJob
```

Post-Commit 派生任务使用上述顺序 Queue Chain。Memory、Summary 或 Projection 失败都不会回滚已经成功的 Canonical Commit，但会停止自动续写并保留可恢复的 Generation Run。Embedding 由 Memory 独立派发和重试，不阻塞下一章。手动或自动生成下一章时，上一正式章必须已有 `chapters.summary`；缺失时应进入摘要恢复操作。

Context Builder MVP 可以作为 Service：

```text
ContextBuilder
```

不必单独建立 Queue Job。

Chapter Plan 之后的 Scene Writer、Story Event Extractor、Reviewer 和 Rewriter 必须读取同一份冻结伏笔动作契约；Deterministic Assembler 只读取经过校验的 Scene Artifact 和该契约，不调用 Provider。契约记录完整伏笔定义、promised payoff、Canonical 优先的内容状态、时限、兑现窗口、重要度、所属 Arc、本章动作、目标 Scene、验收条件、最近有效事件证据，以及 Bible/State/Plan 版本和 checksum。只有结构化 `foreshadowing_actions` 中的伏笔具有本章处理权限；未选中的未来伏笔不得被模型绑定为本章伏笔事件。该契约属于强制上下文，Token 紧张时先裁剪近期摘要和长期 Memory，不能删除 Critical、Due 或 Overdue 契约。

Scene Writer 必须按目标 Scene 返回契约中每条伏笔动作的 `fulfilled / missing / contradicted` Coverage。`fulfilled` 与 `contradicted` 的 evidence 必须逐字来自当前正文，`missing` 的 evidence 必须为 `null`；Laravel 必须校验伏笔 ID、动作、顺序、Scene 归属和原文引用。只有正文具体结果满足该动作的 `acceptance_criteria` 才能报告 fulfilled；只有主题或意象相近、但没有动作结果时必须报告 missing。缺失或冲突形成可自动 Rewrite 的 Finding，不得修改 Canonical State。

Deterministic Assembler 必须按 Scene Sequence 拼接正文，并从 Scene Artifact 聚合伏笔 Coverage。它不得改写正文、重新判断或提升 Coverage，也不得新增动作结果；缺失、冲突、错误顺序、跨章来源或字数问题必须返回最早受影响 Scene 修复。跨多个 Scene 的动作仍按各目标 Scene 保存 Coverage，由 Chapter Draft 聚合后交给后续 Event Extraction 和 Review 判断整体是否满足契约。

Story Event Extractor 只能为冻结契约中已授权且最终 Coverage 为 fulfilled 的伏笔动作生成候选事件。事件类型必须与动作一一对应，事件 evidence 必须覆盖该动作的 Coverage 原文并引用目标 Scene；未选中伏笔、未来伏笔、missing/contradicted 动作、动作类型不匹配和主题相似内容都不得生成伏笔事件。`defer` 不产生正文 Story Event；`abandon` 只有在冻结契约包含与 State Version 一致的人工授权时才能生成事件候选。

伏笔候选事件必须按正文和动作契约顺序执行确定性生命周期校验：`idea → planted`、`planted/reinforced → reinforced`、`planted/reinforced → paid_off`，同章允许依次 `plant → reinforce` 或 `plant → pay_off`。除尚未进入正文的新 `idea` 可以从领域记录开始铺设外，已有 `planted/reinforced/terminal` 状态必须来自冻结 Canonical Story State，不能只凭可能漂移的领域投影生成事件。`paid_off/abandoned` 终态不能被生成事件重新开启。Extractor 在保存 Event Candidate 前执行该规则，StateValidator 再使用 Event Candidate Run 中冻结的契约独立复核；失败只产生候选校验错误或 Hard Finding，不修改 Canonical State。

Reviewer 必须在首轮全量审校中按冻结 Plan 顺序逐项输出伏笔审计，同时对照 promised payoff、动作语义、最终 Coverage、当前草稿对应的 Event Candidate 和正文逐字证据。仅出现相同关键词不能证明 `reinforce` 或 `pay_off`；`pay_off` 必须在语义上完成承诺及验收条件。模型报告 fulfilled 时，Laravel 仍须确认存在 fulfilled Coverage 和匹配事件。正文可修复的问题按伏笔 ID、动作和目标 Scene 合并为一个 Finding，进入一次包含全部修复目标的 Rewrite；需要延期、放弃、改变窗口或 promised payoff 的问题进入 NEEDS_ATTENTION，Rewrite 不得修改伏笔定义或 Canonical State。

Paragraph/Scene Rewrite 必须经 Deterministic Assembly 重新汇总 Coverage。任何 Rewrite 产物都先使旧 Chapter Draft、Event Candidate、State Patch 和 Review 失效，再依次重新执行 Assembly、Event Extraction、State Patch 和全量 Review。只有重写稿对应的新 Review PASS 才能停止自动流程，历史 Review、Coverage 或 Event Candidate 不得跨草稿复用。

Canonical Commit、Latest Chapter Rollback 或 Manual Canonical Correction 成功后，必须按当前 Canonical State Version 异步刷新伏笔领域投影。目标 `status` 和 `reinforce_count` 从 Canonical 基线及不晚于目标版本的 Active Events 重放得到，并与 Canonical State 复核；`setup_chapter_id`、`payoff_chapter_id` 只能来源于对应的有效铺设、兑现事件。刷新必须幂等，旧 State Version 的任务不得覆盖新指针；刷新失败可独立重试，不能撤销已经成功的 Canonical 写入。

伏笔管理页必须并列显示 Canonical 内容状态、按下一正式章节计算的时限状态、表投影健康状态、Canonical 强化次数、最近有效伏笔事件及证据、未来 Chapter Plan 中的下一动作，并分别显示最新 Canonical 章节和活跃章节工作流。页面检测到投影漂移时以 Canonical State 为准，并提供 Story State 检查和幂等投影重建入口。Critical 逾期项提供安排修复章、人工延期和人工放弃入口；延期将原因、旧/新窗口、Canonical 章节、State Version、操作者和时间写入伏笔管理审计历史，放弃通过 `ManualCorrection` 创建新 State Version，不伪造正文 `foreshadowing_abandoned` 事件。已有伏笔的内容状态、强化次数、铺设/兑现章节和兑现窗口不得通过普通 Edit 绕过这些受控操作。

历史伏笔修复必须先生成冻结计划并默认 dry-run。计划必须固定小说、完整重建基线、Expected State Version/checksum、待失效或替换的 Event、逐字 Canonical evidence、原因、状态前后差异和未决项；用户审阅后才能显式执行。执行只能追加 correction/invalidation 审计和新的 State Version，不得删除原 Event 或覆盖历史 State Version；无效 Event 的来源 Memory 必须在同一事务中失效，替代 Event 只有在正文证据和摘要不变时才能复用原 Memory/Embedding，最后统一刷新领域投影。相同冻结计划重复执行不得产生重复正式数据。

`promised_payoff` 是作者侧验收信息，不等于允许在本章公开秘密；所有阶段仍必须同时遵守 `must_not_reveal`。

---

# 20. Canonical Commit

这是系统最严格的事务。

同一个 Novel：

```text
只能存在一个 Canonical Commit
```

事务步骤：

```text
BEGIN

检查 expected_state_version

检查 chapter 仍未 canonical

创建 Canonical Artifact

写 story_events

写 Milestone / Beat Completion Events（满足时）

创建 story_state_versions N+1

更新 chapter.status

更新 novels.canonical_state_version_id

从 Active Completion Events 重算当前 Milestone / Beat / Arc Progress

COMMIT
```

必须：

- Transaction；
- Unique Constraint；
- Idempotency Key；
- Optimistic Lock / CAS。

Draft、Plan、Review、Rewrite 和 Outline 定义表都不得写完成状态。Handoff 只定义相邻 Main Beat 的退出结果与下一触发条件；正式完成进度只能在 Canonical Commit 事务中产生。

---

# 21. 并发策略

MVP：

```text
不同 Novel
→ 可以并发

同一个 Novel
→ Chapter 级流程默认串行
```

不做复杂 Scene 并行。

理由：

- 实现更简单；
- 更容易 Debug；
- 状态冲突更少；
- 单用户场景无需极致吞吐。

未来性能不足再考虑 Scene Parallel。

---

# 22. Pause / Resume

## Pause

```text
停止创建新 Generation Run
```

已经调用模型：

```text
允许完成
→ 保存 Artifact
→ 禁止 Commit
```

---

## Resume

检查：

```text
Novel status
State Version
Budget
Last Run
Pending Artifact
Chapter Plan
```

然后从最近可恢复阶段继续。

章节技术 Retry 和 Resume 必须复用失败 Run 或 Plan Admission 已冻结的 Provider、Model、推理程度、Prompt Version、模型容量与分档请求预算；后台配置变化不得改变同一恢复链。Provider 配置失败或冻结最高档的推理耗尽、可见输出截断、完成预算耗尽不得 Resume，必须修复配置后显式重建章节来源链。运行详情必须把冻结路由、静态容量、本次预算档位与触发原因、容量门禁快照、Provider 实际发送参数、`reasoning_tokens`、`finish_reason` 和完成预算分类分开显示。

历史 Admission v1 已经完成 Planner、Scene 和 Chapter Draft 时，允许使用显式“恢复章节流程（Admission v1）”动作建立完整下游来源链。该动作不得修改旧 Plan Admission，不得重新请求 Planner 或 Writer；它必须创建零 Provider 的 `chapter_recovery` Generation Run 和不可变 Context Artifact，一次冻结当前 Extractor、Reviewer、Rewrite、Summary 的 Provider、Model、Reasoning Effort、Prompt Version、模型容量、主请求预算与修复子阶段预算。合同成功落库后只能由统一推进器从真实节点选择 Coverage Judgment、Event Extraction、Review、Rewrite 或确定性 Assembly；每个实际 Provider Run 必须复制恢复合同 ID 与自身阶段路由。执行前必须核对 Plan、Admission 快照、冻结 Chapter Draft、Scene 来源链、Bible Version 和 Canonical State Version；局部 Rewrite 只允许产生可追溯到同一合同的新 Scene Artifact，Canonical Commit 后 Summary 继续使用同一合同。任一来源变化时在 Provider 请求前失败。

审校页面只提供一个“执行局部重写”入口，目标 Paragraph / Scene 必须由当前 Review 保存的 `rewrite_scope` 决定，页面不得让用户任选 Scene，也不得提供会被误解为整章 Provider Rewrite 的按钮。点击入口后先同步验证当前 Draft、Review、Rewrite 次数与冻结合同：Admission v1 自动创建或复用完整恢复合同；旧 Coverage Review 先解除其遗留阻塞并进入 Coverage Judgment / 新 Review；只有当前 Review 已覆盖最新 Judgment 且范围可安全解析时才派发 Rewrite。`NEEDS_ATTENTION` 不得通过该入口自动重写。确定性预检失败必须直接显示具体原因，不得先提示已经加入队列。

章节工作台的“提取事件 / 重新提取事件”常规动作必须在设置队列标记和派发 Job 前同步预检 Extractor 冻结合同。旧 Admission、Provider Route 不完整、模型容量缺失、三档预算无效或预算超过冻结容量时，页面直接显示具体错误码与修复方向，并保持零 Job 派发；Admission v1 应引导使用独立的完整章节恢复动作。

运行详情必须单独显示 `route_contract_source`、恢复合同 Run ID、冻结来源 Artifact、实际 Provider 请求数；`chapter_recovery` Run 还必须从不可变 Context Artifact 展示 Extractor、Reviewer、Rewrite、Summary 四条完整冻结路由。章节时间轴把恢复合同作为独立的零 Provider 阶段展示，不能混入普通 Context 节点。恢复合同落库成功但后续推进失败时，使用统一 `progression_failure` 结构记录原始错误码、分类、可重试性、推荐动作和发生次数。

完成预算分类不能只根据“是否已经出现少量可见内容”判断。Provider 返回 `finish_reason=length` 时，事件提取阶段必须把实际 `reasoning_tokens` 与本档冻结推理预留比较：推理消耗超过预留并挤占可见输出时归类为 `reasoning_budget_exhausted`；推理未超过预留但结构化结果仍不完整时才归类为 `visible_output_truncated`。Usage 同时保留 Provider 原始分类和结合冻结合同后的权威分类。

---

# 23. Failure Recovery

每一个阶段都依赖：

```text
generation_runs
+
generation_artifacts
```

输入 hash 相同且 Artifact 已成功：

```text
直接复用
```

避免：

```text
重复调用模型
重复花钱
重复生成正文
```

---

# 24. Ending Controller

必须保留，不因“精简”删除。

---

## Ending Contract

存于：

```text
novel_bibles.ending_contract
```

包括：

```text
final protagonist state
main conflict resolution
theme payoff
required foreshadowing payoff
character arc requirements
allowed open endings
```

---

## Closing Horizon

当：

```text
remaining_words
remaining_chapters
```

达到阈值：

```text
novel.status = completing
```

开始限制：

- 新核心人物；
- 新主线；
- 新世界硬规则；
- 高重要度新伏笔。

---

## Closure Debt

计算：

```text
未完成 Arc
+
未兑现 Reader Promise
+
未回收 Foreshadowing
+
未解决 Relationship
+
未解决 World Crisis
```

critical debt > 0：

```text
禁止 completed
```

---

# 25. Filament 后台

只保留 6 个一级入口。

```text
Dashboard
Novels
Generation
Review
Memory
Settings
```

---

## Novel 页面 Tabs

```text
Overview

Bible

Characters

World

Planning
  ├ Volume
  ├ Arc
  ├ Chapter Plan

Chapters

Foreshadowing

Runs
```

---

# 26. Dashboard

Dashboard 是跨小说的日常操作摘要，不承载章节生成领域逻辑。顶部主操作为：

```text
创建小说
继续当前小说
打开恢复中心
```

显示真实持久化指标：

```text
活跃小说
正在运行章节
今日 Token / Cost
Failed
Blocked
Needs Attention
最近 Generation Run
待处理伏笔
```

最近生成必须显示 Novel、Chapter、Stage、Status、耗时、成本与时间，并进入 Chapter Workbench 或 Run Inspector。

Novel Overview 显示当前小说的 Canonical 字数、Active Volume、当前流水线、首轮 Review 通过率、Rewrite 比例、待处理伏笔以及失败和审校待办。下一步操作由持久化状态决定，同一时刻只突出一个主操作。

20/50/100 章长跑属于诊断与验收工具，由 `generation.acceptance_tools_enabled` 控制，默认关闭。开启后仍必须选择小说并明确确认。

---

# 27. Review 页面

只处理：

```text
NEEDS_ATTENTION
BLOCK
```

功能：

```text
查看 Draft
查看 Findings
查看相关 Fact
查看 Story State
重新生成
人工修改
Override
Commit / Reject
```

不设计多人审批。

---

# 28. Memory Inspector

提供：

```text
搜索关键词

Vector Query

查看 Top-K

查看 Memory 来源

查看所属 Chapter

查看 similarity

disable memory

重新 embedding
```

用于调试 Context Builder。

---

# 29. AI Provider

定义一个简单接口：

```php
interface AiProvider
{
    public function generate(AiRequest $request): AiResponse;
}
```

当前实现两个文本生成 Provider：

```text
OpenAI
DeepSeek
```

Laravel 按 Stage 已解析并冻结到 Generation Run 的 Provider 进行固定路由。不得实现动态选型、按价格自动路由或失败后跨 Provider 自动切换。Embedding 继续固定使用 OpenAI，不随文本生成 Provider 切换。

OpenAI 严格结构化输出在发出请求前必须递归校验 Schema：根节点为 object、每个 object 设置 `additionalProperties=false`，且 `required` 完整覆盖 `properties`。Provider 成功响应仍按同一 Schema 本地复验；非法 JSON、Schema 不匹配、拒绝和 Token 截断必须使用不同错误码。`finish_reason=length` 不能单独证明可见输出被截断：有部分可见内容时分类为 `visible_output_truncated`，没有可见内容且 `reasoning_tokens>0` 时分类为 `reasoning_budget_exhausted`，证据不足时分类为 `completion_budget_exhausted`，不得猜测。章节阶段只有在下一档分别增加可见输出额度、推理预留或总完成预算时，才允许对应分类进入 Queue Retry；普通临时故障沿用当前档，最高档耗尽立即终止。DeepSeek Route 显式冻结推理程度时必须发送并记录实际 `reasoning_effort`，留空时采用 Provider 默认行为。HTTP 4xx 应保留经过脱敏和长度限制的 Provider 原始错误原因，便于从 Generation Run 直接定位参数或 Schema 问题。Embedding 响应必须验证向量为数值列表且维度与请求一致。

章节 Provider Stage 的预算键固定为 `planner`、`writer`、`extractor`、`reviewer`、`rewrite`、`summary`。除当前仅有 `initial` 档的 `reviewer` 外，其余阶段使用 `initial / retry / final` 冻结档位；环境变量中的 `*_MAX_OUTPUT_TOKENS` 表示可见输出额度，`*_REASONING_RESERVE_TOKENS` 表示隐藏推理预留。确定性的 Chapter Assembly 不读取模型路由或 Token 环境变量，也不得创建 Provider 请求或 Usage。

会实际调用 Provider 的修复子阶段必须拥有独立预算合同，当前包括 Scene 辅助字段、计划 Coverage 证据、伏笔 Coverage 证据、Event Evidence、Coverage Judgment、Review Schema、Arc Completion 和 Scene 字数修复。每一档都同时冻结 `output_tokens`、`reasoning_reserve_tokens` 与两者之和 `max_completion_tokens`；不得以一个整数、父阶段最大预算或模型静态容量代替。修复合同随 Plan Admission Route 进入 Input Hash，Run 再冻结本次可用分档和所选档位；Resume 读取 Run/Admission 冻结值，只有新 Plan Admission 才读取新配置。Provider 请求必须精确等于所选总完成预算，运行详情必须记录子阶段、输出额度、推理预留和实际总预算。预算缺失、结构无效或超过模型容量时，在 Provider 请求前失败。

---

# 30. Prompt 系统

MVP 只需要：

```text
NovelPlanner
ChapterPlanner
SceneWriter
Reviewer
StoryEventExtractor
```

Prompt 必须版本化。

可以使用：

```text
config
database
markdown files
```

其中一种简单实现。

不引入复杂 Prompt CMS。

---

# 31. 成本控制

层级：

```text
Novel
Chapter
Generation Run
```

MVP 可配置：

```text
daily_soft_limit
daily_hard_limit

novel_total_limit

chapter_max_cost

rewrite_max_attempts
auto_commit（小说级，默认 false）
```

Filament “AI 与成本”只提供“供应商连接”和“模型价格”两个导航入口。连接保存 Provider、Base URL、加密 API Key、Timeout 与启用状态；价格按 Provider + Model + Currency 保存计费单位、输入/缓存输入/输出价格、上下文窗口、最大输出及结构化输出与推理程度能力。容量和能力必须按实际 Provider 文档及账户可用模型核实；未核实的字段保持空值或关闭，不得推断。“模型价格”页面同时维护各 AI Stage 的 Provider + Model + 可选推理程度路由，并写入独立的 `ai_model_routes` 表。小说创建与编辑页的 Stage Override 必须从已启用的模型价格中选择，实时显示当前表单将生效的 Provider、Model、推理策略和来源，并把 Provider + Model 保存到 `novels.settings.ai.stages`。已有同一 Provider + Model 的小说级可选推理程度在无关保存时必须保留；用户明确改选路由时重置为 Provider 默认。非 Outline 的旧 `ai.models` 值继续兼容读取，在用户保存时迁移；Outline 的旧 Model-only 值必须显示为不完整配置，无关保存时原样保留，只有用户重新选择完整 Provider + Model 后才能迁移。未录入价格表的旧值不得因无关编辑被静默丢弃；全局 Outline 路由缺失时创建或编辑表单仍必须可打开，以便用户选择小说级完整路由。

推理程度按 `planner`、`outline_foundation`、`outline_structure`、`outline_arc_beats`、`outline_beat_detail`、`writer`、`extractor`、`reviewer`、`rewrite`、`summary` 分别配置；留空推理程度表示明确使用 Provider 默认行为，向量生成不使用该配置。四个 Outline Provider 任务必须分别使用 `outline_foundation`、`outline_structure`、`outline_arc_beats`、`outline_beat_detail` 的独立完整路由，任何配置层都不得回退或继承 `planner`、通用 `AI_MODEL` 或其他 Stage。每条路由的 Provider、Model 和推理程度按同一来源整体解析，固定优先级为：小说级该 Stage Override → `ai_model_routes` 中该 Stage 的精确记录 → 旧 `system_settings.ai` 中该 Stage 的精确兼容值 → 该 Stage 专用环境配置；小说级旧 `ai.models.<outline_stage>` 只有 Model、不能表达完整路由，遇到时必须拒绝并要求重新保存。四层均不存在完整路由时必须在创建 Run 和调用 Provider 前以 `outline_route_not_configured` 拒绝启动。专用环境键为 `AI_PROVIDER_OUTLINE_*`、`AI_MODEL_OUTLINE_*`、`AI_REASONING_EFFORT_OUTLINE_*`，其中推理程度可以留空，Provider 和 Model 不得缺失。

启动 AI Outline 前，页面必须预览四个 Provider 任务的有效 Provider、Model、推理程度、配置来源、Prompt Version、请求预算、推理预留、已核实容量及配置错误；错误必须同时显示稳定错误码和用户文案。缺少任一独立路由、已启用模型价格记录、正整数容量、有效请求预算或结构化输出能力时，预览必须标记不可启动；配置推理程度时还必须确认该模型支持 `reasoning_effort`。全局路由和小说级新选择在保存边界执行同一适用性校验，服务端不得创建 Batch、子 Run 或发出 Provider 请求。新建 v4 Outline 主批次在开始时按四条有效路由分别读取对应 `ai_model_prices` 记录，并冻结 Foundation、Structure、Arc Beats、Beat Detail 各自的 Provider、Model、推理程度、Prompt Version、模型价格记录 ID、容量、能力、结构化输出额度与推理 Token 预留；Batch 和 Stage 的 `input_hash` 都必须包含冻结请求预算，后台修改配置不得改变已存在批次或复用边界。内部统一的 `max_completion_tokens` 请求预算为结构化输出额度与推理预留之和，Provider 适配器再映射为对应 API 参数；四个阶段在创建子 Run 前统一校验该总额不超过模型最大输出和扣除估算输入后的剩余上下文。Schema 的集合和文本字段必须声明数量与长度上限，避免合法但无界的结构化响应耗尽输出预算。子 Run 和重试只能读取该任务的冻结值。后台修改价格记录、路由或小说设置不得改变已存在批次，v3 批次继续按原单路由快照及升级前请求上限执行。运行详情必须分别显示冻结路由、静态模型容量、实际请求预算、容量门禁快照、Provider 适配器实际发送的非敏感参数、`reasoning_tokens` 和完成预算分类，不能用计划值冒充已发送参数。确定性的 Skeleton Assembly、Chapter Assembly 与 Outline Finalize 不配置模型。页面不得回显 API Key，日志不得记录密钥明文或密文。专用环境路由只作为同名数据库路由缺失时的兼容来源，但仍必须匹配已启用且适用于 Outline 的模型价格记录；成本硬限制继续由全局 config 和小说设置控制。

达到 hard limit：

```text
不再创建新的模型请求
```

---

# 32. 日志与审计

单用户无需复杂 Audit 系统。

MVP 使用：

```text
Laravel Log
+
generation_runs
+
usage_records
```

对以下高风险操作增加简单操作记录即可：

```text
事实锁定
Bible 修改
Canonical Rollback
Ending Contract 修改
手工 Override
```

如果已有 activitylog，可复用。

不建立企业级防篡改 Audit Infrastructure。

---

# 33. Rollback

MVP 仅支持：

```text
回滚最新 Canonical Chapter
```

流程：

```text
Latest Chapter
     ↓
Mark Superseded
     ↓
Rebuild Story State
     ↓
Invalidate Memories
     ↓
Restore Novel Pointer
```

不实现任意历史分支。

---

## 33.1 内容删除

章节删除采用 **Tail Truncation**：删除第 N 章等于删除第 N 章及全部 `sequence >= N` 的章节和派生数据。操作前必须暂停小说、确认没有 `queued/running` Run、预览影响范围并记录原因；事务中恢复 N-1 的 Canonical 指针，删除范围内的 Event、State Version、Fact、Memory、Review、Artifact、Run、Usage、Scene、Plan 和 Chapter，再从剩余 Active Canonical Events 重建人物/世界/伏笔及 Milestone/Beat/Arc Progress。不得只删除中间一章而保留依赖它的后续正式章节。

小说删除采用完整物理删除。操作前必须暂停小说、确认没有活动 Run、输入完整标题并预览各表数量；在一个事务中按依赖顺序删除该 Novel 的全部业务记录，删除后校验所有直接或间接引用为零。操作日志只保留小说 ID、标题、执行人、原因和计数，不保存正文、Prompt 或密钥。

上述删除能力已由 NGC-008/NGC-009 实现，统一通过领域 Action 执行；Filament 只负责影响预览、确认文本和原因收集。

---

# 34. 性能目标

单人项目不追求高并发。

目标：

```text
Filament 页面 P95 < 2s

一个 Novel 连续稳定生成

不同 Novel 可并行

单 Novel Commit 串行
```

优先：

```text
稳定
>
正确
>
可恢复
>
吞吐
```

---

# 35. MVP 发布门槛

必须完成：

### 生成

```text
连续生成 ≥100 章
```

无：

```text
重复正式章节
状态无法恢复
章节跳号
```

---

### Story State

已知 Hard Conflict：

```text
100% 阻断
```

---

### Recovery

模拟：

```text
模型 Timeout
Queue Retry
PHP Worker Crash
Redis Restart
Embedding Failure
```

系统仍能恢复。

---

### Memory

测试：

```text
早期关键事件
早期伏笔
人物知识边界
同名实体
```

能够合理召回。

---

### Cost

所有 AI 请求能追踪到：

```text
Novel
Chapter
Run
Model
Token
Cost
```

---

### Ending

存在 critical Closure Debt：

```text
禁止 completed
```

---

# 36. 实施顺序

## Phase 1 — Foundation

```text
novels
novel_bibles
volumes
story_arcs
chapters
characters
world_entities
foreshadowings
```

---

## Phase 2 — Story State

```text
facts
story_events
story_state_versions

StoryStateService
StoryEventExtractor
StateValidator
```

---

## Phase 3 — Planning

```text
chapter_plans
scenes

ChapterPlanner
```

---

## Phase 4 — AI Gateway

```text
AiProvider
AiRequest
AiResponse
usage_records
```

---

## Phase 5 — Generation

```text
generation_runs
generation_artifacts

GenerateScene
AssembleChapter
```

---

## Phase 6 — Review

```text
reviews
Reviewer
Rewrite
ReviewGate
```

---

## Phase 7 — Canonical Commit

```text
CanonicalCommitService

Transaction
CAS
Idempotency
```

---

## Phase 8 — Memory

```text
memories
pgvector
ContextBuilder
Hybrid Retrieval
```

---

## Phase 9 — Filament

```text
Novel
Planning
Generation
Review
Memory
Settings
```

---

## Phase 10 — Ending / Recovery

```text
Pause
Resume
Rollback Latest Chapter
Closing Horizon
Closure Debt
Ending Audit
```

---

# 37. 建议 Laravel 代码结构

保持简单：

```text
app/

├── Actions/
│   ├── Generation/
│   ├── Story/
│   └── Memory/
│
├── Ai/
│   ├── Contracts/
│   ├── Providers/
│   └── DTOs/
│
├── Enums/
│
├── Jobs/
│
├── Models/
│
├── Services/
│   ├── ContextBuilder.php
│   ├── StoryStateService.php
│   ├── ReviewService.php
│   ├── CanonicalCommitService.php
│   └── EndingService.php
│
└── Filament/
```

不强制建立：

```text
Repository Layer
Hexagonal Architecture
CQRS Framework
Event Sourcing Framework
```

Story Event 是产品业务数据，不等于需要引入完整 Event Sourcing 框架。

---

# 38. Codex / Agent 工程规则

项目根目录建议建立：

```text
AGENTS.md
```

核心规则：

```text
This project is developed and operated by one person.

Prefer the simplest implementation that satisfies the requirements.

Do not introduce infrastructure or abstractions for hypothetical future needs.

Prefer:
Laravel
PostgreSQL
Redis
Laravel Queue
Filament.

Do not add:
multi-tenancy
complex RBAC
multi-user approval workflows
microservices
Kafka
RabbitMQ
Neo4j
Elasticsearch
Kubernetes
distributed workflow engines

unless explicitly requested.
```

但以下能力不得为了“简单”而删除：

```text
Canonical Story State
Story Events
Generation Runs
Context Snapshot
Review Gate
Idempotent Commit
Failure Recovery
Ending Control
```

---

# 39. 开发决策原则

出现两种都可行的方案时：

选择：

```text
表更少
代码更少
依赖更少
状态更少
部署更简单
Debug 更容易
恢复更容易
```

而不是：

```text
架构看起来更高级
```

---

# 40. MVP 的真正目标

MVP 不以：

```text
功能数量
后台页面数量
架构复杂度
```

衡量。

而以：

```text
能不能稳定生成 100 章
```

为第一阶段目标。

然后：

```text
100章
 ↓
300章
 ↓
500章
 ↓
100万字
 ↓
完整完结
```

每个阶段发现真实问题，再增加复杂度。

---

# 41. 项目核心竞争力

整个系统最值得持续打磨的是：

## Story State

回答：

```text
故事现在到底是什么状态？
```

---

## Context Builder

回答：

```text
写下一章时，AI 应该知道什么？
```

---

## Review

回答：

```text
这一章有没有把前文写崩？
```

---

## Story Planner

回答：

```text
这一章为什么存在？
```

---

## Ending Controller

回答：

```text
小说怎么收回来并真正结束？
```

---

# 42. 暂缓功能清单

以下功能只有出现明确需求后才开发：

```text
多 Provider 自动路由

Scene 并行生成

关系独立表

时间线独立表

完整 Event Projection

Prompt CMS

多版本小说 Branch

完整历史 Rollback

知识图谱

多人协作

多租户

复杂权限

平台发布

自动运营
```

---

# 43. 最终架构

```text
                    Filament
                       │
                       ▼
                    Laravel
                       │
       ┌───────────────┼────────────────┐
       │               │                │
       ▼               ▼                ▼
 Story Planner    Generation      Review Engine
       │               │                │
       └───────────────┼────────────────┘
                       ▼
                  Story Engine
                       │
              ┌────────┴────────┐
              ▼                 ▼
         PostgreSQL           Redis
              │
           pgvector
```

AI Provider：

```text
Laravel
   │
   ▼
AiProvider Interface
   │
   ▼
Current LLM Provider
```

---

# 44. 产品最终定位

本项目不是：

> 面向多人团队的 AI 小说 SaaS 平台。

而是：

> 一个由单人即可长期维护和运营，能够稳定规划、生成、审校、记忆、恢复并最终完成百万字网络小说的 AI 写作系统。

因此所有未来需求都必须首先回答：

```text
它是否提升长篇小说的：

一致性？
剧情推进？
上下文质量？
可恢复性？
完结能力？
成本控制？
```

如果答案都不是：

```text
默认不做。
```
