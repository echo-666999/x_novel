# AI Long-Form Fiction Platform — Agent Instructions

## 1. Project Overview

本项目是一个 **AI 长篇网络小说自动生成系统**。

目标不是构建面向多人团队的复杂 SaaS 平台，而是构建：

> 一个由单人即可长期开发、维护和运营，能够稳定规划、生成、审校、记忆、恢复并最终完成百万字级网络小说的 AI 写作系统。

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

项目采用：

```text
单用户
单租户
Laravel 单体应用
PostgreSQL 权威数据源
Redis 辅助
异步 Queue
Filament 管理后台
```

---

# 2. Source of Truth

开发前必须优先阅读项目文档。

文档优先级：

```text
1. AGENTS.md
2. docs/PRD.md
3. docs/architecture/*.md
4. docs/development/*.md
5. docs/decisions/*.md
6. Existing Code
```

其中：

```text
docs/PRD.md
```

定义产品需求和业务边界。

```text
docs/architecture/
```

定义已经确认的工程实现方式。

如果现有代码与 PRD / Architecture 文档冲突：

> 不要静默修改需求，也不要擅自扩大实现范围。

必须先报告：

1. 冲突位置；
2. 当前代码行为；
3. 文档要求；
4. 推荐处理方案；
5. 可能影响。

等待确认后再修改。

---

# 3. Primary Engineering Principle

## Simplicity First

本项目由一个人开发和运营。

任何设计都必须优先选择：

> 满足当前真实需求的最简单实现。

不要为了假设中的未来需求提前增加复杂度。

优先使用：

```text
Laravel
PostgreSQL
Redis
Laravel Queue
Filament
```

解决问题。

当两个方案都能满足需求时，优先选择：

1. 更少的代码；
2. 更少的表；
3. 更少的状态；
4. 更少的抽象；
5. 更少的依赖；
6. 更少的基础设施；
7. 更容易 Debug；
8. 更容易测试；
9. 更容易恢复；
10. 更低的长期维护成本。

不要因为某种架构“更高级”而选择它。

---

# 4. Explicit Non-Goals

除非用户明确要求，否则不要引入：

```text
Multi-tenancy
Workspace architecture
Complex RBAC
Multi-user approval workflow
Real-time collaboration

Microservices

Kafka
RabbitMQ

Neo4j
Elasticsearch

Separate Vector Database

Distributed Workflow Engine

CQRS Framework
Event Sourcing Framework

Kubernetes

Complex Repository Layer

Service Mesh
```

不要为未来可能出现的 SaaS、团队协作或高并发需求提前设计。

---

# 5. Complexity That Must Be Preserved

“小而精”不等于删除必要的可靠性设计。

以下能力不得为了简化而绕过：

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

这些属于产品核心，而不是过度工程。

---

# 6. Core Product Architecture

核心生成链：

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
Rewrite（必要时）
    ↓
Canonical Commit
    ↓
Story State Update
    ↓
Memory Update
```

任何实现都不得绕过：

```text
Review
    ↓
Canonical Commit
```

直接把 AI Draft 变成正式故事状态。

---

# 7. PostgreSQL Is the Source of Truth

PostgreSQL 是唯一权威业务数据源。

以下数据必须最终存在 PostgreSQL：

```text
Novel

Bible

Volume
Story Arc
Chapter
Scene

Character
World Entity
Foreshadowing

Fact
Story Event
Story State

Generation Run
Generation Artifact
Review

Memory Metadata

Usage / Cost
```

Redis 只能用于：

```text
Queue
Cache
Lock
Rate Limit
Temporary Progress
```

Redis 不得成为任何 Canonical Story Data 的唯一副本。

---

# 8. Draft / Canonical Isolation

这是系统最重要的业务规则之一。

任何 Draft：

```text
scene draft
chapter draft
rewrite draft
event candidate
state patch candidate
```

都不得直接修改：

```text
Canonical Story State

Facts

Character Canonical State

World Canonical State

Relationship State

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

# 9. Canonical Story State

系统必须明确区分：

```text
Story Event
```

和：

```text
Story State
```

Story Event 表示：

> 正式故事中已经发生了什么。

Story State 表示：

> 截至当前正式章节结束，故事世界现在是什么状态。

MVP 使用：

```text
story_events

story_state_versions

facts
```

实现。

不要擅自引入完整 Event Sourcing Framework。

---

# 10. Story Events

Story Event 必须来源于：

```text
Canonical Chapter
```

不得从未提交 Draft 创建正式 Story Event。

事件应尽可能包含：

```text
event_type

subject

payload

chapter

scene

story_time

text evidence

state_version
```

Story Event 原则上 Append-only。

发现历史错误时：

> 优先创建 correction / invalidation 类型事件。

不要静默修改已经影响后续章节的历史事件。

---

# 11. Story State Version

每次成功 Canonical Commit：

```text
State Version N
      ↓
Canonical Chapter
      ↓
Story Events
      ↓
State Patch
      ↓
State Version N+1
```

必须创建新的：

```text
story_state_versions
```

旧版本不得覆盖。

Novel 保存当前：

```text
canonical_state_version_id
```

---

# 12. Facts

`facts` 不应该成为所有故事信息的垃圾桶。

只保存真正需要：

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

角色尚不知道某个秘密

某件物品属于某人

某世界规则禁止某种行为
```

普通叙事情节优先存在：

```text
Story Events
Story State
Memory
```

---

# 13. Locked Facts

人工锁定事实优先级最高。

如果：

```text
LLM Output
```

与：

```text
locked fact
```

冲突：

必须：

```text
BLOCK
```

或：

```text
NEEDS_ATTENTION
```

不得让 AI 自动覆盖锁定事实。

---

# 14. pgvector Rules

pgvector 是：

> Long-term Memory Retrieval。

不是：

> Story Database。

Vector Search 负责：

```text
召回早期事件
召回相关人物历史
召回相关地点历史
召回伏笔
召回类似情节
召回风格样例
```

事实优先级必须是：

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

Vector Memory 不得覆盖前面的结构化事实。

---

# 15. Context Builder

Context Builder 是项目核心模块之一。

生成正文前必须按照优先级构建上下文。

推荐顺序：

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

优先删除低价值长期 Memory。

不得为了节省 Token 删除：

```text
Hard Constraints
Current State
Required Facts
Forbidden Facts
Ending Constraints
```

---

# 16. Context Snapshot

每次重要模型调用必须可以追踪当时使用的上下文。

MVP 使用：

```text
generation_runs.context_snapshot
```

至少记录：

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

token budget
```

目标：

> 能解释“AI 为什么会写出这一章”。

---

# 17. AI Output Is Untrusted Input

所有 LLM 输出都视为不可信数据。

在写入数据库之前必须：

```text
Schema Validate
        ↓
Reference Validate
        ↓
State Version Validate
        ↓
Locked Fact Validate
        ↓
Business Rule Validate
```

不要：

```text
json_decode()
↓
Model::create()
```

直接写入 Canonical Data。

---

# 18. Review Gate

正式章节必须经过 Review。

Review 维度：

```text
事实 / 连续性        25%

计划遵循             15%

人物一致性           15%

剧情推进             15%

重复度               10%

节奏 / 悬念          10%

文风 / 可读性        10%
```

Review Decision：

```text
PASS

REWRITE

NEEDS_ATTENTION

BLOCK
```

---

# 19. Rewrite

自动 Rewrite 默认最大：

```text
2 attempts
```

除非配置明确修改。

优先修复范围：

```text
Paragraph
    ↓
Scene
    ↓
Chapter
```

不要发现一个局部问题就无条件重新生成整章。

每次 Rewrite：

```text
必须创建新的 Artifact
```

不得覆盖原 Draft。

---

# 20. Canonical Commit

Canonical Commit 是整个系统最严格的写操作。

必须统一通过：

```text
CanonicalCommitService
```

执行。

禁止以下位置自行提交 Canonical State：

```text
Controller

Filament Resource

Model Observer

Listener

Job Handler
```

Job 可以调用：

```text
CanonicalCommitService
```

但不得复制 Commit 逻辑。

---

# 21. Canonical Commit Transaction

推荐事务：

