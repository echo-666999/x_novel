# AGENTS.md

# AI Long-Form Fiction Platform

> NGC-005 实施基线。关系化 Outline、Chapter Plan 完整父链、Canonical Milestone Progress，以及 Review/Event/Commit 完成语义已经实现；不迁移、不 Backfill 旧数据。OGR-002 已实现 Outline 主批次生命周期与 Resume；OGR-003 已实现 Structure / Arc Beats Artifact 枚举、Strict Schema、校验与 PostgreSQL CHECK；OGR-004 已接通新版 Job、确定性 Skeleton Assembly 与完整 Blueprint lineage。旧 Provider Skeleton 只保留给 v2 批次兼容。

本项目是一个由单人开发、单人使用、单人维护的 AI 长篇网络小说自动生成系统。

目标不是构建复杂 SaaS，而是构建一个：

> 小而精、稳定、可恢复、可追踪，能够持续生成并最终完成百万字级网络小说的 AI 写作系统。

---

## 1. Tech Stack

核心技术栈：

```text
Laravel
Filament
PostgreSQL
pgvector
Redis
Laravel Queue
Laravel Horizon
LLM Provider API
```

默认保持 Laravel 单体架构。

---

## 2. Source of Truth

开发前按以下顺序阅读：

```text
1. AGENTS.md
2. docs/PRD.md
3. docs/architecture/*.md
4. docs/development/*.md
5. Existing Code
```

当前核心架构文档：

```text
docs/architecture/data-model.md
```

后续预计：

```text
docs/architecture/story-engine.md
docs/architecture/generation-pipeline.md
docs/architecture/memory-context.md
```

如果代码与文档冲突：

不要静默选择其中一个。

先报告：

```text
Conflict
Document Says
Current Code Does
Impact
Recommended Resolution
```

---

## 3. Simplicity First

本项目由一个人长期维护。

当两个方案都能满足需求时，优先选择：

```text
更少代码
更少表
更少状态
更少抽象
更少依赖
更容易 Debug
更容易测试
更容易恢复
更低维护成本
```

不要为了假设中的未来需求提前增加复杂度。

---

## 4. Do Not Introduce

除非明确要求，否则不要引入：

```text
Multi-tenancy
Complex RBAC
Multi-user Approval Workflow
Real-time Collaboration

Microservices
Kafka
RabbitMQ

Neo4j
Elasticsearch
Separate Vector Database

CQRS Framework
Event Sourcing Framework
Distributed Workflow Engine

Kubernetes
Complex Repository Layer
```

不要提前为未来 SaaS 或团队协作做完整设计。

---

## 5. Complexity That Must Be Preserved

以下能力属于产品核心，不得为了“简单”而删除：

```text
Canonical Story State
Story Events
Generation Runs
Generation Artifacts
Context Snapshot
Review Gate
Canonical Commit
Idempotency
State Version Check
Failure Recovery
Long-term Memory
Ending Control
```

---

## 6. Core Workflow

权威生成流程：

```text
Novel Bible
    ↓
Current Outline Version
    ↓
Volume → Arc → Beat → Milestone
    ↓
Chapter Plan
    ↓
Context Builder
    ↓
Scene Generation
    ↓
Deterministic Chapter Assembly
    ↓
Event Extraction / State Validation
    ↓
Compact Review / Paragraph or Scene Rewrite if needed
    ↓
Review PASS
    ↓
Novel policy controlled Canonical Commit
    ↓
Story State Update
    ↓
Memory Update
```

不得绕过：

```text
Review
↓
Canonical Commit
```

直接将 AI Draft 写成正式故事状态。

---

## 7. PostgreSQL Is the Source of Truth

PostgreSQL 是唯一权威业务数据库。

Redis 仅用于：

```text
Queue
Cache
Lock
Rate Limit
Temporary Progress
```

Redis 不得成为以下数据的唯一副本：

```text
Canonical Chapter
Story State
Story Events
Facts
Foreshadowing State
Generation Results
Usage / Cost
```

Redis 丢失后，系统应该能够从 PostgreSQL 恢复。

---

## 8. Draft / Canonical Isolation

Draft 永远不得直接修改：

```text
Canonical Story State
Facts
Character Canonical State
World Canonical State
Timeline
Foreshadowing Progress
Story Arc Progress
Formal World Entities
Novel Canonical Pointer
```

只有：

```text
Review PASS
↓
`auto_commit=true` 的安全自动派发，或用户确认“提交正式章节”
↓
CanonicalCommitService
```

才能更新正式状态。

`story_arcs.progress` 是 Canonical 投影：只从已提交章节的 Active Milestone/Beat Completion Events 计算。Plan、Draft、Review 和 Rewrite 只能携带候选贡献；回滚最新正式章节时必须从剩余 Active Canonical Events 重算，不能递减一个猜测值。

