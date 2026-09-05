# Memory & Context Design — 单人精简版

> 路径：`docs/architecture/memory-context.md`
>
> 基线：`AGENTS.md`、`docs/PRD.md`、`docs/architecture/data-model.md`、`docs/architecture/story-engine.md`、`docs/architecture/generation-pipeline.md`

## 1. 目标

回答：写下一章/Scene 时，AI 到底应该知道什么？

目标不是塞入整本小说，而是提供足够、相关、可靠、可追踪、不冲突的上下文。

核心原则：

```text
Canonical Facts > Memory
Relevance > Quantity
Structured State > Vector Similarity
Recent Context != Long-term RAG
```

## 2. 五层记忆

```text
L0 Hard Constraints
L1 Current State
L2 Recent Story
L3 Long-term Memory
L4 Style
```

### L0 Hard Constraints

来源：Bible Hard Constraints、Locked Facts、World Hard Rules、Ending Contract、Must/Must Not Constraints。

规则：始终注入；不依赖向量检索；不因 Token Budget 删除；与其他 Memory 冲突时 L0 胜出。

### L1 Current State

权威来源 `story_state_versions`。包含人物、关系、地点、关键物品、时间线、Open Threads、Foreshadowing、Reader Promises。

`characters.current_state`、`world_entities.current_state`、`foreshadowings` 只是 Projection。冲突时 Canonical Story State 胜出。

### L2 Recent Story

默认最近 5~20 章，由 `recent_chapter_window` 配置。优先 Chapter Summary、Recent Story Events、Reader Promises、Conflict Changes、Previous Chapter Ending、Previous Scene Tail。近期剧情优先 SQL，不走 pgvector。

### L3 Long-term Memory

解决几十/几百章前的重要事件、人物经历、地点历史、关系变化、早期伏笔和承诺。存储为 `memories + pgvector`。它是召回层，不是事实层。

### L4 Style

POV、Narrative Voice、Tone、Forbidden Expressions、Style Examples。MVP 优先存在 Bible/Prompt Config，不建独立 Style Memory 表。

## 3. Context 优先级

```text
1 System / Output Schema
2 L0 Hard Constraints
3 Ending Contract
4 Chapter / Scene Plan
5 L1 Current State
6 Relevant Character / World Data
7 Due Foreshadowings
8 Required / Forbidden Facts
9 L2 Recent Story
10 Previous Scene Tail
11 L3 Long-term Memory
12 L4 Style Examples
13 Task Instruction
```

## 4. ContextBuilder

核心入口建议：

```php
ContextBuilder::build(ContextRequest $request): ContextSnapshot
```

ContextRequest 至少包含：

```text
novelId
chapterId
sceneId?
taskType
stateVersion
chapterPlanId
tokenBudget
```

## 5. ContextSnapshot

一次模型调用的上下文必须冻结。至少记录：

```text
schema_version
novel_id / chapter_id / scene_id
task_type
bible_version
state_version
chapter_plan_id
character_ids
world_entity_ids
foreshadowing_ids
fact_ids
memory_ids
recent_chapter_ids
previous_artifact_id
prompt_version
model
token_budget
token_allocation
truncated_sections
```

MVP 存于 `generation_runs.context_snapshot`。

没有 Snapshot 就无法解释模型为什么写出某内容，也无法判断是否使用了过期 State 或错误 Memory。

## 6. Memory 写入

正式 Memory 只能来自 Canonical Chapter、Canonical Story Event、Canonical State。Draft、Rejected Draft、Rewrite Candidate 不得创建正式长期 Memory。

Memory Types 第一版保持：

```text
event
character_milestone
relationship
world
location
item
foreshadowing
arc
reader_promise
important_dialogue
```

一条 Memory 应短、明确、有来源、可独立理解、可检索、有适用时间。不要整章只生成一个超长 Memory，也不要一句话生成一条无价值 Memory。

推荐粒度：一个重要事件、人物里程碑、关键关系变化、世界规则揭示、伏笔动作、Arc 里程碑。

## 7. Memory Creation Pipeline

Canonical Commit 后：