```text
BEGIN

SELECT novel FOR UPDATE

检查 expected state version

检查 chapter 尚未 canonical

检查 Review = PASS

检查 idempotency

固定 canonical artifact

写 story_events

创建 story_state_versions N+1

更新 chapter

更新 novel canonical pointers

COMMIT
```

必须保证：

```text
Exactly-once Effect
```

即使 Job 被重复执行：

> 最终也只能存在一个正式提交结果。

---

# 22. Concurrency

MVP 优先正确性，不追求单 Novel 高吞吐。

允许：

```text
Novel A generation
+
Novel B generation
```

并行。

同一个 Novel：

```text
Chapter Generation
```

默认串行。

Canonical Commit：

```text
必须串行
```

不要提前实现复杂 Scene Parallel Generation。

只有真实性能数据证明需要时再增加。

---

# 23. Locking

最终一致性优先依赖：

```text
PostgreSQL Transaction

SELECT ... FOR UPDATE

State Version / CAS

Unique Constraint
```

Redis Lock 可以用于：

```text
减少重复任务
```

但 Redis Lock 不是最终一致性保证。

---

# 24. Idempotency

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

所有 Queue Job 开发时必须回答：

> 如果这个 Job 被执行两次，会发生什么？

如果答案是：

> 会产生重复正式数据。

则实现不合格。

---

# 25. Generation Runs

每个重要 AI / Workflow 阶段应记录：

```text
generation_runs
```

用于：

```text
Debug

Retry

Resume

Cost Tracking

Input Reproduction

Failure Analysis
```

不要只依赖 Laravel Log。

---

# 26. Generation Artifacts

以下产物统一保存为 Artifact：

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

新版本：

```text
Create New Artifact
```

不要覆盖旧 Artifact。

---

# 27. Queue Design

MVP 默认只使用：

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

不要提前拆成大量 Queue。

只有真实拥堵出现后再拆。

---

# 28. Queue Job Rules

每个 Job 必须明确：

```text
Input

Output

Idempotency Key

Retry Policy

Timeout

Failure Behavior

Artifact

Next Stage
```

不要创建：

> 一个 Job 什么都做。

也不要反过来：

> 一个简单流程拆成十几个没有必要的小 Job。

---

# 29. Failure Recovery

生成流程必须支持：

```text
Pause
Resume
Retry
Recover
```

如果某一步已经成功并且：

```text
input_hash
```

相同：

优先复用已有 Artifact。

不要重复调用模型。

目标：

```text
减少重复费用
+
减少随机输出变化
+
提高恢复确定性
```

---

# 30. Pause

暂停后：

```text
不得创建新的 Generation Stage
```

已经发出的模型请求可以完成。

结果：

```text
保存 Draft / Artifact
```

但：

```text
禁止 Canonical Commit
```

---

# 31. Rollback

MVP 只支持：

```text
Rollback Latest Canonical Chapter
```

不要实现：

```text
任意历史版本
任意 Branch
Git-like Merge
```

Rollback 必须：

```text
恢复 Novel Pointer

重建 Story State

失效相关 Memory

重新检查 Foreshadowing

记录操作原因
```

---

# 32. Ending Controller

Ending Controller 不得因为 MVP 精简而删除。

长篇小说系统必须解决：

> 如何结束。

而不仅仅是：

> 如何继续生成。

核心概念：

```text
Ending Contract

Closing Horizon

Closure Debt

Ending Audit
```

---

# 33. Closing Horizon

进入收束阶段后：

```text
Novel.status = completing
```

必须限制：

```text
新增核心人物

新增主线

新增世界硬规则

新增高重要度伏笔
```

除非用户明确允许。

---

# 34. Closure Debt

至少检查：

```text
未完成 Story Arc

未兑现 Reader Promise

未回收 Foreshadowing

未解决核心 Relationship

未解决 World Crisis

未完成 Character Arc
```

存在：

```text
critical Closure Debt
```

时：

```text
Novel 不得 completed
```

---

# 35. Filament

Filament 是本项目唯一主要管理后台。

保持简单。

一级导航默认：

```text
Dashboard

Novels

Generation

Review

Memory

Settings
```

不要为每张数据库表创建一个一级 Resource 菜单。

---

# 36. Filament Business Logic

Filament Page / Resource：

负责：

```text
UI
Form
Table
User Action
```

不要承载复杂领域逻辑。

例如：

错误：

```text
Filament Action
→ 直接更新 Story State
```

正确：

```text
Filament Action
→ CanonicalCommitService
→ Story State
```

---

# 37. Laravel Architecture

默认使用：

```text
Models

Enums

Actions

Services

DTOs

Jobs

Policies

Events
```

根据实际复杂度选择。

推荐核心 Service：

```text
ContextBuilder

StoryStateService

StateValidator

ReviewService

CanonicalCommitService

EndingService
```

不要为了形式建立大量空壳 Service。

---

# 38. Repository Rule

默认：

```text
不创建 Repository Layer
```

优先：

```text
Eloquent
```

复杂查询可以建立：

```text
Query Object
```

只有存在真正的存储抽象需求时才引入 Repository。

---

# 39. Model Observer Rule

不要在 Eloquent Observer 中执行：

```text
AI Request

Story State Update

Memory Update

Embedding

Canonical Commit

Queue Workflow
```

关键业务行为必须显式调用。

避免出现：

```text
save()
```

之后背后自动触发大量不可见副作用。

---

# 40. Database Migration Rules

Migration 必须优先使用数据库约束保证数据完整性：

```text
Foreign Key

Unique Constraint

CHECK Constraint

NOT NULL

Transaction
```

不要把所有约束只放在 Laravel Validation。

---

# 41. JSONB Rule

JSONB 用于：

```text
模型扩展属性

状态快照

上下文快照

低频复杂结构

MVP 暂不值得拆表的关系
```

如果字段需要：

```text
高频查询

排序

唯一约束

复杂 JOIN
```

则优先独立列 / 表。

---

# 42. Database Scope

当前核心模型保持在约：

```text
18 tables
```

不要未经确认主动拆成 30～50 张表。

当前基线详见：

```text
docs/architecture/data-model.md
```

---

# 43. Testing Requirements

关键业务必须有自动测试。

至少覆盖：

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

---

# 44. Canonical Commit Tests

Canonical Commit 属于最高优先级测试范围。

必须测试：

```text
Normal Commit

Duplicate Commit

Duplicate Queue Delivery

Wrong Expected State Version

Already Canonical Chapter

Review Not PASS

Transaction Failure

Story Event Write Failure

State Version Conflict
```

必须保证：

```text
任何失败场景
都不能留下半提交状态
```

例如禁止出现：

```text
Chapter = canonical

但是：

Story State 没更新
```

或者：

```text
Story Events 已写入

但是：

Chapter 仍然是 draft
```

Canonical Commit 必须满足：

> All or Nothing。

---

# 45. AI Provider Architecture

AI Provider 保持最小抽象。

建议：

```php
interface AiProvider
{
    public function generate(AiRequest $request): AiResponse;
}
```

MVP 只实现：

```text
当前实际使用的 Provider
```

不要提前实现：

```text
OpenAIProvider
AnthropicProvider
GeminiProvider
DeepSeekProvider
OpenRouterProvider

+

DynamicRouter
FallbackRouter
CostRouter
QualityRouter
```

一整套未来架构。

正确原则：

> 保留接口，但只实现当前需要的 Provider。

---

# 46. AI Request / Response

不要让业务代码直接处理 Provider 原始 Response。

统一 DTO：

```text
AiRequest

AiResponse
```

AiRequest 至少表达：

```text
model

system prompt

messages / prompt

temperature

max tokens

response schema

metadata
```

AiResponse 至少表达：

```text
content

structured data

input tokens

output tokens

cached tokens

latency

provider request id

model
```

Provider 特有字段不要泄漏到 Story Domain。

---

# 47. Structured Output

规划、Review、Event Extraction、State Patch 等结构化任务：

优先要求模型返回：

```text
Structured JSON
```

并执行：

```text
Schema Validation
```

不要通过：

```text
正则表达式
```

从自然语言回答中大量猜测结构。

正文生成可以返回纯文本，但伴随的：

```text
declared events

uncertainties

used memory

self check
```