正文中新出现的世界实体在 PASS 前只能保存为 Candidate。只有 `CanonicalCommitService` 可以在同一事务中验证引用并创建正式 `world_entities`。类型覆盖用于提示缺口，不构成“所有类型必须非空”的约束。

`chapter_plans.arc_contributions` 与 `chapter_plans.world_entity_candidates` 使用 JSONB 承载多项冻结契约，避免新增关系表。`chapters.canonical_metadata` 只固化已经验收并提交的 Beat、Completion Condition 与 Entity 来源；`world_entities.source_chapter_id/source_candidate_key` 提供幂等创建和 Latest Chapter Rollback 来源。

### 8.1 Foreshadowing 的权威状态与窗口

`story_state_versions.state.foreshadowings` 与 Active Story Events 是正式伏笔进度的权威来源；`foreshadowings` 表是用于编辑、查询和 UI 的领域投影。发生冲突时以 Canonical Story State 为准，并把表记录标记为投影漂移，不得用表值反向覆盖正式状态。

`foreshadowings.status` 只允许表达内容生命周期：

```text
idea
planted
reinforced
paid_off
abandoned
```

`due_from_chapter`、`due_to_chapter` 是包含首尾的兑现窗口。令 `C` 为最新 Canonical 章节序号、`N=C+1`，则非终态伏笔按 `N < due_from`、`due_from <= N <= due_to`、`N > due_to` 分别计算为 `upcoming / due / overdue`。它不是持久化内容状态；terminal 记录不再参与到期或逾期提醒。活跃草稿不得推进 `C`。

`foreshadowings.management_history` 是低频 JSONB 管理审计，只记录不产生正文 Story Event 的人工元数据操作。当前用于保存延期的原因、旧/新窗口、操作时 Canonical 章节、State Version、操作者和时间。人工放弃会改变 Canonical 内容状态，因此通过 `ManualCorrection` Event 和新 State Version 留痕，不写成虚构的正文伏笔事件。该审计历史不能覆盖 Canonical State 或 Active Story Events。

现有枚举、测试或数据中的 `due` 是兼容迁移对象。实施分离状态时必须先保证旧值可读、可报告，再迁移为其真实内容状态；迁移程序不能在没有 Active Events、Canonical State 或人工证据时猜测应为 `idea`、`planted` 或 `reinforced`。

领域投影至少应能从 Canonical State 恢复：

```text
status
reinforce_count
setup_chapter_id
payoff_chapter_id
```

时限状态必须按上述公式实时计算，不另存为 Canonical 内容事实，也不得把它重新混入 `status`。投影刷新失败不回滚已经成功的 Canonical Commit，必须可检测、重试和重建。

---

## 9. Story State

必须区分：

```text
Story Event
```

与：

```text
Story State
```

Story Event：

> 正式故事中发生过什么。

Story State：

> 截至当前正式章节结束，故事世界现在是什么状态。

MVP 使用：

```text
story_events
story_state_versions
facts
```

实现。

`story_state_versions.version` 必须满足 `version >= 0`。新小说的版本 `0` 应是进入生成前建立的初始完整状态快照；恢复校验仍必须逐项验证必要 Domain、`schema_version`、无章节来源和 checksum，不能因为版本号为 `0` 就假定历史数据完整。每部小说的版本号唯一：`unique(novel_id, version)`。

不要引入完整 Event Sourcing Framework。

---

## 10. Facts

`facts` 只保存真正需要：

```text
精确查询
锁定
Hard Validation
```

的事实。

例如：

```text
角色已经死亡
角色不会游泳
角色不知道某个秘密
某件物品属于某人
某条世界规则被锁定
```

普通剧情信息优先存在：

```text
Story Events
Story State
Memory
```

---

## 11. Locked Facts

人工锁定事实优先于模型输出。

如果 LLM 与 Locked Fact 冲突：

```text
BLOCK
```

或：

```text
NEEDS_ATTENTION
```

不得让模型自动覆盖 Locked Fact。

---

## 12. pgvector

pgvector 用于：

```text
Long-term Memory Retrieval
```

不是事实数据库。

事实优先级：

```text
Bible / Hard Constraints
        ↓
Locked Facts
        ↓
Current Story State
        ↓
Canonical Story Events
        ↓
Structured Domain Data
        ↓
Vector Memory
```

Vector Memory 永远不能覆盖前面的权威事实。

---

## 13. Context Builder

正文生成前的 Context 优先级：

```text
1. System / Output Schema
2. Bible Hard Constraints
3. Ending Contract
4. Volume / Arc / Chapter Plan
5. Current Story State
6. Relevant Characters
7. Relevant World Entities
8. Due Foreshadowings
9. Required / Forbidden Facts
10. Recent Chapter Summaries
11. Previous Scene Tail
12. Long-term Memory RAG
13. Task Instruction
```

Token 不足时：

优先减少低价值长期 Memory。

不要删除：

```text
Hard Constraints
Current State
Required Facts
Forbidden Facts
Ending Constraints
```

