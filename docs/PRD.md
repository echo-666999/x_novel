# AI 长篇网络小说自动生成平台 PRD v1.1（单人精简版）

> 面向单人开发、单人运营的百万字级网络小说自动生成系统。  
> 核心目标不是建设复杂 SaaS 平台，而是构建一个稳定、可控、可恢复、可完结的 AI 长篇小说生产工具。

---

## 0. 文档信息

| 字段 | 内容 |
|---|---|
| 文档版本 | v1.1 Solo Edition |
| 基线来源 | PRD v1.0 |
| 产品形态 | 单用户、单租户、单体应用 |
| 开发/运营方式 | 单人开发、单人使用 |
| 技术栈 | Laravel + Filament + PostgreSQL/pgvector + Redis + Laravel Queue |
| 核心目标 | 小而精、低运维成本、长期可维护 |
| 非目标 | SaaS、多租户、多人协作、复杂审批、微服务化 |
| 状态 | 可进入数据模型与工程设计 |

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
Current Novel Outline（用户确认的 Volume → Arc → Beat 顺序）
  ↓
Volume
  ↓
Story Arc
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

人工确认的 Current Novel Outline 是 Chapter Planning 的上游约束。AI 可以生成候选大纲、拆分当前 Beat、决定场景表达和建议一个 Beat 需要几章，但不能拥有节点排序、主线切换、跳过节点或删除节点的决定权。Laravel 必须选择顺序最早的未完成 Main Beat，并把该精确 Outline Version 和 Primary Beat 冻结到 Chapter Plan。

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
Volume
    ↓
Story Arc
    ↓
Chapter Plan
    ↓
Context Builder
    ↓
Scene Generation
    ↓
Chapter Assembly
    ↓
Review
    ↓
Rewrite（必要时）
    ↓
Review PASS
    ↓
auto_commit 开启时自动派发；否则用户确认提交
    ↓
Canonical Commit
    ↓
Story State Update
    ↓
Memory Update
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

> MVP 控制在约 18 张核心业务表。

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

`style_profile` 使用可空 JSONB：新创建的 Bible Version 必须保存通过结构校验的完整对象，历史版本允许为 `null`，不得通过默认值伪造旧文风。现有小说必须创建新 Bible Version 完成迁移。

---

# 9.2 Story Structure

### novel_outlines

`novel_outlines` 保存用户确认前后的完整全书大纲版本。它是章节规划的权威来源，但不是已经发生的故事事实；Canonical Story State 与 Active Story Events 仍是正式故事事实来源。

```text
id
novel_id
version
status draft | current | superseded
source ai | manual | revision
schema_version
content JSONB
checksum char(64)
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

`novels.current_outline_id` 指向当前采用版本。更换 Current Outline 必须在锁定 Novel 的事务中完成，并保留旧版本为 `superseded`。AI 生成结果只能先成为 Artifact 或 Draft Outline；用户确认采用前不得写入正式 Volume、Story Arc、Character、World Entity 或 Canonical Story State。

`content` 固定为 `Volume → Story Arc → Beat`。Volume、Arc、Beat 的 `key` 在同一 Outline 内唯一，版本创建后不可原地改键；每层 `sequence` 从 1 开始、连续且不重复。每个 Main Arc 至少有一个 Beat，每个 Beat 至少包含：

```text
key
sequence
title
summary
chapter_budget { min, max }
acceptance_criteria[]
must_include[]
must_not_include[]
character_candidates[]
world_entity_candidates[]
```

`chapter_budget.min >= 1`，非空 `max >= min`；`acceptance_criteria` 不得为空；同一文本不得同时出现在 `must_include` 与 `must_not_include`。Candidate 使用 Outline 内唯一的稳定 `candidate_key`，只表达未来正文可能引入的对象，不会在保存或采用大纲时自动成为正式人物或世界实体。

旧小说迁移可以在 Outline Version 中保存经人工确认的 `baseline_completions`，每项记录 `beat_key`、Canonical `chapter_ids`、逐字 `evidence`、`reason`、`confirmed_by` 和 `confirmed_at`。历史基线只决定新 Outline 从哪个 Beat 继续，不创建 `story_arc_beat_completed`，不修改历史 Story Event、State Version 或 Arc Progress，UI 必须明确标识为“历史基线”。新 Outline 生效后的 Beat 只能由 Review PASS 后的 Canonical Commit 正式完成。

### volumes

```text
id
novel_id
outline_key nullable
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
unique(novel_id, outline_key) WHERE outline_key IS NOT NULL
```

---

### story_arcs

```text
id
novel_id
volume_id nullable
outline_key nullable
sequence
type
title
goal
stakes
beats JSONB
completion_conditions JSONB
progress
status
created_at
updated_at
```

`story_arcs.progress` 只由 Canonical Chapter 中经过验证的结构化 Beat 完成记录投影计算。Chapter Plan、Scene Draft、Review 或 Rewrite 只能保存候选贡献，不得直接推进 Arc；最新 Canonical Chapter 回滚时必须用剩余 Canonical 记录重算进度。

Story Arc 的 Outline 投影约束为 `unique(novel_id, outline_key) WHERE outline_key IS NOT NULL` 与 `unique(volume_id, sequence)`。

新 Outline 的 `beats` 使用结构化 Beat 对象。旧字符串 Beat 只做兼容读取：保留原文本，继续用现有 `StoryArcBeatContract` 生成相同 Key，`sequence` 使用原数组位置，预算默认为 `{min: 1, max: null}`，验收条件暂用原 Beat 文本。兼容迁移不得改变已有 Canonical Event 的 `beat_key`。

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
novel_outline_id nullable
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

status
created_at
updated_at
```