应该尽可能结构化。

---

# 48. Prompt Architecture

MVP 只维护少量核心 Prompt：

```text
NovelPlanner

ChapterPlanner

SceneWriter

Reviewer

StoryEventExtractor
```

如果后续真正需要，可以增加：

```text
StatePatchGenerator

EndingPlanner
```

不要一开始建立几十种 Agent / Prompt。

---

# 49. Prompt Versioning

重要 Prompt 必须有版本。

例如：

```text
chapter-planner-v1

scene-writer-v1

reviewer-v1

event-extractor-v1
```

Generation Run 必须记录：

```text
prompt_version
```

修改 Prompt 后：

> 不得导致历史 Run 无法解释。

---

# 50. Agent / Multi-Agent Rule

默认不要使用复杂 Multi-Agent 架构。

禁止为了概念上的“AI Agent”创建：

```text
Planner Agent

Writer Agent

Critic Agent

Judge Agent

Editor Agent

Director Agent

Supervisor Agent

Memory Agent
```

然后让它们互相聊天。

本项目优先：

```text
确定性的 Laravel Workflow
+
明确的 Prompt
+
明确的输入输出 Schema
```

即：

```text
Laravel 控制流程

LLM 完成具体认知任务
```

而不是：

```text
LLM 自己决定整个系统怎么运行
```

---

# 51. Workflow Ownership

Workflow 的控制权属于：

```text
Laravel
```

而不是 LLM。

Laravel 决定：

```text
下一步执行什么

是否 Retry

是否 Rewrite

是否 Commit

是否 Pause

是否 Block

是否进入 Ending
```

LLM 可以：

```text
提出建议
生成内容
给出评分
提取事件
```

但不能自行绕过业务规则。

---

# 52. Story Planner

Planner 必须围绕结构化目标工作。

层级：

```text
Novel
 ↓
Volume
 ↓
Story Arc
 ↓
Chapter Plan
 ↓
Scene Plan
```

MVP 不需要复杂独立 Planner 服务集群。

Planner 应首先回答：

```text
这一章为什么存在？
```

而不是直接开始写正文。

---

# 53. Chapter Plan

Chapter Plan 至少应包含：

```text
chapter function

arc contribution

reader promise

target words

POV

tone

time anchor

hook type

must reveal

may hint

must not reveal

required facts

forbidden conflicts

due foreshadowings

scene plans
```

Scene Writer 必须读取 Chapter Plan。

禁止：

```text
没有 Chapter Plan
直接连续生成正文
```

作为正常生产流程。

---

# 54. Scene Generation

MVP 默认：

```text
一个 Chapter
    ↓
多个 Scene
```

按顺序生成。

不要提前实现 Scene 并行。

每个 Scene 应明确：

```text
goal

conflict

turn

outcome
```

Scene 生成完成后可以形成：

```text
Temporary Chapter State
```

供下一个 Scene 使用。

但 Temporary State：

> 不是 Canonical State。

---

# 55. Chapter Assembly

Scene 全部完成后：

```text
Assemble Chapter
```

负责：

```text
场景衔接

语气统一

重复信息清理

段落过渡

局部语言调整
```

Assembly 不应该：

```text
随意新增重大事实

改变人物能力

创造新的世界规则

修改关键剧情结果
```

如果 Assembly 引入新的重要事件：

必须重新进入：

```text
Review
+
Event Extraction
```

---

# 56. Story Event Extraction

Event Extractor 的目标不是总结文章。

它的任务是识别：

> 哪些事件会改变后续故事状态。

例如：

```text
CharacterMoved

CharacterDied

CharacterLearnedSecret

RelationshipChanged

ItemTransferred

AbilityAcquired

ConflictStarted

ConflictResolved

ForeshadowingPlanted

ForeshadowingReinforced

ForeshadowingPaidOff

WorldRuleRevealed
```

不要把：

```text
“角色看了看天空”
```

之类没有后续状态意义的行为全部变成 Story Event。

---

# 57. Event Type Growth

不要一开始设计几百个 Event Type。

先使用：

```text
少量稳定事件类型
+
payload
```

随着真实小说生成过程出现需求再增加。

优先稳定：

```text
Character

Relationship

Location

Knowledge

Item

Ability

Conflict

Foreshadowing

World
```

相关事件。

---

# 58. State Validation

StateValidator 必须优先执行确定性规则。

例如：

```text
dead character
+
normal action
=
Hard Conflict
```

```text
character.location = Beijing

下一 Scene 无移动事件

character suddenly appears in Shanghai
=
Potential Hard Conflict
```

```text
character does not know secret X

character directly acts on X
=
Knowledge Conflict
```

确定性规则能够判断的问题：

> 不要全部交给 LLM Judge。

---

# 59. LLM Judge Boundary

LLM Judge 更适合判断：

```text
人物性格是否漂移

铺垫是否充分

节奏是否拖沓

剧情是否重复

情绪变化是否自然

章节是否完成计划目标
```

数据库 / Rule Engine 更适合判断：

```text
角色是否死亡

物品归属

明确位置

锁定规则

State Version

章节编号

明确知识边界
```

原则：

```text
Deterministic Rule First
        ↓
LLM Judgment Second
```

---

# 60. Memory Creation

正式 Memory 只能来自：

```text
Canonical Chapter
```

不要从：

```text
Draft
Rewrite Candidate
Rejected Draft
```

创建正式长期记忆。

否则未来 RAG 可能召回：

> 从未真正发生过的剧情。

---

# 61. Memory Granularity

不要把整章原文直接作为唯一 Memory。

优先生成有意义的记忆单元：

```text
event memory

character milestone

relationship change

world discovery

foreshadowing

important dialogue consequence

arc milestone
```

每条 Memory 应尽量：

```text
短
明确
有来源
可检索
```

---

# 62. Memory Source

每条 Memory 必须能够追踪：

```text
source_type

source_id

chapter

相关 Story Event
```

如果无法知道 Memory 从哪里来：

> 这条 Memory 不应该成为可靠上下文。

---

# 63. Memory Retrieval

RAG 不应该直接：

```text
SELECT nearest vectors
LIMIT 20
```

然后全部塞进 Prompt。

至少先做：

```text
novel_id filter
        ↓
status filter
        ↓
type / entity filter
        ↓
vector similarity
        ↓
salience
        ↓
deduplication
        ↓
token budget
```

---

# 64. Recent Context vs Long-Term Memory

近期剧情不要全部依赖 pgvector。

近期章节优先：

```text
SQL
+
Chapter Summary
+
Story Events
```

例如：

```text
最近 5～20 章
```

Long-term RAG 主要解决：

```text
几十章以前

几百章以前

早期人物事件

早期伏笔

长期关系变化
```

---

# 65. Memory Debugging

Memory Retrieval 必须可调试。

至少能够看到：

```text
Query

Selected Memory

Similarity

Salience

Source Chapter

Source Event

为什么入选
```

这也是 Filament：

```text
Memory Inspector
```

存在的主要原因。

---

# 66. Cost Tracking

每一次实际 Provider 请求：

必须写：

```text
usage_records
```

至少记录：

```text
provider

model

input tokens

output tokens

cached tokens

latency

estimated cost

request id
```

必须能够从：

```text
Novel
 ↓
Chapter
 ↓
Generation Run
 ↓
Usage
```

追踪费用。

---

# 67. Cost Optimization

优化成本的优先级：

```text
减少无效调用

减少重复 Retry

复用 Artifact

控制 Context

局部 Rewrite

低价值任务使用较便宜模型
```

不要首先通过：

```text
删除关键 Story State
删除 Hard Constraints
```

来省 Token。

---

# 68. Filament Review Workflow

因为系统只有一个用户：

不要建立：

```text
Author Submit
 ↓
Editor Review
 ↓
Chief Editor
 ↓
Final Approval
```

只需要：

```text
AUTO PASS
```

或者：

```text
NEEDS_ATTENTION
```

用户处理后：

```text
Retry

Rewrite

Manual Edit

Override

Reject
```

即可。

---

# 69. Permissions

当前项目默认：

```text
super_admin
```

即可。

如果项目已经安装：

```text
Filament Shield
```

可以保留。