---

## 14. Context Snapshot

重要模型调用必须记录：

```text
bible_version
state_version
chapter_plan_id
novel_outline_id
outline_version
outline_checksum
primary_arc_id
primary_beat_id
primary_milestone_id
handoff_next_beat_id
canonical_completed_beat_ids
canonical_completed_milestone_ids
chapters_used_for_current_beat
chapter_budget_min / chapter_budget_max
character_ids
world_entity_ids
foreshadowing_ids
fact_ids
memory_ids
recent_chapter_ids
prompt_version
model
token_budget
```

MVP 保存于：

```text
generation_runs.context_snapshot
```

目标是能够解释：

> AI 为什么会生成这一段内容。

---

## 15. AI Output Is Untrusted

所有 LLM 输出必须经过：

```text
Schema Validation
        ↓
Reference Validation
        ↓
State Version Validation
        ↓
Locked Fact Validation
        ↓
Business Rule Validation
```

禁止：

```php
Model::create($llmJson);
```

直接将模型结果写入 Canonical Data。

---

## 16. LLM vs Laravel

LLM 负责：

```text
Planning
Creative Writing
Semantic Review
Event Extraction
Summary
```

Laravel 负责：

```text
Workflow
State Machine
Validation
Persistence
Transaction
Retry
Budget
Lock
Idempotency
Permissions
```

原则：

> LLM 提建议，Laravel 做决定。

---

## 17. Review Gate

Review 维度：

```text
事实 / 连续性        25
计划遵循             15
人物一致性           15
剧情推进             15
重复度               10
节奏 / 悬念          10
文风 / 可读性        10
```

Decision：

```text
PASS
REWRITE
NEEDS_ATTENTION
BLOCK
```

Hard Conflict 优先于总分。

即使总分很高：

只要存在 Hard Conflict，就不能 PASS。

---

## 18. Rewrite

默认最大自动 Rewrite：

```text
2 attempts
```

优先修复：

```text
Paragraph
↓
Scene
```

自动路径禁止 Whole Chapter Rewrite。多 Scene 连续性从最早受影响 Scene 有限级联；章功能、Milestone/Handoff 或关键结果问题返回 Plan 修订，无法安全定位时进入 `NEEDS_ATTENTION`。

每次 Rewrite 必须创建新的 Artifact。

禁止覆盖旧版本。

---

## 19. Canonical Commit

所有正式提交统一通过：

```text
CanonicalCommitService
```

禁止：

```text
Controller
Filament Resource
Model Observer
Queue Job
Listener
```

自行实现正式提交逻辑。

Job 可以调用 `CanonicalCommitService`。

---

## 20. Canonical Commit Transaction

核心事务：

```text
BEGIN

SELECT novel FOR UPDATE

Validate expected state version

Validate chapter is not canonical

Validate Review = PASS

Validate idempotency

Create / select canonical artifact

Write story events

Create story state version N+1

Update chapter

Update novel pointers

COMMIT
```

必须保证：

```text
All or Nothing
```

以及：

```text
Exactly-once Effect
```

---

## 21. Concurrency

允许：

```text
Novel A
+
Novel B
```

并行生成。

同一个 Novel：

```text
Chapter Workflow
```

MVP 默认串行。

Canonical Commit：

```text
必须串行
```

不要提前实现复杂 Scene Parallel Generation。

---

## 22. Locking

最终一致性优先依赖：

```text
PostgreSQL Transaction
SELECT ... FOR UPDATE
State Version / CAS
Unique Constraint
```

Redis Lock 可以减少重复任务：

但不是最终一致性保障。

---

## 23. Idempotency

以下操作必须考虑重复执行：

```text
Planning
Generation
Review
Rewrite
Canonical Commit
Memory Update
Embedding
```

每个 Queue Job 都必须回答：

> 如果执行两次会怎样？

不得产生：

```text
重复 Canonical Chapter
重复 Story Event
重复 State Version
重复收费记录
```

---

## 24. Generation Runs

重要 AI / Workflow 阶段使用：

```text
generation_runs
```

记录：

```text
Input
Stage
Status
Attempt
Idempotency Key
Input Hash
Context Snapshot
Provider
Model
Error
Timing
```

用于：

```text
Debug
Retry
Resume
Cost Tracking
Reproduction
```

---

## 25. Generation Artifacts

统一使用：

```text
generation_artifacts
```

保存：