```text
UpdateMemoryJob
→ Read Canonical Chapter + Active Events + New State
→ Generate Memory Candidates
→ Schema Validation
→ Source Validation
→ Deduplication
→ Persist Memory
→ GenerateEmbeddingJob
```

示例：

```json
{
  "type": "character_milestone",
  "summary": "刘据首次独立主持赈灾，并因此获得关中士族的公开支持。",
  "entities": {"characters":["..."],"locations":["..."],"arcs":["..."]},
  "salience": 0.9,
  "source_type": "story_event",
  "source_id": "..."
}
```

## 8. Salience

范围 0..1，仅用于排序，不代表事实权威。

建议初始语义：

```text
0.9~1.0 核心剧情 / Critical Foreshadowing
0.7~0.9 重要长期信息
0.4~0.7 普通可复用事件
<0.4    通常不值得长期保存
```

具体阈值通过 Evaluation 调整。

## 9. Memory Status / Validity

MVP 状态：

```text
active
invalid
```

Retrieval 只查 active。

`valid_from_chapter / valid_to_chapter` 用于阶段性身份、关系、物品归属等。Rollback 后来自被回滚章的 Memory 设为 invalid，不物理删除。

正式故事纠正旧信息时，旧 Memory invalid，新 Memory active。

## 10. Deduplication

写入前考虑：

```text
novel_id
type
entities
source
normalized summary
semantic similarity
```

第一版策略：

```text
同 source + type → 不重复
高语义相似 + 同实体 → 跳过或保留更高价值一条
```

不要第一版做复杂聚类。无法安全 Merge 时宁可保留两条，不要丢失来源。

## 11. Embedding

MVP 只使用一个 Embedding Model 和固定维度。配置：

```text
AI_EMBEDDING_MODEL
AI_EMBEDDING_DIMENSIONS
```

`memories.embedding_model` 必须记录实际模型。

Embedding 失败：Memory 保留、embedding=null、Retry，不影响 Canonical Chapter。

不同 Embedding Model 的向量不得直接比较。更换模型时走 Re-embedding。

开发初期不急着建 HNSW/IVFFlat，数据增长并压测后再选。

## 12. Retrieval Query

Scene Retrieval Query 不应只是把 Scene Prompt 原文做 embedding。

输入应来自：

```text
Chapter Plan
Scene Plan
POV Character
Participants
Location
Current Goal
Conflict
Open Threads
Due Foreshadowings
Required Facts
```

建议 `MemoryQueryBuilder` 输出：

```text
query_text
entity_ids
types
chapter_range
required_tags
exclude_ids
```

不引入复杂 RAG Framework。

## 13. Metadata Filter First

Long-term Retrieval 必须先过滤：

```text
novel_id
status = active
embedding_model
entity/type（可用时）
validity window
chapter range
```

再做向量检索。禁止跨 Novel Search。

## 14. Hybrid Retrieval

第一版：

```text
Metadata Filter + Vector Similarity
```

第二阶段如果需要：

```text
PostgreSQL Full Text Search + Vector
```

不引入 Elasticsearch。

## 15. Ranking

候选排序可考虑：

```text
semantic similarity
salience
recency
entity match
arc relevance
foreshadowing urgency
source reliability
```

第一版可以从简单权重开始，例如：

```text
0.65 similarity + 0.20 salience + 0.15 recency
```

这只是初始值，必须通过 Evaluation 调整。

Critical/Due Foreshadowing 可 Boost，但它本身仍应结构化强注入，不依赖 RAG。

与当前 Participants、Location、Arc 直接相关的 Memory 优先。

Recency 不得压倒 Salience：100 章前的 Critical Secret 可能比上一章普通对话重要。

## 16. 去冗余

Top-K 不能全是同一事件的不同摘要。

第一版：

```text
相同 source 去重
高相似 Memory 去重
同一事件最多 1~2 条
```

只有真实出现重复问题后再实现 MMR。

配置：

```text
memory_candidate_k
memory_final_k
```

例如 candidate 30、final 8~15；实际值通过 Evaluation 调整。

## 17. Token Budget

按类别分配，而不是简单从尾部截断：

```text
system/schema
hard_constraints
plan
current_state
entities
foreshadowing/facts
recent_story
previous_scene
long_term_memory
style
```