但不要主动创建：

```text
author
planner
reviewer
editor
operator
manager
```

等角色。

---

# 70. Security

API Key：

不得：

```text
写入 Git

写入 Prompt

输出到日志

保存完整值到数据库
```

优先：

```text
.env

Secret Management
```

模型输出：

始终视为：

```text
Untrusted Input
```

---

# 71. Logging

使用：

```text
Laravel Log
```

记录运行错误。

但业务追踪不要只依赖日志。

以下信息应该进入数据库：

```text
Generation Run

Artifact

Review

Usage

Story Event
```

日志用于：

```text
技术 Debug
```

数据库用于：

```text
业务追踪
```

---

# 72. Audit

单用户项目不建立复杂企业 Audit 系统。

如果已有：

```text
spatie/laravel-activitylog
```

可以复用。

重点记录：

```text
Locked Fact Change

Bible Change

Ending Contract Change

Manual Override

Canonical Rollback

Critical Setting Change
```

---

# 73. Error Handling

不要吞掉 Exception。

错误至少需要：

```text
generation_run.status = failed
```

以及：

```text
error_code

error_message
```

能够判断：

```text
是否可 Retry
```

不要只写：

```php
catch (\Throwable $e) {
    return false;
}
```

---

# 74. Retry Policy

只对可恢复错误自动 Retry。

例如：

```text
Timeout

429

Temporary Provider Failure

Network Error
```

不要对：

```text
Schema Invalid

Locked Fact Conflict

State Version Conflict

Business Rule Failure
```

进行无脑 Retry。

这些错误需要：

```text
Rewrite
Rebuild Context
NEEDS_ATTENTION
```

等明确处理。

---

# 75. Configuration

可配置项优先放：

```text
config/*.php
```

或：

```text
novels.settings
```

选择标准：

全局技术配置：

```text
config
```

小说级策略：

```text
novels.settings
```

不要创建：

> 一个巨大的 settings 表保存所有东西。

---

# 76. Constants

不要在业务代码散落：

```text
2

20

0.75

100
```

等没有语义的 Magic Number。

重要阈值使用：

```text
config

Enum

Named Constant
```

例如：

```text
max_rewrite_attempts
recent_chapter_window
review_pass_score
memory_top_k
```

---

# 77. Naming

代码优先使用清晰业务名称。

推荐：

```text
CanonicalCommitService

StoryStateService

ContextBuilder

ReviewService

EndingService

GenerationRun
```

避免：

```text
Manager

Helper

Common

Util

Processor
```

这种无法表达领域含义的万能类。

---

# 78. Method Size

复杂业务逻辑不要堆在一个几百行方法中。

例如 Canonical Commit 可以拆成明确步骤：

```text
validateExpectedState()

validateReview()

createCanonicalArtifact()

persistStoryEvents()

createNextStateVersion()

updateChapter()

updateNovelPointer()
```

但也不要为了“Clean Code”：

> 每两行代码创建一个类。

保持实用。

---

# 79. Comments

注释重点解释：

```text
为什么这样做
```

而不是重复：

```text
代码正在做什么
```

例如值得写：

```text
Why Draft must not update canonical state.

Why state version check is required.

Why Redis lock is not enough.

Why vector memory cannot override locked facts.
```

---

# 80. Documentation Updates

完成重要架构变更后：

必须同步检查：

```text
docs/PRD.md

docs/architecture/
```

是否需要更新。

如果实现与当前 Architecture 文档不同：

不要让文档长期失真。

---

# 81. ADR

只有真正重要且有长期影响的决策才创建 ADR。

例如：

```text
更换 Story State 存储方式

引入第二种数据库

改变 Canonical Commit 机制

改变 Embedding Model / Dimension

引入 Scene Parallel Generation
```

不要为每个小代码决定创建 ADR。

---

# 82. Development Workflow

处理较大功能时，遵循：

```text
Read
 ↓
Analyze
 ↓
Plan
 ↓
Confirm Scope
 ↓
Implement
 ↓
Test
 ↓
Review
 ↓
Document
```

不要：

```text
看到需求
 ↓
立即修改几十个文件
```

---

# 83. Before Coding

每一个重要任务开始前先回答：

```text
1. 这个任务对应哪个 PRD 需求？

2. 涉及哪些表？

3. 涉及哪些 Model？

4. 哪些状态会变化？

5. 是否产生 Story Event？

6. Transaction Boundary 在哪里？

7. Idempotency 怎么保证？

8. Job 执行两次会怎样？

9. 中途 Crash 会怎样？

10. 如何恢复？

11. 需要哪些测试？

12. 是否真的需要新增抽象或基础设施？
```

如果这些问题还没有答案：

> 先设计，不要直接编码。

---

# 84. Scope Control

用户要求实现：

```text
Data Model Batch 1
```

就不要顺手实现：

```text
AI Provider

Queue

Filament

RAG

Review
```

用户要求：

```text
Story State
```

不要顺手重构整个 Laravel 项目。

每次任务保持：

```text
Small

Focused

Reviewable

Testable
```

---

# 85. Existing Code First

修改项目之前必须先检查：

```text
已有 Model

已有 Migration

已有 Enum

已有 Service

已有 Helper

已有 Package

已有测试
```

如果已经存在能力：

```text
优先复用
```

不要创建重复实现。

---

# 86. Package Rule

不要因为某个功能存在 Package 就自动安装。

安装新 Composer Package 前必须判断：

```text
Laravel 本身能否简单解决？

现有依赖是否已经解决？

Package 是否长期维护？

引入成本是否大于自己实现？
```

简单功能优先自己实现。

复杂且成熟的通用能力可以使用成熟 Package。

---

# 87. Refactoring Rule

不要在实现功能时进行无关大规模 Refactor。

允许：

```text
为了当前功能必须进行的小范围重构
```

禁止：

```text
顺便重写整个项目架构
```

如果发现值得做的大重构：

> 单独报告并建议后续任务。

---

# 88. Backward Compatibility

项目处于早期开发阶段。

不要为了不存在的外部用户：

```text
过度维护兼容层
```

但数据库已有正式小说数据后：

必须谨慎处理：

```text
Migration

State Schema

Embedding

Prompt Version
```

变化。

---

# 89. Performance Philosophy

性能优先级：

```text
Correctness

Reliability

Recoverability

Maintainability

Performance
```

不是：

```text
Performance First
```

单用户系统不要为了理论 QPS：

> 引入复杂并发架构。

继续接在上一段 `# 89. Performance Philosophy` 后面。下面是 **AGENTS.md 的最后部分**，这次一直到 `END OF AGENTS.md`。

# 90. Redis Philosophy

Redis 是辅助基础设施，不是业务事实数据库。

允许 Redis 承担：

```text
Laravel Queue

Cache

Distributed Lock

Rate Limit

Temporary Progress

Debounce
```

禁止把以下数据只存在 Redis：

```text
Canonical Story State

Story Events

Facts

Canonical Chapter

Foreshadowing State

Generation Result

Usage / Cost
```

原则：

> Redis 丢失后，系统可以从 PostgreSQL 恢复并继续工作。

---

# 91. Cache Philosophy

不要一开始缓存所有东西。

只有出现明确收益时才增加 Cache。

优先考虑缓存：

```text
Current Story State

Current Novel Bible

Frequently Used Characters

Frequently Used World Entities

Generation Progress
```

Cache Key 必须至少包含：

```text
novel_id
```

涉及 Story State 时还应该包含：

```text
state_version
```

例如：

```text
novel:{novel_id}:state:{version}
```

这样可以避免旧 Story State 污染新章节生成。

---

# 92. Cache Invalidation

不要依赖复杂 TTL 猜测 Story State 是否过期。

Canonical Commit 完成后：

```text
明确失效相关 Cache
```

或者直接通过：

```text
state_version
```

产生新的 Cache Key。

对于 Story State：

> Versioned Cache 优先于复杂 Cache Invalidation。

---

# 93. Horizon

如果项目已经使用 Laravel Horizon：

继续使用。

Horizon 主要负责：

```text
Queue Monitoring

Failed Jobs

Retry

Throughput

Runtime

Wait Time
```

Job 建议添加有意义的 Tag：