```text
outline_foundation
outline_structure
outline_arc_beats
outline_skeleton
outline_beat_detail
outline_blueprint
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

Outline Artifact 语义：

```text
outline_foundation  Provider 生成的 Bible / 初始领域候选
outline_structure   Provider 生成的 Volume / Arc 结构，不含 Beat
outline_arc_beats   Provider 按单个 Arc 生成的 Beat 集合
outline_skeleton    Laravel 按 Structure 与全部 Arc Beats 确定性合并
outline_beat_detail Provider 按单个 Main Beat 生成 Milestones / Handoff
outline_blueprint   Laravel Finalize 校验后的完整候选来源包
```

Artifact 均不可变。`outline_skeleton` 与 `outline_blueprint` 必须保存有序来源 ID、checksum 和算法版本，且不产生 AI Request Log 或 Usage Record。Structure、全部 Arc Beats、Skeleton 和全部 Beat Detail 必须属于同一 Novel、同一主批次；Finalize 不能按“最新 Artifact”猜测来源。

Artifact 原则：

```text
Immutable
```

修改即创建新版本。

---

## 26. Queue

MVP 默认：

```text
generation
default
```

### generation

```text
GenerateNovelOutlineJob
GenerateNovelFoundationJob
GenerateNovelOutlineStructureJob
GenerateNovelArcBeatsJob
AssembleNovelOutlineSkeletonJob
GenerateNovelBeatDetailJob
FinalizeNovelOutlineJob
PlanChapterJob
GenerateSceneJob
AssembleChapterJob
ExtractStoryEventsJob
ReviewChapterJob
RewriteChapterJob
CommitChapterJob
```

### default

```text
UpdateMemoryJob
GenerateEmbeddingJob
GenerateCanonicalChapterSummaryJob
RefreshNovelProjectionJob
ContinueAutoGenerationJob
EndingAuditJob
```

只有真实 Queue 拥堵后再拆。

---

## 27. Queue Job Contract

每个重要 Job 必须明确：

```text
Input
Output
Idempotency Key
Retry Policy
Timeout
Failure Behavior
Persisted Artifact
Next Stage
```

不要创建一个什么都做的超大 Job。

也不要把简单流程拆成几十个无意义 Job。

---

## 28. Failure Recovery

阶段成功后必须持久化 Run / Artifact。

如果：

```text
input_hash 相同
+
Artifact 已成功
```

优先复用。

不要重复调用模型。

例如：

```text
Scene 1 success
Scene 2 success
Scene 3 timeout
```

恢复时：

从 Scene 3 继续。

---

## 29. Pause

Pause 后：

```text
停止创建新 Generation Stage
```

已发出的模型调用可以结束并保存 Artifact。

但：

```text
禁止 Canonical Commit
```

---

## 30. State Version Conflict

如果 Commit 时：

```text
expected_state_version != current_state_version
```

必须停止 Commit。

正确流程：

```text
Discard Candidate State Patch
↓
Rebuild Context
↓
Review Again
```

禁止覆盖新的 Canonical State。

---

## 31. Memory

正式 Memory 只能来自：

```text
Canonical Content
```

Draft / Rejected Draft：

不得创建正式长期 Memory。

Memory 应尽量：

```text
短
明确
可检索
有来源
```

并能够追踪：

```text
source_type
source_id
chapter
story event
```

---

## 32. Memory Retrieval

长期检索至少执行：

```text
novel_id filter
↓
status filter
↓
entity / type filter
↓
vector similarity
↓
salience
↓
deduplication
↓
token budget
```

近期剧情优先：

```text
SQL
+
Chapter Summary
+
Story Events
```

不要所有上下文都依赖 pgvector。

---

## 33. Ending Controller

Ending Controller 属于核心功能，不得删除。

必须支持：

```text
Ending Contract
Closing Horizon
Closure Debt
Ending Audit
```

进入 `completing` 后限制：

```text
新增核心人物
新增主线
新增硬世界规则
新增高重要度伏笔
```

存在 Critical Closure Debt：

```text
不得 completed
```

---

## 34. Rollback

MVP 只实现：

```text
Rollback Latest Canonical Chapter
```

暂不实现：

```text
Arbitrary History Rollback
Story Branch
Git-like Merge
```

Rollback 必须同步处理：

```text
Chapter
Story Events
Story State
Novel Pointer
Memory
Foreshadowing
```

---

## 35. Laravel Architecture

默认使用：

```text
Models
Enums
Actions
Services
DTOs
Jobs
Events
Policies
```

推荐核心 Service：

```text
ContextBuilder
StoryStateService
StateValidator
ReviewService
CanonicalCommitService
EndingService
```

不要为了形式创建大量空壳类。

---

## 36. Repository Rule

默认：

```text
不创建 Repository Layer
```

优先：

```text
Eloquent
```

复杂查询时：

可以使用 Query Object。

只有出现真实存储抽象需求才引入 Repository。

---

## 37. Model Observer Rule

禁止在 Model Observer 中执行：

```text
AI Request
Story State Update
Memory Update
Embedding
Canonical Commit
Queue Workflow
```

重要业务行为必须显式调用 Service / Action。

避免隐藏 Side Effect。

---

## 38. Database Rules

Migration 应尽可能使用数据库约束：

```text
Foreign Key
Unique
CHECK
NOT NULL
Transaction
```

不要只依赖 Laravel Validation。

PostgreSQL 特性可以正常使用：

```text
JSONB
Partial Index
FOR UPDATE
Advisory Lock
Full Text Search
pgvector
```

无需保持 MySQL Compatibility。

---

## 39. JSONB

JSONB 适合：

```text
State Snapshot
Context Snapshot
Model Structured Output
Extension Attributes
Low-frequency Complex Structures
```

如果数据需要：

```text
High-frequency Query
Sorting
Unique Constraint
Complex JOIN
```

优先拆成列或表。

不要凭感觉拆表。

---

## 40. Current Data Model

NGC-001 目标基线为 23 张核心表：现有 19 张基线增加 4 张版本化 Outline 子表，不新增独立 Handoff 表：

```text
novels
novel_bibles
novel_outlines
novel_outline_volumes
novel_outline_arcs
novel_outline_beats
novel_outline_milestones