不足时优先压缩：

```text
L3 Long-term Memory
→ L4 Style Examples
→ 较旧 L2 Summaries
```

永远保留 L0、关键 L1、Required/Forbidden Facts、Current Scene Plan。

## 18. Recent Story Compression

建议：

```text
上一章：较详细 Summary + Ending
最近 3~5 章：标准 Summary
更早近期窗口：短 Summary
```

Previous Scene Tail 是高优先级短期 Context，用于语气、空间、动作、对话连续性。

不要默认把最近 10 章全文塞进 Context。优先 Summary + Events + State；只有明确需要原文细节时按需获取。

## 19. Character / Knowledge Context

相关人物 Context：

```text
Static Profile
+ Current Canonical State
+ Relevant Locked Facts
+ Recent Events
+ Retrieved Long-term Memory
```

必须区分 Writer Knowledge 与 Character Knowledge。

模型作为 Writer 可以知道全局信息，但 POV Character 不得基于其知识边界之外的信息行动。

`must_not_reveal` 属于强约束。

## 20. World Context

只注入当前 Scene 相关 Location、Faction、Item、World Rule。不要每个 Scene 注入整个 World Bible。

相关 Hard World Rules 必须强注入。

## 21. Foreshadowing

分为：

```text
Due
Relevant
Background
```

Due/Critical：结构化强注入。
Relevant：可通过 Retrieval。
Background：通常不进入 Context。

Open Reader Promise 也应作为 L1/L2 结构化 Context，而非只依赖 Vector Memory。

## 22. Arc / Ending Context

Active Arc 必须提供 goal、stakes、beats、progress、completion_conditions。

非当前 Arc 只有与 Scene 强相关时注入。

`generating` 时注入 Ending Contract 必要约束；`completing` 时提高 Ending Context 权重并加入 Closure Debt、Remaining Arcs、Required Payoffs、Forbidden New Threads。

## 23. Context Staleness

Snapshot 绑定：

```text
state_version
bible_version
plan_version
```

Commit 前如果 Current State Version 已变化：

```text
旧 Context = stale
```

必须 Rebuild Context / Re-review，不得直接 Commit。

## 24. Cache

可缓存：

```text
Bible by version
Story State by version
Character Profile
World Entity
```

Key 必须版本化，例如：

```text
novel:{id}:state:{version}
```

Redis 只做 Hot Cache。Context Snapshot 最终必须进 PostgreSQL。Redis 丢失后仍能解释历史生成。

## 25. Inspectors

Memory Inspector 后续至少展示：

```text
Query
Filters
Candidate Memories
Similarity
Salience
Final Score
Source Chapter/Event
Selected / Rejected Reason
```

Generation Run 的 Context Inspector 展示：

```text
Context Snapshot
Token Allocation
Truncated Sections
Selected Memories
State Version
Prompt Version
```

MVP 先保证可读，不追求复杂图形化。

## 26. Retrieval Trace

第一版不建独立 Retrieval Log 表。

最终选中的：

```text
memory_ids
selection metadata
```

写入 Context Snapshot。

后续 Evaluation 真有需要，再增加 Retrieval Trace Artifact。

## 27. Evaluation Dataset

必须建立固定 Retrieval 测试集，至少包含：

```text
早期关键事实
早期伏笔
人物知识边界
地点历史
物品归属
关系变化
同名/相似实体
错误干扰 Memory
Rollback 后 invalid Memory
```

每个 Case 定义：

```text
query context
expected memory ids / expected facts
forbidden memory ids
```

## 28. Evaluation Metrics

第一阶段关注：

```text
Recall@K
Critical Memory Hit Rate
Invalid Memory Leakage
Cross-Novel Leakage
Context Token Usage
Selected Memory Redundancy
```

不需要第一版建立复杂 MRR/NDCG Dashboard，但可以保留未来扩展。

## 29. Context Evaluation

除了“是否召回”，还要检查最终 Context：

```text
Hard Constraints 是否完整
Current State 是否正确
Due Foreshadowing 是否存在
Required Facts 是否存在
Forbidden Facts 是否存在
是否混入 invalid Memory
是否超 Token Budget
```