```text
novel:{id}

chapter:{id}

run:{id}

stage:{stage}
```

方便定位：

> 哪一本小说、哪一章、哪一次生成出了问题。

---

# 94. Scheduler

Laravel Scheduler 可以用于：

```text
检查 stalled generation

检查 pending embedding

Rollup

Ending Audit

Cost Summary

清理临时 Cache
```

不要为了这些简单周期任务引入独立调度系统。

---

# 95. Background Maintenance

Maintenance Job 不得直接修改 Canonical Story Meaning。

例如：

允许：

```text
Generate Embedding

Rebuild Cache

Calculate Statistics

Rollup Summary

Recalculate Cost
```

涉及：

```text
Story State

Facts

Canonical Chapter

Foreshadowing Status
```

的修改必须经过明确的业务 Service。

---

# 96. Database Transactions

以下操作必须明确考虑 Transaction：

```text
Canonical Commit

Latest Chapter Rollback

State Version Creation

Important Manual Override
```

不要把：

```text
外部 LLM API Request
```

放在长时间数据库 Transaction 中。

错误：

```text
BEGIN

SELECT ...

调用 LLM 等待 30 秒

UPDATE ...

COMMIT
```

正确：

```text
Prepare Input

Call LLM

Validate Result

BEGIN

Short Critical Commit

COMMIT
```

---

# 97. External API Calls

调用 AI Provider 时必须考虑：

```text
Timeout

Retry

Rate Limit

429

5xx

Malformed Response

Provider Request ID
```

不要假设：

```text
Provider 永远返回成功 JSON
```

所有调用都应该能够关联：

```text
GenerationRun
```

---

# 98. Timeout

不同 AI Stage 可以配置不同 Timeout。

不要在业务代码散落：

```text
timeout = 300
```

这种 Magic Number。

统一配置：

```text
config/ai.php
```

例如：

```text
planning_timeout

generation_timeout

review_timeout

embedding_timeout
```

---

# 99. Provider Fallback

MVP 默认：

```text
不实现复杂自动 Provider Fallback
```

如果未来增加第二 Provider：

首先保证：

```text
Provider Interface
```

兼容。

然后再根据真实故障率决定是否增加：

```text
Fallback
```

不要提前建立复杂 Model Routing Engine。

---

# 100. Model Selection

不同任务可以使用不同模型：

```text
Planning

Writing

Review

Event Extraction

Embedding
```

但模型选择应该：

```text
配置化
```

不要在 Service / Job 中写死模型名称。

---

# 101. Model Change

更换模型时必须考虑：

```text
Prompt Compatibility

Structured Output Compatibility

Context Window

Cost

Quality

Embedding Dimension
```

正文模型切换通常不需要数据库 Migration。

Embedding Model 如果维度改变：

> 必须作为单独的数据迁移任务处理。

---

# 102. Embedding

MVP：

```text
一个 Embedding Model
```

即可。

不要同时维护：

```text
Embedding A

Embedding B

Embedding C
```

多个向量空间。

只有真实 A/B 或迁移需求出现后再增加复杂度。

---

# 103. Embedding Failure

Embedding 属于：

```text
Post-Commit Derived Data
```

因此：

```text
Canonical Chapter 已成功
+
Embedding 失败
```

不得回滚正式章节。

正确处理：

```text
Memory status = pending

Retry Embedding
```

---

# 104. Full Text Search

如果需要关键词检索：

优先使用：

```text
PostgreSQL Full Text Search
```

或简单：

```text
ILIKE
```

根据实际规模决定。

不要因为需要搜索 Memory 就立即引入 Elasticsearch。

---

# 105. Hybrid Retrieval

长期 Memory Retrieval 推荐：

```text
Metadata Filter
        ↓
Vector Similarity
        +
Keyword / Full Text
        ↓
Ranking
        ↓
Deduplication
        ↓
Token Budget
```

但 MVP 可以逐步实现。

第一版可以：

```text
Metadata Filter
+
Vector Similarity
```

先验证效果。

---

# 106. Retrieval Evaluation

不要只凭感觉判断 RAG 好不好。

准备固定测试集：

```text
早期关键事实

早期伏笔

人物知识边界

地点历史

物品归属

同名/相似实体

错误干扰项
```

检查：

```text
Top-K 是否召回正确内容
```

Context Builder 改动后重新跑测试集。

---

# 107. Repetition Detection

MVP 重复剧情检测可以使用：

```text
Recent Story Event Fingerprint

Chapter Summary Similarity

Embedding Similarity
```

不需要建立复杂独立反抄袭系统。

重点判断：

> 最近是不是又发生了本质相同、但没有新后果的剧情。

---

# 108. Narrative Quality

不要试图把“小说好不好看”完全转换成数据库规则。

系统负责：

```text
Consistency

Planning

Memory

Repetition

Pacing Signals

Foreshadowing

Closure
```

文学质量仍主要依赖：

```text
Prompt

Model

Planning Quality

Review
```

不要建立数百条机械规则把正文写死。

---

# 109. Human Override

因为用户是唯一操作者：

必须允许：

```text
Manual Edit

Override Review

Lock Fact

Unlock Fact

Force Rewrite

Change Plan

Pause

Resume

Rollback Latest Chapter
```

但高影响操作必须：

```text
明确显示影响
```

并记录原因。

---

# 110. Manual Canonical Edit

如果用户直接修改已经 Canonical 的正文：

不能只：

```text
UPDATE artifact.content
```

然后结束。

必须重新考虑：

```text
Story Event Extraction

Review

Story State

Facts

Memory

Embedding
```

因为正文变化可能改变故事事实。

---

# 111. Filament UX Principle

这是单人系统。

后台首先追求：

```text
快速

清晰

少点击

容易判断当前状态
```

而不是复杂企业工作流。

一个操作能在 Novel Workspace 完成：

> 不要强迫用户跳五个 Resource 页面。

---

# 112. Novel Workspace

Novel 应该成为后台主要工作空间。

推荐 Tabs：

```text
Overview

Bible

Characters

World

Planning

Chapters

Foreshadowing

Generation Runs
```

Memory 和 Review 可以提供全局页面，同时支持从 Novel 内进入。

---

# 113. Dashboard

Dashboard 只展示真正有行动价值的信息。

例如：

```text
Current Novel

Current Chapter

Current Volume

Total Words

Generation Status

Last Failure

Review Pass Rate

Rewrite Rate

Due Foreshadowings

Closure Debt

Today's Tokens

Today's Cost

Queue Status
```

不要为了“Dashboard 看起来丰富”堆大量无意义图表。

---

# 114. Notifications

单用户 MVP 不需要复杂通知中心。

重要异常在 Filament 中明显展示：

```text
Generation Failed

NEEDS_ATTENTION

Hard Conflict

Budget Limit

Due Critical Foreshadowing

Ending Blocked
```

如果未来真的需要：

```text
Email / Telegram / Slack
```

再增加。

---

# 115. CLI Commands

适合批处理和维护的操作可以使用 Artisan Command。

例如：

```text
novel:generate

novel:pause

novel:resume

story:rebuild-state

memory:rebuild

memory:embed

ending:audit
```

但不要让 CLI 和 Filament 分别实现两套业务逻辑。

二者都应该调用：

```text
同一个 Service / Action
```

---

# 116. Service Reuse

例如：

```text
Filament
       \
        → GenerateNextChapterAction
       /
Artisan
```

而不是：

```text
FilamentGenerateService

CliGenerateService
```

各写一套。

---

# 117. Testing Pyramid

测试优先级：

```text
Domain / Service Tests

Feature Tests

Database Constraint Tests

Queue / Workflow Tests

Filament Critical Action Tests
```

不要为了追求覆盖率：

> 给简单 Getter 写大量低价值测试。

---

# 118. Test Database

测试必须尽可能使用：

```text
PostgreSQL
```

尤其涉及：

```text
JSONB

pgvector

CHECK

Lock

Transaction

PostgreSQL-specific Index
```

时。

不要只在 SQLite 测试通过就认为 PostgreSQL 行为正确。

---

# 119. AI Tests

自动测试不要默认调用真实付费 LLM。

Provider 必须能够：

```text
Fake

Mock
```

测试：