volumes
story_arcs
chapters
chapter_plans
scenes

characters
world_entities
foreshadowings

story_events
story_state_versions
facts

generation_runs
generation_artifacts
reviews

memories
usage_records
```

详细定义以：

```text
docs/architecture/data-model.md
```

为准。

### 40.1 Novel Bible 的叙事与文风归属

Current Novel Bible Version 是章节叙事与文风的唯一权威来源。一个版本在语义上必须统一承载：

```text
tone
pov
tense
subgenre
target_platform
primary_style
secondary_styles
language_era
pacing
style_parameters
```

其中 `tone`、`pov`、`tense` 继续作为现有叙事基线；其余内容由 Bible 的可空 JSONB `style_profile` 承载。新创建的 Bible Version 必须保存通过结构校验的完整对象；历史版本允许为 `null`，不得用当前默认值伪造历史设置。

叙事与文风发生变化时必须创建新 Bible Version，不得原地修改历史版本。`novels.settings.editorial` 只允许在迁移窗口中用于迁移预览、冲突对照和数据复制；章节生成不得把它作为回退来源。迁移完成后应清理旧来源，但不得改写历史 Run、Artifact 或 Context Snapshot。

Foundation 发生在 Current Bible 创建前，可以生成完整 Bible 候选；用户采用并创建 Current Bible 后，Planner、Writer、Reviewer、Local Rewriter 和长度修复必须读取同一个冻结 Bible Version。Deterministic Assembler 不调用 Provider或读取文风 Prompt，只按 Scene Artifact 拼接。

### 40.2 Novel Outline、Milestone、Handoff 与 Candidate

人工确认的 Current Novel Outline 是 Chapter Planning 的上游权威。正式层级固定为 `Volume → Arc → Beat → Milestone → Chapter → Scene`；Canonical Story State 与 Active Story Events 是已经发生之故事事实和完成进度的权威。AI 只能生成候选结构，Laravel 决定顺序、解析引用并选择当前 Main Beat/Milestone。

`novel_outlines` 只保存不可变版本头和全局约束，不再包含 `content`：

```text
id bigint PK
novel_id bigint FK novels.id
version unsigned integer
status draft | current | superseded
source ai | manual | revision
schema_version unsigned integer
title text
summary text
must_include jsonb
must_not_include jsonb
checksum char(64)
source_artifact_id nullable FK generation_artifacts.id
based_on_outline_id nullable FK novel_outlines.id
created_by nullable FK users.id
applied_at nullable timestamp
created_at
updated_at
```

完整规划定义只存在于：

```text
novel_outline_volumes
  id / novel_outline_id / volume_key / sequence
  title / goal / climax / target_words

novel_outline_arcs
  id / novel_outline_id / novel_outline_volume_id
  arc_key / sequence / mainline_sequence nullable
  type / title / goal / stakes / completion_conditions

novel_outline_beats
  id / novel_outline_id / novel_outline_arc_id
  beat_key / sequence / mainline_sequence nullable
  title / summary
  chapter_budget_min / chapter_budget_max nullable
  acceptance_criteria / must_include / must_not_include
  character_candidates / world_entity_candidates
  handoff_next_beat_id nullable
  handoff_transition_mode / handoff_exit_result / handoff_next_trigger
  handoff_carried_states / handoff_open_threads
  handoff_required_transition / handoff_forbidden_jump

novel_outline_milestones
  id / novel_outline_id / novel_outline_beat_id
  milestone_key / sequence
  title / objective
  acceptance_criteria / must_include / must_not_include