`due_foreshadowings` 只保留历史整数 ID；新版本 Plan 使用 `foreshadowing_actions`。人工编辑保存为新的 Plan Version，旧版本不得原地覆盖。

`arc_contributions` 使用 `arc_id + beat_key + beat_index + role` 引用冻结 Outline 中的真实 Beat，并记录目标 Scene 与验收条件；每个新 Plan 必须恰有一个 `role=primary`，Secondary 只能推进获准支线，不能替代或提前完成后续 Main Beat。`character_candidates` 与 `world_entity_candidates` 保存稳定临时键、名称或类型、描述、去重依据、潜在重复对象、引入理由与目标 Scene。三者在 Review PASS 前都只是 Draft 契约。

Chapter Plan 的 `novel_outline_id` 必须冻结当时采用的 Outline Version。Generation Run 的 Context Snapshot 同时记录 Outline ID、Version、Checksum、Primary Arc/Beat、已完成 Beat Keys、当前 Beat 已使用的 Canonical Chapter 数和章节预算，后续大纲修订不得把旧 Plan 静默改挂到新版本。

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

共 18 张：

```text
1  novels
2  novel_bibles

3  volumes
4  story_arcs
5  chapters
6  chapter_plans
7  scenes

8  characters
9  world_entities
10 foreshadowings

11 story_events
12 story_state_versions
13 facts

14 generation_runs
15 generation_artifacts
16 reviews

17 memories
18 usage_records
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
整章规划
   ↓
逐 Scene 生成
   ↓
Scene 临时接受
   ↓
整章 Assembly
   ↓
Review
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
↓
整章
```

禁止无差别整章重写。

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

Review 的 `findings` 是七维问题集合的权威来源，`dimension_audits.*.status` 由 Laravel 根据最终 Findings 确定性派生。原始状态与归一化状态都必须保留；状态声称有问题但没有 Finding 时，只允许执行一次同 Draft、State、Bible 和 Prompt 来源链的单维结构修复。结构修复不消耗正文 Rewrite 配额，失败进入 NEEDS_ATTENTION 并保留 `ai_request_log_id`，不得把 Chapter 标为 blocked。

普通 warning 按分数和可执行性分流：总分达到 `review_pass_score` 且没有其他门禁时允许 PASS，并保留为非阻塞建议；总分未达标且 warning 可执行时进入 REWRITE；auto-fixable error 进入 REWRITE；真正需要用户选择、没有安全修复路径或 Rewrite 耗尽时进入 NEEDS_ATTENTION。Hard Conflict 仍无条件 BLOCK。

每轮 Review 必须先完成全部七个质量维度的全量检查，再一次性返回当前正文中所有有明确证据的问题；不得发现一个问题后提前结束或把同一根因拆到后续轮次。Rewrite 必须把同一 Review 的全部可修复问题作为一个批次一次解决，并在返回前再次全量检查，避免修复旧问题时保留或引入同类问题。

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
RollupSummaryJob
EndingAuditJob
```

以后出现拥堵再拆 Queue。

---

# 19. Job 流水线

```text
PlanChapterJob
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
ReviewChapterJob
        ↓
   ├ REWRITE → RewriteChapterJob → Review
   ├ NEEDS_ATTENTION / BLOCK → 停止
   └ PASS → 按 novel.settings.auto_commit 判断
              ├ false → 停止并等待用户确认
              └ true  → 安全派发
                         ↓
                    CommitChapterJob
              ↓
         UpdateMemoryJob
              ↓
         RollupSummaryJob