```text
Workflow

Schema Validation

Retry

Review Decision

State Patch
```

使用固定响应。

真实模型测试：

```text
单独作为 Integration / Evaluation
```

运行。

---

# 120. Golden Test Cases

建议维护少量高价值小说测试场景。

例如：

```text
角色已经死亡
→ 后文试图正常出现

角色不会游泳
→ 后文熟练游泳

角色不知道秘密
→ 后文利用秘密行动

物品在角色 A 手中
→ 无转移事件出现在角色 B 手中

伏笔已到期
→ Chapter Plan 完全忽略

Ending 阶段
→ Planner 新开核心主线
```

这些测试比大量低价值 Unit Test 更重要。

---

# 121. Fixtures

测试故事数据应尽量：

```text
小

明确

可理解
```

不要每次测试生成：

```text
几百章随机 Faker 小说
```

关键规则使用人工设计 Fixture。

性能测试再使用大数据 Seeder。

---

# 122. Migration Safety

已经存在真实小说数据后：

Migration 不得假设：

```text
表为空
```

涉及：

```text
NOT NULL

Column Type Change

State Schema

Embedding Dimension
```

时必须考虑数据迁移。

---

# 123. Destructive Migration

不要轻易：

```text
dropColumn()

dropTable()
```

删除已经承载正式故事的数据。

如果确实需要：

先：

```text
分析影响
备份
迁移
验证
```

再删除。

---

# 124. Story State Schema Evolution

`story_state_versions.state` 是 JSONB。

随着系统发展 Schema 会变化。

因此 State 中建议保存：

```json
{
  "schema_version": 1
}
```

不要假设所有历史 State 永远具有完全相同结构。

---

# 125. Artifact Immutability

Generation Artifact 创建后原则上：

```text
Immutable
```

如果用户修改 Draft：

创建：

```text
new version
```

不要覆盖历史 Artifact。

这样才能：

```text
比较 Rewrite

追踪 Review

Debug 模型输出

恢复历史
```

---

# 126. Checksums

重要 Artifact / Context / State 建议保存：

```text
SHA-256
```

用于：

```text
Input Reuse

Idempotency

Change Detection

Reproduction
```

不要设计自定义复杂 Hash 算法。

---

# 127. Word Count

中文正文 `word_count` 的具体口径必须统一。

不要在：

```text
Filament

Job

Service
```

分别实现不同算法。

建立一个统一：

```text
TextMetricsService
```

或简单 Utility。

中文网文更准确的名称未来可以考虑：

```text
character_count
```

但如果当前产品统一使用 `word_count`，保持一致即可。

---

# 128. Chapter Number

正式章节序号：

```text
novel_id + sequence
```

必须唯一。

预留 Chapter 可以存在。

但只有：

```text
canonical
```

后才视为正式发布意义上的章节。

失败 Chapter 不得导致：

```text
重复正式 sequence
```

---

# 129. Current Pointers

`novels` 可以保存：

```text
current_volume_id

current_chapter_id

canonical_state_version_id
```

作为快速 Pointer。

但这些 Pointer 必须能够：

```text
从正式数据重新计算
```

它们不是不可恢复的唯一事实。

---

# 130. Summary Generation

Chapter Canonical Commit 后可以异步生成：

```text
Chapter Summary
```

Summary 用于：

```text
Recent Context

RAG

Planning

Review
```

Summary 失败：

> 不回滚 Canonical Chapter。

进入 Retry 即可。

---

# 131. Foreshadowing

Foreshadowing 是核心能力，不应退化成普通备注。

必须至少有：

```text
status

importance

setup chapter

due window

promised payoff

owner arc

payoff chapter
```

Planner 必须能够读取：

```text
Due Foreshadowings
```

---

# 132. Critical Foreshadowing

当：

```text
importance = critical
```

且进入：

```text
due window
```

Planner 必须：

```text
处理
```

或者：

```text
明确延期
```

不得静默忽略。

---

# 133. Reader Promise

Reader Promise 可以 MVP 存在：

```text
Chapter Plan

Story State
```

暂不建立独立表。

只有未来需要：

```text
大量 Promise 查询

独立生命周期

统计分析
```

时再拆表。

---

# 134. Relationship

MVP Relationship 存在：

```text
Story State JSONB

characters.current_state
```

不建立独立：

```text
relationships
```

表。

当未来需要：

```text
关系图

大量关系查询

关系历史分析
```

时再拆。

---

# 135. Timeline

MVP Timeline 存在：

```text
Story State

Story Events
```

不建立复杂 Timeline Engine。

但必须能够判断最基本的：

```text
事件顺序

人物位置移动

关键时间锚
```

复杂时间旅行小说如果真实出现，再升级设计。

---

# 136. World Model

MVP 使用：

```text
world_entities
```

统一表示：

```text
Location

Item

Faction

Organization

Rule

Concept
```

不要提前拆成多张表。

---

# 137. Character Model

人物静态设定与当前轻量状态可以暂时共存在：

```text
characters
```

但 Canonical 的完整当前状态仍以：

```text
Story State
```

为准。

`characters.current_state` 更接近：

```text
方便 UI / Query 的投影
```

发生冲突时：

```text
Canonical Story State
```

优先。

---

# 138. Projection Philosophy

MVP 可以有少量方便查询的 Projection：

```text
characters.current_state

world_entities.current_state
```

这些 Projection：

> 必须能够从 Story State / Story Events 重建。

不要让 Projection 成为第二套不可恢复事实源。

---

# 139. Rebuild

系统后续必须支持：

```text
Rebuild Story State
```

至少能够：

```text
从某个 State Version
+
后续 Story Events
```

恢复当前状态。

MVP 不要求构建复杂 Event Sourcing Infrastructure。

只需要明确可恢复路径。

---

# 140. Latest Chapter Rollback

Rollback 最新正式章时：

必须考虑：

```text
Chapter

Story Events

Story State

Novel Pointer

Memory

Embedding

Foreshadowing

Summary
```

不能只：

```text
chapter.status = draft
```

---

# 141. Observability

单用户项目的 Observability 重点是：

> 出问题时自己能快速找到原因。

至少能从一个 Chapter 找到：

```text
Chapter Plan

Generation Runs

Context Snapshot

Artifacts

Reviews

Story Events

State Version

Usage
```

这比搭建复杂 Metrics Platform 更重要。

---

# 142. Metrics

MVP 建议跟踪：

```text
Chapters Generated

Generation Success Rate

Review Pass Rate

Rewrite Rate

Hard Conflict Count

Average Generation Time

Input Tokens

Output Tokens

Cost Per Chapter

Memory Retrieval Count

Due Foreshadowings

Closure Debt
```

---

# 143. Cost Per Chapter

`cost per chapter` 是非常重要的长期指标。

必须能够统计：

```text
Planning

Scene Generation

Assembly

Review

Rewrite

Event Extraction

Embedding
```

最终总成本。

这可以帮助决定：

```text
哪些阶段值得使用更强模型
```

---

# 144. Quality vs Cost

不要单纯追求：

```text
最便宜
```

也不要默认：

```text
所有阶段都使用最贵模型
```

实际通过：

```text
Evaluation

Review Pass Rate

Rewrite Rate

Cost Per Chapter
```

决定模型策略。

---

继续接在上一段 `# 145. Evaluation Before Optimization` 后面。这是最后一部分，我会明确以 `END OF AGENTS.md` 结束。

# 145. Evaluation Before Optimization

任何重大 Prompt / Model / RAG / Review 策略调整之前：

先建立可比较的 Evaluation。

不要因为：

```text
“感觉这个 Prompt 更好”
```

就直接替换生产配置。

至少比较：

```text
Continuity

Plan Adherence

Character Consistency

Plot Progress

Repetition

Style

Review Pass Rate

Rewrite Rate

Cost
```

---

# 146. Prompt Change Evaluation

修改核心 Prompt 后：

至少使用固定测试小说运行：

```text
相同 Chapter Plan

相同 Story State

相同 Context

相同模型
```

比较新旧 Prompt。

如果无法证明明显改善：

> 优先保持更简单、稳定的版本。

---

# 147. Model Change Evaluation

切换正文生成模型时至少关注：