## 30. Memory Rebuild

提供：

```text
memory:rebuild {novel}
```

用途：

```text
Embedding Model Migration
Memory Policy Change
Rollback Repair
Debug
```

默认支持 dry-run/分批处理。

不要让 Rebuild 修改 Canonical Story State。

## 31. Re-embedding

更换 Embedding Model：

```text
记录新 model
→ 批量生成新 embedding
→ 验证 Retrieval
→ 切换 active model
```

MVP 因为只保留一个 vector 列/模型，模型维度改变时需要 Migration + Rebuild。

这是显式维护操作，不做自动透明迁移。

## 32. Failure Handling

```text
Memory extraction failed
→ Retry Post-Commit Job

Embedding failed
→ Keep Memory + Retry

Vector query failed
→ Fallback to structured + recent context

No long-term result
→ Continue without L3

Context exceeds budget
→ Deterministic trimming

State unavailable
→ BLOCK generation
```

L3 失败不能导致系统失去 L0/L1。

## 33. Security / Prompt Injection Boundary

Memory 和历史正文属于模型输入数据，不是系统指令。

Context Builder 必须明确分区：

```text
System Instructions
Trusted Structured Constraints
Retrieved Story Data
Task
```

Retrieved Memory 中即使出现类似“忽略之前规则”的文本，也只能当小说内容处理。

## 34. Performance

单人项目的 Memory 规模即使达到数万/十万条，PostgreSQL + pgvector 足够。

MVP 不做：

```text
Separate Vector DB
Elasticsearch
Sharding
Partition
Distributed Retrieval
```

先通过真实数据和 EXPLAIN/压测确认瓶颈。

## 35. Implementation Batches

```text
M1 Context DTO + ContextSnapshot + Budget
M2 L0/L1 Structured Context
M3 L2 Recent Story
M4 Memory Creation + Dedup
M5 Embedding Pipeline
M6 L3 Vector Retrieval + Metadata Filter
M7 Ranking + Dedup + Token Assembly
M8 Inspectors / Evaluation
M9 Rollback Invalidity + Rebuild / Re-embedding
```

每批完成测试后再继续。

## 36. Codex 第一任务

```text
阅读：

AGENTS.md
docs/PRD.md
docs/architecture/data-model.md
docs/architecture/story-engine.md
docs/architecture/generation-pipeline.md
docs/architecture/memory-context.md

当前只实现 Memory/Context Batch M1：

- ContextRequest DTO
- ContextSnapshot DTO/schema
- TokenBudget / TokenAllocation
- ContextBuilder 基础骨架

要求：

1. 先检查现有项目。
2. 先输出计划，不修改代码。
3. 不实现 pgvector Retrieval。
4. 不实现 Embedding。
5. 不实现 Memory 写入。
6. 不实现 Filament Inspector。
7. Snapshot 必须绑定 state/bible/plan/prompt/model 版本。
8. Token Budget 必须支持按 section 分配。
9. 添加 DTO/schema/budget 测试。
10. 遵守单人精简原则。
```

## 37. Definition of Done

完整 Memory & Context 阶段完成时：

```text
L0 永远注入
L1 来自 Canonical State
L2 不依赖 Vector
L3 只召回 active Memory
L4 可按预算裁剪
Context Snapshot 可复现
Knowledge Boundary 不泄漏
Critical Foreshadowing 不漏
Rollback Memory 不再召回
Embedding Failure 不影响 Canonical
Hybrid Retrieval 可评测
Token Budget 可解释
```

## 38. Final Invariants

```text
1. Canonical structured facts always outrank memory.
2. L0 constraints are never dropped for token savings.
3. Current State comes from the requested canonical state version.
4. Draft content never creates official long-term memory.
5. Invalid/rolled-back memory is never retrieved.
6. Retrieval never crosses novel boundaries.
7. Character knowledge boundaries are preserved.
8. Context selection is traceable through ContextSnapshot.
9. Long-term RAG failure does not remove structured context.
10. Embedding failure never rolls back canonical content.
11. Context is optimized for relevance, not maximum size.
12. Retrieval quality is evaluated with fixed test cases.
```

# END OF memory-context.md
