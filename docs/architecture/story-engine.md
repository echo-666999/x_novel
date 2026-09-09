# Story Engine Design — 单人精简版

> 建议路径：`docs/architecture/story-engine.md`
>
> 基线：`docs/PRD.md`、`docs/architecture/data-model.md`
>
> 目标：定义 Story Event、Story State、State Patch、冲突校验、Canonical Commit、状态重建与最新章回滚。

---

# 1. 核心目标

Story Engine 只回答两个核心问题：

```text
这一章正式发生了什么？
```

以及：

```text
这一章结束后，故事世界现在是什么状态？
```

它不负责创作正文，而负责把已经通过 Review 的正文转换为可验证、可版本化、可恢复的故事事实。

权威链路：

```text
Chapter Draft
    ↓
Event Extraction
    ↓
StoryEventCandidate[]
    ↓
State Patch
    ↓
State Validator
    ↓
Review
    ↓
PASS
    ↓
Canonical Commit
    ↓
Story Events
    ↓
Story State Version N+1
```

---

# 2. 核心原则

## 2.1 Canonical Only

只有 Canonical Chapter 可以产生正式：

- Story Event
- Story State Version
- Fact Change
- Foreshadowing Change

Draft、Rewrite Candidate、Rejected Draft 都不得修改正式状态。

## 2.2 Event 与 State 分离

Story Event 表示：

> 发生了什么。

Story State 表示：

> 现在是什么状态。

例如：

```text
Event:
角色甲从长安去了洛阳

State:
characters[甲].location = 洛阳
```

## 2.3 Event Append-only

正式 Story Event 默认不可覆盖。

历史错误优先通过：

```text
event_corrected
event_invalidated
```

处理。

## 2.4 State Version Immutable

每次正式提交创建新版本：

```text
State N
↓
State N+1
```

旧版本不修改。