```text
正文质量

连续性

指令遵循

Structured Output 稳定性

Context Length

Latency

Cost

Rewrite Rate
```

不要只根据单章文笔决定模型。

长篇小说系统更重要的是：

> 连续几十章、几百章后是否仍然稳定。

---

# 148. Long-Run Testing

本项目最终必须进行长时间连续生成测试。

阶段目标：

```text
20 Chapters
     ↓
50 Chapters
     ↓
100 Chapters
     ↓
300 Chapters
     ↓
500 Chapters
     ↓
1,000+ Chapters
```

每个阶段重点检查：

```text
Story State Drift

Character Drift

Plot Drift

Memory Retrieval

Foreshadowing

Repetition

Cost

Recovery

Ending Control
```

---

# 149. MVP Success Criterion

MVP 成功的第一标准不是：

```text
后台页面很多

架构很复杂

Agent 很多

支持很多模型
```

而是：

> 能否稳定、可恢复地连续生成至少 100 章，并保持故事基本一致。

达到 100 章后：

再根据真实问题决定下一阶段开发内容。

---

# 150. Development Priority

默认开发优先级：

```text
1. Data Integrity

2. Story State

3. Planning

4. Generation

5. Review

6. Canonical Commit

7. Context / Memory

8. Recovery

9. Ending

10. Filament UX

11. Performance Optimization

12. Advanced Features
```

不要颠倒成：

```text
漂亮后台
↓
复杂 Agent
↓
高级架构
↓
最后才解决 Story State
```

---

# 151. Current Architecture Documents

开发时优先检查：

```text
docs/PRD.md

docs/architecture/data-model.md
```

后续预计增加：

```text
docs/architecture/story-engine.md

docs/architecture/generation-pipeline.md

docs/architecture/memory-context.md
```

这些文档一旦存在：

> 对应模块开发前必须先阅读。

---

# 152. Current Data Model

当前 MVP 数据模型目标约：

```text
18 Core Tables
```

包括：

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

不要未经明确需求主动拆出大量新表。

---

# 153. Data Model Batch Strategy

数据模型必须分批实现。

## Batch 1

```text
novels

novel_bibles

volumes

story_arcs

chapters
```

## Batch 2

```text
characters

world_entities

foreshadowings

chapter_plans

scenes
```

## Batch 3

```text
story_events

story_state_versions

facts
```

## Batch 4

```text
generation_runs

generation_artifacts

reviews

usage_records
```

## Batch 5

```text
memories

pgvector
```

## Batch 6

```text
Circular Foreign Keys

Current Pointers
```

除非明确要求：

> 不要跨 Batch 一次实现全部数据模型。

---

# 154. Migration Order

Migration 顺序以：

```text
docs/architecture/data-model.md
```

为准。

遇到循环外键：

不要通过：

```text
取消 FK
```

粗暴解决。

应该：

```text
先创建基础表
        ↓
后续 Migration 添加 Pointer FK
```

---

# 155. PostgreSQL Specific Features

允许合理使用：

```text
JSONB

Partial Index

CHECK Constraint

FOR UPDATE

Advisory Lock

Full Text Search

pgvector
```

因为 PostgreSQL 是项目明确选择的数据库。

不需要为了理论上的：

```text
MySQL Compatibility
```

放弃 PostgreSQL 能力。

---

# 156. pgvector Installation

应用 Migration 不应假设数据库用户拥有：

```text
CREATE EXTENSION
```

权限。

推荐：

```text
部署阶段确保 pgvector extension 已安装
```

Laravel Migration：

负责：

```text
业务表和 vector column
```

不要让普通部署因为：

```sql
CREATE EXTENSION vector
```

权限问题导致全部 Migration 失败。

---

# 157. Environment Assumptions

不要在代码中假设：

```text
开发环境 = 生产环境
```

配置必须通过：

```text
.env

config/*.php
```

管理。

至少区分：

```text
APP_ENV

DB

REDIS

QUEUE

AI PROVIDER

AI MODEL

EMBEDDING MODEL
```

---

# 158. Local Development

本项目优先保证：

```text
单台开发机
+
单套 PostgreSQL
+
单套 Redis
```

即可完整运行。

不要要求开发者为了启动项目：

```text
运行十几个 Docker Service
```

除非这些 Service 真正必要。

---

# 159. Deployment Philosophy

生产部署保持简单。

目标：

```text
Laravel App

PostgreSQL

Redis

Queue Worker / Horizon

Scheduler
```

即可运行核心系统。

不要主动增加：

```text
Service Discovery

Message Broker Cluster

Vector DB Cluster

Search Cluster
```

等运维负担。

---

# 160. Single-Person Operations

任何新功能都必须考虑：

> 一个人出了问题以后，能不能自己快速修？

优先提供：

```text
可见状态

明确错误

Retry

Resume

Rebuild

Rollback

Inspector
```

而不是依赖：

```text
多人排查

复杂 DevOps

隐藏自动化
```

---

# 161. Recoverability Over Cleverness

当：

```text
聪明但难恢复
```

和：

```text
简单但容易恢复
```

之间选择时：

默认选择后者。

长篇生成任务可能持续数小时甚至更久。

恢复能力比代码技巧更重要。

---

# 162. Explicit State Over Hidden State

重要业务状态必须显式存在数据库中。

不要依赖：

```text
某个 Job 是否还在队列里

某个 Redis Key 是否存在

某个 PHP Process 内存变量
```

推断小说当前状态。

应该直接能够查询：

```text
Novel Status

Chapter Status

Generation Run Status

Review Decision

State Version
```

---

# 163. No Hidden Workflow

Workflow 状态必须可观察。

例如不能：

```text
一个 Job 内部调用 8 次模型
```

最后只留下：

```text
success / failed
```

重要阶段应该形成：

```text
Generation Run

Artifact

Usage
```

方便 Debug。

---

# 164. But Avoid Over-Splitting

“可观察”不等于：

> 每一个函数都建立一个 Generation Run。

只有真正：

```text
耗时

收费

可失败

需要 Retry

需要恢复

产生重要 Artifact
```

的阶段才值得独立记录。

---

# 165. Deterministic Core

系统外围可以使用生成式 AI。

系统核心控制逻辑尽量确定性。

例如：

```text
是否超过 Rewrite 次数

是否超过 Budget

State Version 是否匹配

是否存在 Locked Fact Conflict

Chapter 是否已经 Canonical

Foreshadowing 是否到期
```

必须由代码判断。

不要问 LLM：

```text
“你觉得现在是否超过最大重试次数？”
```

---

# 166. LLM Responsibilities

LLM 适合：

```text
Planning

Creative Writing

Semantic Review

Event Extraction

Summary

Semantic Retrieval Query Generation
```

Laravel 适合：

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

边界必须清晰。

---

# 167. No Autonomous Database Mutation

LLM 永远不能直接决定：

```text
执行 SQL

删除数据

修改 Canonical State

Commit Chapter
```

LLM 输出：

```text
Proposal
```

Laravel：

```text
Validate
        ↓
Decide
        ↓
Persist
```

---

# 168. Tool / Agent Safety

未来如果引入 Tool Calling：

模型调用 Tool 前必须有明确权限边界。

尤其禁止模型自行：

```text
Rollback Novel

Delete Chapter

Change Locked Fact

Change Ending Contract

Change Budget

Publish Content
```

这些属于用户或确定性 Workflow 控制。

---

# 169. Automatic Generation Safety

如果开启：

```text
Auto Generate
```

每一章仍必须经过完整：

```text
Plan
↓
Generate
↓
Review
↓
Commit
```

Auto Mode 只是：

> 自动触发下一章。

不是：

> 绕过质量门禁。

---

# 170. Auto Stop Conditions

自动连续生成必须在以下情况停止：

```text
Hard Conflict

NEEDS_ATTENTION

Budget Hard Limit

Repeated Rewrite Failure

State Version Conflict

Critical Provider Failure

Ending Audit Block

User Pause

Emergency Stop
```

禁止系统在明显异常时：

```text
继续疯狂生成几十章
```

---

# 171. Generation Loop

推荐自动生成循环：