```

所有子表通过非空外键、组合唯一键和组合外键保证完整父链属于同一 Outline Version。各层 Key 和同级 Sequence 唯一；Main Arc/Beat 的 `mainline_sequence` 在同一 Outline 内唯一；每个 Main Beat 至少一个 Milestone；除最终 Main Beat 外，`handoff_next_beat_id` 必须指向同版本相邻 Main Beat。Handoff 首版与 Beat 一对一，不新增表。

首版 Milestone/Handoff 只属于 Main Beat；Subplot 使用 Arc Completion Conditions 和 Chapter Plan Secondary Contribution，不创建独立 Milestone Completion。

`chapter_budget_min >= 1`，`chapter_budget_max` 为空或不小于最小值。Min 只用于规模提示和偏差观测，Max 只用于异常停留保护；二者都不是完成条件。

Source of Truth 边界：

- `novel_outline_*` 保存不可变规划定义；修订创建新版本。
- `story_events` 保存正式 Milestone/Beat Completion，不回写 Outline 表。
- 运行态 `volumes.source_outline_volume_id`、`story_arcs.source_outline_arc_id` 非空且唯一。
- 删除 `volumes.outline_key`、`story_arcs.outline_key` 和 `story_arcs.beats`。
- Chapter Plan 冻结 `novel_outline_id + checksum + arc_id + beat_id + milestone_id` 完整链；`checksum`、`input_hash`、`admission_snapshot` 和 `admitted_at` 固化 Scene 1 前通过门禁的语义输入、Bible/State/Outline/Handoff 来源、下游 Route 与容量。
- 不保留 `baseline_completions`、旧 JSONB Outline 双读或 Key 回退。

版本创建在一个事务中按 Volume、Arc、Beat、Milestone 顺序写入，随后按稳定 `beat_key` 回填 Handoff 自外键，并从持久化关系重新计算 Checksum。任一引用、顺序、邻接或 Checksum 校验失败都回滚整个版本。`source=ai` 时 `source_artifact_id` 必须引用同小说同规划批次的最终 `outline_blueprint` Artifact，且一个 Finalize Artifact 最多创建一个 Outline Version。

Character/World Candidate 使用 Outline 内稳定 `candidate_key`。保存、Finalize 或采用 Outline 都不会创建正式对象；只有对应 Plan、Review 证据、Introduced Event 和 State Version 校验通过后，Canonical Commit 才能幂等转正。

本基线明确放弃现有数据库数据，不迁移、不 Backfill 旧 `novel_outlines.content`、`story_arcs.beats`、旧 `beat_key` 或历史 Canonical 映射。Migration 实施和验收从停止 Worker 后的开发环境 `migrate:fresh` 开始。

### 40.3 Outline 生成与完成进度

新建 Outline 的目标合同是 `Foundation → Structure → Arc Beats × Arc → Skeleton Assembly → Beat Detail × Main Beat → Finalize`：

1. Foundation 生成 Bible、初始人物、世界实体和伏笔候选。
2. Structure 只生成 Volume / Arc 稳定 Key、顺序、目标和预算，不生成 Beat。
3. 每个 Arc Beats Provider 请求只生成目标 Arc 的 Beat；同一 Novel 严格串行。
4. Skeleton Assembly 由 Laravel 按 Structure 和全部 Arc Beats 的稳定 Key、局部顺序与 checksum 确定性合并，统一校验全局 Key、主线顺序和预算，不调用 Provider。
5. 每个 Beat Detail 只生成一个 Main Beat 的 Milestones / Handoff。
6. Finalize 不调用 Provider；它沿固定 lineage 合并成功 Artifact、解析稳定 Key、执行完整校验并事务写入关系表与最终 `outline_blueprint`。

主批次与子阶段继续复用现有 `generation_runs`、`generation_artifacts`，不新增工作流业务表。主批次使用 `scope_type=novel_outline_batch`；所有子 Run 的 `scope_id` 指向主批次 Run ID。子阶段 `scope_type` 与区分字段固定为：

| 阶段 | `scope_type` | Artifact | 区分字段 |
|---|---|---|---|
| Foundation | `novel_outline_foundation` | `outline_foundation` | 无 |
| Structure | `novel_outline_structure` | `outline_structure` | 无 |
| Arc Beats | `novel_outline_arc_beats` | `outline_arc_beats` | `context_snapshot.discriminator.arc_key` |
| Skeleton Assembly | `novel_outline_skeleton_assembly` | `outline_skeleton` | 无 |
| Beat Detail | `novel_outline_beat_detail` | `outline_beat_detail` | `context_snapshot.discriminator.beat_key` |
| Finalize | `novel_outline_finalize` | `outline_blueprint` | 无 |

主批次持久化状态为 `queued / running / failed / succeeded / cancelled`。`retrying` 由页面根据主批次与最新子 Run 派生，不新增数据库枚举。页面启动生成时先创建或复用 `queued` 主批次；Worker 激活后改为 `running`；子阶段终止失败时收口为 `failed`；Finalize 事务成功才改为 `succeeded`。同输入重复执行必须复用校验通过的 Artifact，不得重复产生正式关系行或 Usage。

全书大纲进度只从 PostgreSQL 的 Run、Artifact 和 Draft Outline 投影。Redis、Horizon 与 `failed_jobs` 不保存权威业务进度。Structure 完成后才能确定 Arc Beats 分母；Skeleton Assembly 完成后才能确定 Main Beat Detail 分母。领域 Resume 从最早缺失且来源有效的 Artifact 继续，不直接重放 Queue payload。

正式进度继续复用 `story_events`：

```text
story_arc_beat_milestone_completed
story_arc_beat_completed
```

Milestone Completion 必须引用完整 Outline/Arc/Beat/Milestone 父链。Beat Completion 只在全部 Milestone 已完成、Beat 验收条件具有 Canonical 证据且最终 Handoff 已满足时创建。Outline 定义表和运行态 Arc 表不保存可变完成游标。

### 40.4 删除边界

Outline 头到四层定义内部使用级联删除；运行态 Volume/Arc、Chapter Plan 和 Story Event 对 Outline 节点使用限制删除，防止误删正在使用的定义。完整小说删除由领域 Action 在一个事务中先删除外部运行引用，再删除 Outline Version 和全部小说数据。

章节删除采用 Tail Truncation：只允许删除目标 Sequence 及其后的完整章节后缀，并同步删除或重建相关 Event、State、Fact、Memory、Candidate、Foreshadowing Projection、Run、Artifact、Review 和 Usage。不能依靠单个 Chapter 外键级联来猜测 Canonical 恢复顺序；Action 必须先锁定 Novel、恢复前一 State Version，再按显式依赖顺序处理。

---

## 41. Data Model Batches

### Batch 1

```text
novels
novel_bibles
novel_outlines
novel_outline_volumes
novel_outline_arcs
novel_outline_beats
novel_outline_milestones
volumes
story_arcs
chapters
```

### Batch 2

```text
characters
world_entities
foreshadowings
chapter_plans
scenes
```

### Batch 3

```text
story_events
story_state_versions
facts
```

### Batch 4

```text
generation_runs
generation_artifacts
reviews
usage_records
```

### Batch 5

```text
memories
pgvector
```

### Batch 6

```text
Circular Foreign Keys
Current Pointers
```

除非用户明确要求：

不要跨 Batch 一次实现全部数据模型。

---

## 42. Testing

重要逻辑至少覆盖：

```text
Success