## 2.5 Structured Facts Win

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
Domain Projection
↓
Vector Memory
```

pgvector 永远不能覆盖正式事实。

---

# 3. 核心组件

MVP 只实现：

```text
StoryEventExtractor
StatePatchBuilder
StateValidator
StoryStateService
CanonicalCommitService
StoryStateRebuilder
RollbackLatestChapterAction
```

不要拆成复杂 Event Sourcing Framework。

---

# 4. Story Event Candidate

Event Extractor 输出候选事件，而不是直接写 `story_events`。

建议 DTO：

```php
final readonly class StoryEventCandidate
{
    public function __construct(
        public string $eventType,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $payload,
        public array $evidence,
        public ?string $storyTime,
        public float $confidence,
    ) {}
}
```

候选事件只有通过验证并进入 Canonical Commit 后，才成为正式 Story Event。

---

# 5. MVP Event Types

## Character

```text
character_status_changed
character_moved
character_injured
character_recovered
character_goal_changed
character_emotion_changed
character_learned
character_forgot
character_ability_acquired
character_ability_changed
```

## Relationship

```text
relationship_changed
promise_made
promise_broken
debt_created
debt_resolved
```

## Item

```text
item_acquired
item_transferred
item_lost
item_destroyed
item_state_changed
```

## Plot

```text
conflict_started
conflict_escalated
conflict_resolved
thread_opened
thread_progressed
thread_closed
reader_promise_created
reader_promise_resolved
```

## Foreshadowing

```text
foreshadowing_planted
foreshadowing_reinforced
foreshadowing_due
foreshadowing_paid_off
foreshadowing_abandoned
```

## World

```text
world_rule_revealed
world_rule_changed
location_state_changed
faction_state_changed
world_state_changed
```

## Correction

```text
event_corrected
event_invalidated
manual_correction
```

第一版不要继续细分几百种 Event Type。

---

# 6. Event Payload

不同 Event Type 使用不同 payload。

例如：

```json
{
  "event_type": "character_moved",
  "subject_type": "character",
  "subject_id": "uuid",
  "payload": {
    "from": "长安",
    "to": "洛阳",
    "reason": "奉诏入京"
  }
}
```

---

# 7. Evidence

每个关键事件必须能追踪正文来源。

MVP：

```json
{
  "artifact_id": "uuid",
  "scene_id": "uuid",
  "quote": "他终于抵达洛阳城下。",
  "start_offset": 1024,
  "end_offset": 1035
}
```

offset 可以暂时可选，但 `artifact_id` 与正文证据必须可追踪。

---

# 8. Ambiguous Event

文本只是暗示时，不能直接形成 Hard Fact。

例如：

```text
“他似乎已经死了。”
```

不能直接：

```text
character.status = dead
```

应该：

```text
ambiguous finding
```

或保留低置信候选事件，等待后文确认。

---

# 9. State Patch

State Patch 表示：

> 如果候选事件成立，当前 Story State 应该怎样变化。

建议：

```php
final readonly class StatePatch
{
    public function __construct(
        public int $expectedStateVersion,
        public array $operations,
        public array $factChanges = [],
        public array $foreshadowingChanges = [],
    ) {}
}
```

---

# 10. Patch Operations

MVP 不实现完整 RFC JSON Patch。

只支持：

```text
set
unset
increment
append_unique
remove
```

例如：

```json
{
  "op": "set",
  "path": "characters.<uuid>.location",
  "value": "洛阳",
  "source_event_index": 2
}
```

模型不能创建未知顶层 State Domain。

---

# 11. Story State Schema

MVP：

```json
{
  "schema_version": 1,
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

Story State 保存“当前重要状态”，不是整本小说历史。

---

# 12. Character State

示例：

```json
{
  "status": "alive",
  "location": null,
  "health": {
    "state": "normal",
    "notes": null
  },
  "abilities": {},
  "resources": {},
  "knowledge": {},
  "goals": [],
  "emotion": null,
  "identity": {},
  "flags": {}
}
```

静态人物档案仍由 `characters` 表保存。

---

# 13. Relationship State

MVP 使用 JSONB：

```json
{
  "A:B": {
    "type": "friend",
    "trust": 0.7,
    "intimacy": 0.4,
    "hostility": 0,
    "promises": [],
    "debts": []
  }
}
```

暂不建立独立 `relationships` 表。

---

# 14. Item State

只追踪关键物品：

```json
{
  "item_uuid": {
    "owner_type": "character",
    "owner_id": "uuid",
    "location": null,
    "status": "intact"
  }
}
```

普通无剧情价值物品不进入 Story State。

---

# 15. Timeline State

保持简单：

```json
{
  "current_time": "建元三年三月",
  "current_day_index": 120,
  "anchors": {}
}
```

如果小说不强调绝对时间，`current_day_index` 可以为空。

---

# 16. Foreshadowing State

```json
{
  "foreshadowing_uuid": {
    "status": "reinforced",
    "reinforce_count": 2,
    "last_chapter": 38,
    "due_from": 50,
    "due_to": 70
  }
}
```

详情仍以 `foreshadowings` 表为主。

---

# 17. StateValidator

校验优先级：

```text
Deterministic Rules
↓
LLM Semantic Judge
```

确定性规则能判断的问题，不交给模型猜。

---

# 18. Conflict Severity

MVP：

```text
hard
soft
ambiguous
intentional
```

## hard

禁止 Canonical Commit。

## soft

进入 Review 分数。

## ambiguous

证据不足，不升级成正式硬事实。

## intentional

Plan 明确声明的梦境、幻觉、伪装、假死、时间跳跃等特殊叙事。

---

# 19. Finding DTO

建议：

```php
final readonly class StateFinding
{
    public function __construct(
        public string $code,
        public string $severity,
        public string $message,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public array $evidence = [],
        public array $metadata = [],
    ) {}
}
```

---

# 20. Hard Conflict Rules

第一版至少实现：

```text
STATE_VERSION_CONFLICT
LOCKED_FACT_CONFLICT
CHARACTER_DEAD_CONFLICT
KNOWLEDGE_CONFLICT
LOCATION_CONFLICT
ITEM_OWNERSHIP_CONFLICT
ABILITY_CONFLICT
WORLD_RULE_CONFLICT
INVALID_EVENT_REFERENCE
INVALID_STATE_PATCH
```

---

# 21. Character Death Rule

若：

```text
character.status = dead
```

后续普通场景出现：

```text
说话
移动
战斗
持有物品
```

则：

```text
CHARACTER_DEAD_CONFLICT
```

除非 Plan 明确声明：

```text
resurrection
false_death_revealed
illusion
flashback
dream
time_shift
```

---

# 22. Knowledge Rule

若角色当前不知道某秘密：

```text
X does not know fact Y
```

却直接基于 Y 行动，且不存在：

```text
character_learned
```

事件，则：

```text
KNOWLEDGE_CONFLICT
```

---

# 23. Location Rule

例如当前：

```text
X.location = 长安
```

下一 Scene 突然在洛阳。

若没有：

```text
character_moved
```

或合理时间跳跃，则产生：

```text
LOCATION_CONFLICT
```

MVP 不做 GIS 和真实道路计算，只阻断明显错误。

---

# 24. Item Ownership Rule

当前：

```text
item.owner = A
```

正文直接由 B 持有，且没有：

```text
item_transferred
item_lost
item_acquired
```

事件，则：

```text
ITEM_OWNERSHIP_CONFLICT
```

---

# 25. Ability Rule

角色使用尚未获得的能力：

```text
ABILITY_CONFLICT
```

如果属于“原本拥有但第一次揭示”，必须在 Chapter Plan 中显式声明 reveal。

---

# 26. World Rule

Locked World Rule 最高优先级。

例如：

```text
本世界不存在复活
```

正文真实复活：

```text
WORLD_RULE_CONFLICT
```

---

# 27. Locked Facts

Commit 前必须读取：

```text
facts.locked = true
AND status = active
```

任何候选 Event / Patch 与之冲突：

```text
BLOCK
```

---

# 28. StatePatchBuilder

推荐：

```text
Event Candidate
↓
Deterministic Event Applier
↓
State Patch
```

不要让 LLM 自由决定所有 State Mutation。

例如：

```text
character_moved
→ set characters.X.location
```

属于确定性映射。

---

# 29. Event Applier

建议简单接口：

```php
interface StoryEventApplier
{
    public function supports(string $eventType): bool;

    public function apply(
        array $state,
        StoryEventCandidate $event
    ): array;
}
```

第一版只为真正需要改变 State 的事件实现 Applier。

---

# 30. StoryStateService

职责：

```text
current(novel)
findVersion(novel, version)
previewPatch(state, patch)
diff(before, after)
```

不负责：

```text
AI 调用
Queue
Review
Canonical Commit
```

---

# 31. Initial State Version 0

Novel 第一次进入生成前，应建立：

```text
Story State Version 0
```

来源：

```text
Bible
Characters
World Entities
Locked Facts
Foreshadowing Initial State
```

这样第一章生成可以明确引用：

```text
state_version = 0
```

---

# 32. Data Model Revision — Version 0

`story_state_versions.version` 原设计：

```sql
CHECK (version > 0)
```

需要改成：

```sql
CHECK (version >= 0)
```

这是 Story Engine 的第一项数据模型修订。

---

# 33. InitializeNovelStateAction

建议：

```text
InitializeNovelStateAction
```

职责：

```text
检查 Novel 尚无 State
↓
读取 Bible / Character / World / Facts
↓
生成 State 0
↓
保存 checksum
↓
更新 novel.canonical_state_version_id
```

必须幂等。

重复执行：

返回已有 State 0。

---

# 34. Canonical Commit Input

建议 DTO：

```php
final readonly class CanonicalCommitData
{
    public function __construct(
        public string $novelId,
        public string $chapterId,
        public string $artifactId,
        public string $reviewId,
        public int $expectedStateVersion,
        public array $eventCandidates,
        public StatePatch $statePatch,
    ) {}
}
```

---

# 35. Commit Preconditions

必须全部满足：

```text
Novel generating / completing
Novel not paused
Chapter not canonical
Artifact exists
Artifact checksum unchanged
Review PASS
No hard state finding
Expected State Version matches
Idempotency valid
```

---

# 36. Canonical Commit Transaction

```text
BEGIN

SELECT novel FOR UPDATE

Reload current state pointer

Validate expected state version

SELECT chapter FOR UPDATE

Validate chapter not canonical

Validate Review PASS

Validate Artifact

Persist Story Events

Apply Fact Changes

Create Story State Version

Update Chapter canonical pointer

Update Novel current pointers

COMMIT
```

这个事务中不调用 LLM。

---

# 37. Why PostgreSQL Lock

MVP 同一个 Novel 的 Canonical Commit 必须串行。

使用：

```text
SELECT ... FOR UPDATE
+
State Version Check
+
Unique Constraint
```

足够。

Redis Lock 可以作为外围优化，但不作为最终一致性保证。

---

# 38. Commit Idempotency

推荐 Key：

```text
commit:{chapter_id}:{artifact_checksum}:{expected_state_version}
```

重复执行同一提交：

应该直接返回已有结果。

---

# 39. Conflicting Duplicate

如果 Chapter 已 Canonical：

且新请求 Artifact checksum 与正式版本不同：

```text
BLOCK
```

必须走：

```text
Manual Edit
或
Rollback
```

不能直接覆盖。

---

# 40. State Version

版本必须单调递增：

```text
0
1
2
3
...
```

但：

```text
State Version != Chapter Number
```

尤其在 Rollback 后两者可能不同。

---

# 41. State Checksum

推荐：

```text
Canonical JSON Serialization
+
SHA-256
```

用于：

```text
Rebuild Validation
Change Detection
Debug
```

---

# 42. Post-Commit Work

Canonical Commit 成功后异步：

```text
Update Memory
Generate Embedding
Generate Chapter Summary
Refresh Projection
Refresh Cache
Update Metrics
```

这些失败：

```text
不得回滚正文
```

---

# 43. Projection

MVP Projection：

```text
characters.current_state
world_entities.current_state
foreshadowings.status
```

它们用于 UI 和快速查询。

权威仍然是：

```text
Story State
+
Story Events
```

Projection 失败时可重建。

---

# 44. StoryStateRebuilder

职责：

```text
验证当前 Story State 是否可以由历史正式事件重建
```

---

# 45. Rebuild Strategy

```text
State Version 0
↓
active Story Events
↓
按正式提交顺序 replay
↓
得到 rebuilt state
↓
计算 checksum
↓
与 current state 比较
```

第一版完全重放即可。

数据量很小，不需要复杂 Snapshot Optimization。

---

# 46. Rebuild Command

建议：

```bash
php artisan story:rebuild-state {novel} --dry-run
```

未来可增加：

```text
--from-version=
--replace
```

默认：

```text
dry-run
```

不直接覆盖正式状态。

---

# 47. Latest Chapter Rollback

MVP 只支持：

```text
回滚最新 Canonical Chapter
```

不支持任意历史分支。

---

# 48. Rollback Preconditions

目标 Chapter 必须：

```text
chapter.id = novel.current_chapter_id
```

否则拒绝。

---

# 49. Rollback Flow

例如当前：

```text
Chapter 51
Current State Version 51
```

回滚：

```text
找到 Chapter 50 对应有效 State
↓
Chapter 51 离开 canonical
↓
Chapter 51 Story Events invalidated
↓
Novel pointer 恢复
↓
Memory from Chapter 51 invalidated
↓
Fact projection rebuild
↓
Foreshadowing projection rebuild
```

旧数据不物理删除。

---

# 50. Data Model Revision — Story Event Status

`story_events` 需要新增：

```text
status varchar(32) NOT NULL DEFAULT 'active'
invalidated_at timestamptz NULL
```

状态只需要：

```text
active
invalidated
```

这是第二项数据模型修订。

---

# 51. State Version After Rollback

State Version 不重复使用。

例如已经产生过：

```text
version 51
```

即使回滚，也不要再次创建第二个 51。

重新提交时：

```text
version 52
```

因此 State Version 表示：

```text
状态提交历史
```

不是章节号。

---

# 52. Data Model Revision — Chapter Mapping

因为同一 Chapter 在 Rollback 后可能重新 Canonical：

`story_state_versions` 原本：

```sql
UNIQUE (novel_id, chapter_id)
```

必须删除。

保留：

```sql
UNIQUE (novel_id, version)
```

这是第三项数据模型修订。

---

# 53. Re-canonicalize Chapter

Rollback 后同一章允许：

```text
重新规划
重新生成
重新 Review
重新 Canonical
```

新的：

```text
Artifact
Story Events
Story State Version
```

全部保留历史。

---

# 54. Memory Rollback

来自被回滚 Chapter 的 Memory：

```text
status = invalid
```

不删除。

Retrieval 必须过滤：

```text
status = active
```

---

# 55. Fact Rollback

Fact 如果来源于被 invalidated Story Event：

需要失效或重建。

因此建议 `facts` 增加：

```text
source_type varchar(32)
```

值：

```text
manual
story_event
bible
```

这是第四项数据模型修订。

---

# 56. Manual Facts

用户手工锁定：

```text
source_type = manual
```

Rollback 不自动删除。

---

# 57. Derived Facts

Story Event 派生：

```text
source_type = story_event
```

对应 Event 失效后：

Fact Projection Rebuild 可以正确处理。

---

# 58. Validation Pipeline

推荐顺序：

```text
Schema Validation
↓
Reference Validation
↓
State Version
↓
Locked Facts
↓
Character Status
↓
Knowledge
↓
Location
↓
Items
↓
Abilities
↓
World Rules
↓
Foreshadowing
↓
Semantic Review
```

---

# 59. Reference Validation

任何：

```text
character_id
world_entity_id
foreshadowing_id
```

都必须属于当前：

```text
novel_id
```

防止跨 Novel 数据污染。

---

# 60. Review Integration

最终：

```text
Review PASS
```

必须同时满足：

```text
Narrative Review PASS
+
No Hard State Conflict
```

Hard Conflict 不能被高总分抵消。

---

# 61. Event Extraction Timing

推荐：

```text
Assemble Chapter
↓
Extract Event Candidates
↓
Build State Patch
↓
Validate State
↓
Narrative Review
↓
Final Review Decision
↓
Commit
```

实现时可以将 Narrative Review 和 State Validation 并行，但 Commit 前必须全部完成。

---

# 62. Event Candidate Artifact

候选事件保存：

```text
generation_artifacts.type = event_candidate
```

---

# 63. State Patch Artifact

候选 Patch 保存：

```text
generation_artifacts.type = state_patch
```

这样失败后可以：

```text
Debug
Review
Retry
Resume
```

---

# 64. Formal Story Events

Canonical Commit 时：

从验证通过的 Event Candidate：

```text
复制
```

为正式：

```text
story_events
```

不能直接把 Artifact 当正式事件库。

---

# 65. Context Builder Integration

Context Builder 获取当前 Story State：

统一通过：

```text
StoryStateService
```

不要在各 Job 中自己拼不同版本状态。

---

# 66. Hard Facts Injection

Locked Facts 必须始终进入 Context。

不能依赖 pgvector 恰好召回。

---

# 67. Due Foreshadowing

如果：

```text
due_from <= next chapter <= due_to
```

必须进入 Chapter Context。

Critical 伏笔还必须进入：

```text
Chapter Plan validation
```

---

# 68. Ending Controller Integration

Ending Controller 依赖：

```text
open_threads
foreshadowings
reader_promises
character state
story_arcs
ending_contract
```

因此这些状态必须是结构化数据，而不能全部只在自然语言摘要中。

---

# 69. Tests — State Version 0

必须测试：

```text
first initialization creates version 0
duplicate initialization returns existing state
initial state contains locked facts
initial state checksum stable
```

---

# 70. Tests — State Validator

至少：

```text
dead character conflict

knowledge conflict

location conflict

item ownership conflict

ability conflict

locked fact conflict

world rule conflict
```

---

# 71. Tests — State Patch

至少：

```text
character move

item transfer

knowledge gain

foreshadowing payoff

unknown patch path rejected
```

---

# 72. Tests — Canonical Commit

必须：

```text
normal commit

duplicate identical commit

conflicting duplicate

wrong expected state version

review not pass

hard conflict

chapter already canonical

transaction rollback
```

---

# 73. Transaction Failure Test

模拟：

```text
Story Events 已准备写入
↓
中途抛异常
```

最终必须：

```text
No Story Event persisted
No State Version persisted
Chapter unchanged
Novel pointer unchanged
```

---

# 74. Tests — Rebuild

```text
State 0
+
Active Events
↓
Rebuilt checksum
=
Current checksum
```

---

# 75. Tests — Rollback

必须：

```text
rollback latest canonical chapter

old events invalidated

novel pointer restored

memory invalidated

derived facts rebuilt

re-canonicalization works

state version remains monotonic
```

---

# 76. Golden Cases

必须长期保留：

```text
“角色不会游泳”
↓
正文熟练渡河
↓
BLOCK
```

```text
角色已经死亡
↓
下一章正常行动
↓
BLOCK
```

```text
角色不知道秘密
↓
直接利用秘密
↓
BLOCK
```

```text
关键物品没有转移事件
↓
突然出现在另一角色手中
↓
BLOCK
```

---

# 77. Performance

1000 章左右：

```text
1000 State Snapshots
数万 Story Events
```

对 PostgreSQL 很小。

MVP 直接保存完整 JSONB State Snapshot。

不做：

```text
Partition
Delta-only State
Separate State Database
```

---

# 78. Manual Correction

发现正式状态错误：

不要直接编辑：

```text
story_state_versions.state
```

推荐：

```text
Manual Correction Action
↓
Correction Event
↓
Validate
↓
New State Version
```

保证历史可追踪。

---

# 79. Filament Story Inspector

后续可以提供只读：

```text
Current State
State Versions
Story Events
Facts
State Diff
```

用于单人 Debug。

不需要第一版做复杂关系图。

---

# 80. Data Model Revisions Summary

实现 Story Engine 前，同步修改 `data-model.md`：

## Revision 1

```sql
story_state_versions.version >= 0
```

支持 State Version 0。

## Revision 2

`story_events` 新增：

```text
status
invalidated_at
```

## Revision 3

删除：

```sql
UNIQUE (novel_id, chapter_id)
```

允许回滚后同章再次 Canonical。

## Revision 4

`facts` 新增：

```text
source_type
```

支持：

```text
manual
story_event
bible
```

这些修改属于初始架构完善，不需要单独 ADR。

---

# 81. Implementation Batches

## S1 — State Foundation

```text
Story State Schema
Initial State Version 0
StoryStateService
InitializeNovelStateAction
```

## S2 — Event Candidate

```text
StoryEventCandidate DTO
Event Type Enum
StoryEventExtractor Contract
Fake Extractor
```

## S3 — State Patch

```text
StatePatch DTO
StatePatchBuilder
Basic Event Appliers
```

## S4 — Validation

```text
StateValidator
Finding Codes
Hard Conflict Rules
```

## S5 — Canonical Commit

```text
CanonicalCommitService
Transaction
Idempotency
State Version Creation
```

## S6 — Rebuild

```text
StoryStateRebuilder
story:rebuild-state
```

## S7 — Rollback

```text
RollbackLatestChapterAction
Event Invalidation
Memory Invalidation
Fact Rebuild
```

---

# 82. Do Not Implement Yet

Story Engine 阶段暂不做：

```text
Advanced RAG
Multi-Agent
Complex LLM Judge
Scene Parallel
Knowledge Graph
Timeline Engine
Relationship Graph
Arbitrary History Rollback
```

---

# 83. Codex First Task

第一轮只做 S1：

```text
阅读：

AGENTS.md
docs/PRD.md
docs/architecture/data-model.md
docs/architecture/story-engine.md

当前只实现 Story Engine Batch S1：

- Story State Schema
- Initial State Version 0
- StoryStateService
- InitializeNovelStateAction

要求：

1. 检查当前已有代码。
2. 先输出实现计划，不修改代码。
3. 同步 data-model.md 的 version >= 0 修订。
4. 定义 Story State schema / DTO 策略。
5. 实现 Initial State Version 0。
6. 实现获取 Current Story State。
7. 实现读取指定 State Version。
8. 实现初始化幂等。
9. 添加数据库与 Service 测试。
10. 不实现 Event Extractor。
11. 不实现 StateValidator。
12. 不实现 Canonical Commit。
13. 不实现 RAG。
14. 不实现 Filament。
```

---

# 84. Definition of Done

Story Engine 完整完成时必须达到：

```text
Initial State Version 0 works

Story Event schema stable

Event Candidate is traceable

State Patch deterministic

Hard conflicts block commit

Canonical Commit atomic

Duplicate commit idempotent

State versions immutable

State rebuild works

Latest chapter rollback works

Memory rollback works

Golden conflict tests pass
```

---

# 85. Final Invariants

长期必须保持：

```text
1. Draft cannot mutate canonical state.

2. Only validated events become Story Events.

3. Story State is versioned and immutable.

4. Locked Facts override model suggestions.

5. Hard Conflict blocks Canonical Commit.

6. Canonical Commit is atomic.

7. Duplicate Commit has exactly-once effect.

8. State Version is monotonic.

9. Story State can be rebuilt.

10. Latest Chapter can be safely rolled back.

11. Vector Memory never becomes canonical fact.

12. Every state change is traceable to evidence.
```

# END OF story-engine.md