```text
Generate Chapter N
        ↓
Canonical Commit
        ↓
Post-Commit Tasks
        ↓
Check Stop Conditions
        ↓
Check Ending Conditions
        ↓
Generate Chapter N+1
```

不要提前一次：

```text
Queue 100 Chapters
```

因为后面的 Chapter Plan 必须基于前面已经发生的正式 Story State。

---

# 172. Planning Horizon

可以提前规划：

```text
Volume

Arc

Near-term Chapters
```

但详细 Chapter Plan 不应该一次生成几百章后永久固定。

推荐：

```text
长期方向稳定

近期计划详细

远期计划粗粒度
```

随着故事发展动态调整。

---

# 173. Replanning

允许对：

```text
未 Canonical
```

的未来计划重新规划。

已经 Canonical 的历史：

> 不应因为 Replanning 被静默修改。

新的计划必须从：

```text
Current Canonical State
```

继续。

---

# 174. Drift Detection

MVP Drift Detection 可以保持简单。

例如检查最近：

```text
10～20 Chapters
```

对当前 Arc 的贡献。

连续多章：

```text
没有 Arc Progress

没有 Character Change

没有 Conflict Progress

没有 Promise Payoff
```

则产生：

```text
Drift Warning
```

不要第一版就建立复杂机器学习 Drift 系统。

---

# 175. Ending Trigger

进入 `completing` 的触发条件可以结合：

```text
Target Words

Current Arc Progress

Remaining Major Arcs

Closure Debt

Ending Contract
```

不要只根据：

```text
字数达到 100 万
```

机械结束。

---

# 176. Completion

Novel 只有满足：

```text
Ending Audit PASS
```

或者：

```text
用户明确 Override
```

才能进入：

```text
completed
```

Override 必须记录原因。

---

# 177. Completed Novel

`completed` 默认视为：

```text
Read Mostly
```

不要自动继续生成正文。

如果未来需要：

```text
番外

续作
```

优先设计成明确的新模式，而不是偷偷把 completed 改回 generating。

---

# 178. Feature Request Evaluation

每个新功能开发前问：

```text
它是否明显提升：

Story Consistency？

Planning？

Context Quality？

Generation Quality？

Review？

Recoverability？

Ending？

Cost Control？
```

如果全部不是：

> 默认低优先级。

---

# 179. Avoid Feature Creep

以下类型需求尤其容易让项目失控：

```text
“以后可能需要多人”

“以后可能做 SaaS”

“以后可能很多用户”

“以后可能百万并发”

“以后可能支持几十个模型”
```

没有真实需求之前：

```text
不实现。
```

---

# 180. YAGNI

本项目明确遵循：

```text
You Aren't Gonna Need It
```

但 YAGNI 不适用于：

```text
Data Integrity

Idempotency

Recovery

Story Consistency
```

这些不是未来需求，而是当前核心需求。

---

# 181. KISS

本项目明确遵循：

```text
Keep It Simple
```

例如：

能使用：

```text
Laravel Service
```

解决：

就不要建立：

```text
Distributed Domain Service Platform
```

能使用：

```text
PostgreSQL JSONB
```

合理解决：

就不要立刻拆十张表。

---

# 182. DRY With Restraint

避免明显重复业务逻辑。

但不要为了消除：

```text
两三行相似代码
```

建立难以理解的抽象层。

优先：

```text
Readable
```

而不是：

```text
Maximum Abstraction
```

---

# 183. Explicit Over Magical

优先：

```text
显式 Service Call

显式 State Transition

显式 Transaction

显式 Job Chain
```

避免：

```text
Observer 魔法

全局 Hook

隐藏 Side Effect

动态 Service Locator
```

一个人维护时：

> 能快速找到“谁改了这个状态”非常重要。

---

# 184. Code Readability

代码应该让未来几个月后的开发者自己：

```text
快速看懂
```

不要追求：

```text
炫技

极端抽象

复杂 Design Pattern
```

领域命名清楚比 Pattern 数量重要。

---

# 185. No Premature Optimization

没有 Profile / Metrics / Query Plan 证据之前：

不要：

```text
复杂 Cache

Table Partition

Read Replica

Sharding

Scene Parallel

Multiple Queue Clusters
```

PostgreSQL 对本项目 MVP 数据量完全足够。

---

# 186. Query Optimization

出现慢查询时按顺序处理：

```text
1. 检查 N+1

2. 检查 Query

3. EXPLAIN ANALYZE

4. 添加合理 Index

5. 减少无用数据读取

6. Cache

7. 最后才考虑架构变化
```

---

# 187. Database Index Rule

不要给每个字段都建 Index。

优先索引：

```text
Foreign Keys

Novel Scope

Status

Sequence

Version

Frequent Filters

Retrieval Metadata
```

复杂 Index 必须有真实查询依据。

---

# 188. Large JSONB

如果：

```text
generation_runs.context_snapshot
```

未来变得非常大并影响主表性能：

再拆：

```text
context_snapshots
```

当前 MVP：

```text
不拆。
```

同样原则适用于其他 JSONB。

---

# 189. Large Artifact Storage

正文 MVP 保存在：

```text
PostgreSQL TEXT
```

即可。

不要提前引入：

```text
S3

MinIO

Object Storage
```

当 Artifact 数据量真正影响数据库时再归档。

---

# 190. Backup

生产环境必须优先保证：

```text
PostgreSQL Backup
```

Redis 数据：

应该能够：

```text
丢失后重建
```

真正需要保护的是：

```text
Canonical Chapters

Story Events

Story State

Facts

Generation History

Memory Metadata
```

---

# 191. Restore Test

有备份不等于能恢复。

系统进入真实长期运行后，应定期验证：

```text
Database Restore

Story State Rebuild

Queue Restart

Memory Rebuild
```

---

# 192. Code Generation by Codex

Codex 修改代码时：

不要一次生成巨大范围。

优先：

```text
Small Batch
        ↓
Run Tests
        ↓
Review
        ↓
Next Batch
```

尤其数据库阶段严格按照：

```text
Data Model Batch
```

执行。

---

# 193. Codex Planning Requirement

对于较大的开发任务：

Codex 必须先输出：

```text
Current State

Files To Change

Database Changes

Implementation Plan

Tests

Risks
```

然后再开始修改。

如果用户明确要求直接执行：

可以执行。

但仍然必须保持 Scope。

---

# 194. Codex Existing Project Inspection

开始编码前：

必须实际检查当前项目。

不要根据：

```text
“标准 Laravel 项目应该是……”
```

猜测当前代码。

需要检查：

```text
composer.json

Laravel Version

Filament Version

Existing Models

Existing Migrations

Existing Packages

Existing Enums

Existing Services

Existing Tests
```

---

# 195. Do Not Downgrade Existing Good Architecture

“Simplicity First” 不意味着：

> 看到现有成熟实现就拆掉。

如果项目已经有：

```text
合理的 Service

成熟的 Enum

稳定的 Test Helper

已使用的 Package
```

且没有造成明显问题：

```text
优先复用。
```

---

# 196. Conflict Reporting

发现文档与代码冲突时，使用：

```text
Conflict

Document Says

Current Code Does

Impact

Recommended Resolution
```

格式说明。

不要默默选择其中一个。

---

# 197. Assumption Rule

如果一个实现需要重要业务假设：

不要静默猜测。

例如：

```text
“一个 Volume 是否允许没有 Arc？”

“一个 Chapter 是否允许跨 Volume？”

“Locked Fact 是否允许自动解除？”
```

如果文档没有答案且会明显影响数据模型：

```text
先报告假设、影响和推荐方案，并等待用户确认后再实现。
```

---

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>

---

## UI / Design Source of Truth

For all Filament UI work, read:

1. `DESIGN.md`
2. `.agents/skills/filament-ui/SKILL.md`
3. relevant product / architecture documents

`DESIGN.md` is the source of truth for:

- visual hierarchy
- colors
- typography
- spacing
- radius
- surfaces
- borders
- component density
- layout
- interaction patterns

Architecture documents remain authoritative for business behavior.

DESIGN.md must never override:

- canonical story rules
- workflow rules
- database rules
- permissions
- business state transitions

When existing Filament UI conflicts with DESIGN.md,
report the conflict before performing a large redesign.

---

# END OF AGENTS.md