Validation Failure

Retry

Duplicate Job

State Version Conflict

Pause

Resume

Canonical Commit

Rollback

Hard Fact Conflict
```

涉及 PostgreSQL 特性的测试：

优先使用 PostgreSQL。

不要只依赖 SQLite。

---

## 43. AI Testing

自动测试默认：

```text
Fake / Mock Provider
```

不要每次测试调用真实付费 LLM。

真实模型测试单独作为：

```text
Integration / Evaluation
```

执行。

---

## 44. Golden Cases

建议长期保留以下测试：

```text
死亡角色无解释重新出现

不会游泳的人突然熟练游泳

不知道秘密的人利用秘密行动

关键物品无转移突然换主人

Critical Foreshadowing 到期却被忽略

Ending 阶段突然增加核心主线
```

这些测试优先级很高。

---

## 45. Cost Tracking

每次真实 Provider Request：

必须写入：

```text
usage_records
```

至少记录：

```text
provider
model
input_tokens
output_tokens
cached_tokens
latency
estimated_cost
request_id
```

必须能够：

```text
Novel
↓
Chapter
↓
Generation Run
↓
Usage
```

追踪成本。

---

## 46. Cost Optimization

优化顺序：

```text
减少无效调用
减少重复 Retry
复用 Artifact
控制 Context
局部 Rewrite
合理模型选择
```

不得通过删除：

```text
Hard Constraints
Current State
Critical Facts
```

来节省 Token。

---

## 47. Filament

Filament 是主要管理后台。

一级导航保持简单：

```text
Dashboard
Novels
Generation
Review
Memory
Settings
```

不要给所有 Model 都创建一级菜单。

Canonical Data 默认只读。

修改正式状态必须通过明确 Action。

---

## 48. Single-User UX

后台首先追求：

```text
快速
清晰
少点击
容易 Debug
```

不要增加企业级：

```text
多级审批
多人工作流
复杂通知
```

---

## 49. Development Workflow

重要任务遵循：

```text
Read Docs
↓
Inspect Existing Code
↓
Analyze
↓
Plan
↓
Implement
↓
Test
↓
Review
↓
Update Docs
```

不要看到需求后立即修改大量文件。

---

## 50. Before Coding

较大的功能开始前先回答：

```text
对应哪个需求？

涉及哪些表？

涉及哪些 Model？

哪些状态会变化？

是否产生 Story Event？

Transaction Boundary 是什么？

Idempotency 如何保证？

Job 执行两次会怎样？

中途 Crash 会怎样？

如何恢复？

需要什么测试？

是否真的需要新增抽象？
```

---

## 51. Existing Code First

修改项目之前必须检查：

```text
composer.json

Laravel Version

Filament Version

Existing Models

Existing Migrations

Existing Enums

Existing Services

