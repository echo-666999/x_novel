# AGENTS.md

# AI Long-Form Fiction Platform

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
Rewrite if needed
    ↓
Canonical Commit
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
Novel Canonical Pointer
```

只有：

```text
Review PASS
↓
CanonicalCommitService
```

才能更新正式状态。

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

`story_state_versions.version` 必须满足 `version >= 0`，其中版本 `0` 是小说进入生成前建立的初始完整状态快照。每部小说的版本号唯一：`unique(novel_id, version)`。

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
↓
Chapter
```

不要因为局部问题无条件重写整章。

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
PlanChapterJob
GenerateSceneJob
AssembleChapterJob
ReviewChapterJob
RewriteChapterJob
CommitChapterJob
```

### default

```text
UpdateMemoryJob
GenerateEmbeddingJob
RollupSummaryJob
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

当前 MVP 约 18 张核心表：

```text
novels
novel_bibles

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

首次 AI 小说蓝图生成发生在 Current Bible 创建前，可以生成完整 Bible 候选；用户采用并创建 Current Bible 后，Planner、Writer、Assembler、Reviewer、Rewriter 和长度修复必须读取同一个冻结 Bible Version。

---

## 41. Data Model Batches

### Batch 1

```text
novels
novel_bibles
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