```

Context Builder MVP 可以作为 Service：

```text
ContextBuilder
```

不必单独建立 Queue Job。

Chapter Plan 之后的 Scene Writer、Assembler、Story Event Extractor、Reviewer 和 Rewriter 必须读取同一份冻结伏笔动作契约。契约记录完整伏笔定义、promised payoff、Canonical 优先的内容状态、时限、兑现窗口、重要度、所属 Arc、本章动作、目标 Scene、验收条件、最近有效事件证据，以及 Bible/State/Plan 版本和 checksum。只有结构化 `foreshadowing_actions` 中的伏笔具有本章处理权限；未选中的未来伏笔不得被模型绑定为本章伏笔事件。该契约属于强制上下文，Token 紧张时先裁剪近期摘要和长期 Memory，不能删除 Critical、Due 或 Overdue 契约。

Scene Writer 必须按目标 Scene 返回契约中每条伏笔动作的 `fulfilled / missing / contradicted` Coverage。`fulfilled` 与 `contradicted` 的 evidence 必须逐字来自当前正文，`missing` 的 evidence 必须为 `null`；Laravel 必须校验伏笔 ID、动作、顺序、Scene 归属和原文引用。只有正文具体结果满足该动作的 `acceptance_criteria` 才能报告 fulfilled；只有主题或意象相近、但没有动作结果时必须报告 missing。缺失或冲突形成可自动 Rewrite 的 Finding，不得修改 Canonical State。

Assembler 必须按 Scene 汇总并重新验证伏笔 Coverage。它不得把 Scene Draft 已报告为 missing/contradicted 的动作提升为 fulfilled，也不得靠组装新增动作结果；若润色删除了唯一有效证据，最终 Coverage 必须降为 missing/contradicted 并形成 Finding。跨多个 Scene 的动作仍按各目标 Scene 保存 Coverage，由 Chapter Draft 聚合后交给后续 Event Extraction 和 Review 判断整体是否满足契约。

Story Event Extractor 只能为冻结契约中已授权且最终 Coverage 为 fulfilled 的伏笔动作生成候选事件。事件类型必须与动作一一对应，事件 evidence 必须覆盖该动作的 Coverage 原文并引用目标 Scene；未选中伏笔、未来伏笔、missing/contradicted 动作、动作类型不匹配和主题相似内容都不得生成伏笔事件。`defer` 不产生正文 Story Event；`abandon` 只有在冻结契约包含与 State Version 一致的人工授权时才能生成事件候选。

伏笔候选事件必须按正文和动作契约顺序执行确定性生命周期校验：`idea → planted`、`planted/reinforced → reinforced`、`planted/reinforced → paid_off`，同章允许依次 `plant → reinforce` 或 `plant → pay_off`。除尚未进入正文的新 `idea` 可以从领域记录开始铺设外，已有 `planted/reinforced/terminal` 状态必须来自冻结 Canonical Story State，不能只凭可能漂移的领域投影生成事件。`paid_off/abandoned` 终态不能被生成事件重新开启。Extractor 在保存 Event Candidate 前执行该规则，StateValidator 再使用 Event Candidate Run 中冻结的契约独立复核；失败只产生候选校验错误或 Hard Finding，不修改 Canonical State。

Reviewer 必须在首轮全量审校中按冻结 Plan 顺序逐项输出伏笔审计，同时对照 promised payoff、动作语义、最终 Coverage、当前草稿对应的 Event Candidate 和正文逐字证据。仅出现相同关键词不能证明 `reinforce` 或 `pay_off`；`pay_off` 必须在语义上完成承诺及验收条件。模型报告 fulfilled 时，Laravel 仍须确认存在 fulfilled Coverage 和匹配事件。正文可修复的问题按伏笔 ID、动作和目标 Scene 合并为一个 Finding，进入一次包含全部修复目标的 Rewrite；需要延期、放弃、改变窗口或 promised payoff 的问题进入 NEEDS_ATTENTION，Rewrite 不得修改伏笔定义或 Canonical State。

整章 Rewrite 必须返回并重新验证全部 Scene Coverage；局部 Scene Rewrite 必须经 Assembly 重新汇总 Coverage。任何 Rewrite 产物都先使旧 Event Candidate 和 State Patch 失效，再依次重新执行 Event Extraction、State Patch 和全量 Review。只有重写稿对应的新 Review PASS 才能停止自动流程，历史 Review、Coverage 或 Event Candidate 不得跨草稿复用。

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

创建 story_state_versions N+1

更新 chapter.status

更新 novels.canonical_state_version_id

COMMIT
```

必须：

- Transaction；
- Unique Constraint；
- Idempotency Key；
- Optimistic Lock / CAS。

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

显示：

```text
当前 Novel
当前 Volume
当前 Chapter

总字数
章节数

最近生成状态
Review 通过率
Rewrite 比例

伏笔到期
Closure Debt

今日 Token
今日 Cost

Queue 状态
```

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

Filament “AI 与成本”只提供“供应商连接”和“模型价格”两个导航入口。连接保存 Provider、Base URL、加密 API Key、Timeout 与启用状态；价格按 Provider + Model + Currency 保存计费单位及输入、缓存输入、输出价格。“模型价格”页面同时维护各 AI Stage 的 Provider + Model 路由，并写入独立的 `ai_model_routes` 表。页面不得回显 API Key，日志不得记录密钥明文或密文。环境模型配置只作为数据库路由缺失时的兼容回退，成本硬限制继续由全局 config 和小说设置控制。

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