Existing Packages

Existing Tests
```

已有能力优先复用。

不要创建重复实现。

---

## 52. Scope Control

任务要求：

```text
Batch 1
```

就只做 Batch 1。

不要顺便实现：

```text
AI
RAG
Queue
Filament
Ending
```

发现值得进行的大重构：

单独报告。

不要顺手执行。

---

## 53. Package Rule

安装新 Composer Package 前先判断：

```text
Laravel 自己能否简单解决？

已有 Package 是否已经解决？

这个 Package 是否真正必要？

长期维护成本如何？
```

不要为了一个简单功能随便增加依赖。

---

## 54. Explicit Over Magical

优先：

```text
Explicit Service Call
Explicit State Transition
Explicit Transaction
Explicit Workflow
```

避免：

```text
Hidden Observer
Global Hook
Magic Side Effect
Dynamic Service Locator
```

一个人维护时：

> 快速知道谁修改了状态非常重要。

---

## 55. Performance

优先级：

```text
Correctness
↓
Reliability
↓
Recoverability
↓
Maintainability
↓
Performance
```

没有真实 Profile / Metrics 之前不要引入：

```text
Table Partition
Read Replica
Sharding
Complex Cache
Scene Parallel
Multiple Queue Clusters
```

---

## 56. Future Complexity Trigger

只有出现真实问题才升级架构。

例如：

```text
关系查询复杂
→ 拆 relationships

时间逻辑复杂
→ 拆 timeline

Scene 生成成为瓶颈
→ 考虑 Scene Parallel

Queue 明显互相阻塞
→ 拆更多 Queue

PostgreSQL Search 无法满足
→ 再考虑 Elasticsearch

复杂图查询成为核心需求
→ 再考虑 Neo4j
```

没有真实信号：

```text
不做。
```

---

## 57. Critical Invariants

以下规则永远不能破坏：

```text
1. Draft never mutates canonical state.

2. Only reviewed content can become canonical.

3. A novel has one current canonical state version.

4. Canonical Commit is atomic.

5. Duplicate Commit has exactly-once effect.

6. Locked facts override model suggestions.

7. Vector memory never overrides canonical facts.

8. Every canonical chapter is traceable to generation inputs.

9. Failures are recoverable from persisted runs/artifacts.

10. Critical Closure Debt blocks completion.
```

---

## 58. Definition of Done

普通 Feature 至少完成：

```text
Requirement satisfied

Scope not expanded

Code implemented

Migration if required

Validation implemented

Tests added

Tests passing

Failure path considered

Duplicate execution considered

Documentation updated if architecture changed
```

---

## 59. Queue Job Definition of Done

每个重要 Job 必须明确：

```text
Input

Output

Idempotency Key

Retryable Errors

Timeout

Persisted Artifact

Duplicate Behavior

Failure Behavior

Resume Strategy
```

---

## 60. AI Stage Definition of Done

AI Stage 至少包含：

```text
Prompt Version

Input DTO

Output Schema

Schema Validation

Generation Run

Usage Tracking

Failure Handling

Fake Provider Test
```

---

## 61. Task Reporting

完成代码任务时优先汇报：

```text
Summary

Files Changed

Key Decisions

Tests

Risks / Follow-ups
```

必须明确：

```text
哪些测试实际运行

哪些测试通过

哪些测试没运行
```

不要说：

```text
“应该可以通过”
```

来代替真实测试结果。

---

## 62. Current Development Order

当前默认顺序：

```text
1. Data Model

2. Story Engine

3. Planning

4. AI Gateway

5. Generation Pipeline

6. Review

7. Canonical Commit

8. Context / Memory

9. Filament

10. Recovery / Ending
```

不要在 Story Engine 尚未稳定前优先开发复杂 RAG 或 Multi-Agent。

---

## 63. Next Architecture Document

Data Model 后的下一份核心文档：

```text
docs/architecture/story-engine.md
```

必须定义：

```text
Story Event Schema

Story State Schema

State Patch

State Validator

Hard Conflict

Soft Conflict

Canonical Commit

State Version

State Rebuild

Latest Chapter Rollback
```

---

## 64. Final Principle

本项目不是为了：

> 做一个功能最多的 AI 小说 SaaS。

而是为了：

> 做一个一个人能够长期维护，并真正稳定写完长篇小说的系统。

每增加一个功能之前先问：

```text
它是否明显提高：

Story Consistency？
Planning？
Context Quality？
Generation Quality？
Review？
Recoverability？
Ending？
Cost Control？
```

如果答案都是否：

```text
默认不做。
```

---

## 65. Agent Reminder

Before substantial changes:

```text
Read the docs.

Inspect the existing code.

Keep the scope small.

Prefer simple solutions.

Protect canonical state.

Treat LLM output as untrusted.

Make failures recoverable.

Test duplicate execution.

Do not invent future requirements.
```

# END OF AGENTS.md
