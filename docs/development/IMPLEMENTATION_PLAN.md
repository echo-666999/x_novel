# XNovel 可执行开发任务计划 — 单人精简版

> 建议路径：`docs/development/IMPLEMENTATION_PLAN.md`
>
> 基线：`AGENTS.md`、`docs/PRD.md`、`docs/architecture/data-model.md`、`docs/architecture/story-engine.md`、`docs/architecture/generation-pipeline.md`、`docs/architecture/memory-context.md`
>
> 项目原则：单人开发、单人运营、小而精；任务尽量可独立实施、独立测试、独立验收，并尽可能在 Filament UI 中形成可见成果。

---

# 1. 如何使用这份计划

每次只把一个 Task ID 交给 Codex。

推荐提示：

```text
阅读：
- AGENTS.md
- docs/PRD.md
- 与 TASK-XXX 相关的 architecture 文档
- docs/development/IMPLEMENTATION_PLAN.md 中 TASK-XXX

当前只实施 TASK-XXX。

要求：
1. 先检查现有代码。
2. 先输出实施计划，不扩大范围。
3. 说明要修改的文件、数据库、状态、测试。
4. 实施完成后实际运行测试。
5. 按 Summary / Files Changed / Tests / Follow-ups 汇报。
```

状态建议：

```text
TODO
IN_PROGRESS
BLOCKED
DONE
```

优先级：

```text
P0 = MVP 主链路必需
P1 = MVP 可用性 / 可调试性必需
P2 = 稳定后再做
```

---

# 2. 总体里程碑

| Milestone | 核心目标 | 主要 Filament 可见成果 |
|---|---|---|
| M0 | 项目基线 | Dashboard / 导航 / Settings 基础 |
| M1 | Novel 基础 | Novel Workspace、Bible、Volume、Arc |
| M2 | 故事资产 | Characters、World、Foreshadowing |
| M3 | Story State | State Inspector、Facts、Locked Facts |
| M4 | Planning | Chapter / Scene / Plan 页面 |
| M5 | AI Gateway | Provider Settings、Test Connection、Usage |
| M6 | Generation | Runs、Scenes、Draft、Pipeline |
| M7 | Review | Review Inbox、Findings、Rewrite |
| M8 | Canonical | Commit、正式章、Story State 更新 |
| M9 | Memory | Memory Inspector、Context Inspector、pgvector |
| M10 | Automation | Auto Generate、Pause/Resume、Recovery |
| M11 | Ending | Closure Debt、Volume Gate、Ending Audit |
| M12 | 验收 | 100章长跑、成本、恢复、发布检查 |

---

# 3. Task Card 完成标准

每个 Task 至少要明确：

```text
目标
依赖
后端实现
Filament UI
测试
验收标准
完成定义
```

除纯基础设施 Task 外，原则上都要有一个用户可见结果。

---

# M0 — 项目骨架与 Filament UI 基线

## TASK-001 — 当前项目环境审计

**Skills：** `filament-ui`

**优先级：P0**

### 目标
确认当前 Laravel / Filament / Shield / PostgreSQL / Redis / Queue / pgvector 的真实状态，避免重复实现。

### 后端
- 检查 `composer.json`、`package.json`。
- 检查 Models、Migrations、Enums、Services、Jobs、Tests。
- 检查 Filament Panel / Shield。
- 检查 DB、Redis、Queue、Horizon。
- 检查 pgvector 是否可用。
- 只记录现状，不做架构重构。

### Filament UI
- 确认 Panel 可正常登录。
- 记录当前已有菜单与页面。

### 验收
生成：

```text
docs/development/CURRENT_STATE.md
```

内容包含：
- 已有能力
- 缺失能力
- 与 PRD 冲突
- 可复用组件
- 技术债

### 测试
- `php artisan about`
- 现有测试
- DB 连接
- Redis 连接
- Queue/Horizon 基础检查

### 完成定义
后续任务不再需要猜测当前项目结构。

---

## TASK-002 — Filament 导航与视觉基线

**Skills：** `filament-ui`

**依赖：TASK-001｜P1**

### 目标
建立单人工作台导航规范。

### Filament UI
一级导航控制为：

```text
Dashboard
Novels
Generation
Review
Memory
Settings
```

建立统一：
- Status Badge 色彩
- Empty State
- Error State
- Action 样式
- SlideOver 使用规范

### 验收
- 六个入口清晰。
- 同一 Status 在不同页面表现一致。
- 未完成模块使用 Empty State，不创建无意义 CRUD。

### 测试
- Filament 页面路由
- 基础权限 / super_admin

---

## TASK-003 — Dashboard 基础壳

**Skills：** `filament-ui`

**依赖：TASK-002｜P1**

### 目标
先建立后续可以持续填充的单人 Dashboard。

### Filament UI
初始卡片：

```text
Active Novels
Current Chapter
Today's Cost
Needs Attention
```

初始列表：

```text
Recent Generation
Due Foreshadowing
```

数据不存在时显示 Empty State。

### 验收
Dashboard 可作为日常入口，后续任务只填数据，不反复重做布局。

---

## TASK-004 — Settings 页面骨架

**Skills：** `filament-ui`

**依赖：TASK-002｜P1**

### 目标
建立后续 AI、生成、Review、Memory 参数的统一设置入口。

### UI Sections
```text
AI
Generation
Review
Memory
Budget
```

暂时可以只读 / placeholder。

### 验收
不建立复杂 settings 表；全局配置仍优先 `config/*.php` / `.env`。

---

# M1 — Novel / Bible / Volume / Arc

## TASK-010 — Novel 数据模型与列表

**Skills：** `filament-ui`

**依赖：TASK-001｜P0**

### 后端
- `novels` migration/model/factory。
- `NovelStatus` Enum。
- settings JSONB cast。
- target_words CHECK。
- status / updated_at indexes。

### Filament UI
`Novels`：
- title
- genre
- status
- target words
- current chapter
- updated_at

支持：
- Search
- Status Filter
- Create Novel
- Edit 基础信息

### 验收
可以从 UI 创建小说，并进入 Novel Workspace。

### 测试
- DB constraints
- Model casts
- Filament Create/Edit

---

## TASK-011 — Novel Workspace Overview

**Skills：** `filament-ui`

**依赖：TASK-010｜P1**

### UI
Workspace Overview：

```text
Title / Genre / Status
Target Words
Current Words
Current Volume
Current Chapter
Current State Version
Generation Status
Today Cost
Review Pass Rate
Rewrite Rate
Due Foreshadowings
Needs Attention
```

Actions 先保留：
- Generate Next Chapter
- Auto Generate
- Pause
- Resume

尚未实现时 disabled。

### 验收
绝大部分小说操作后续都可进入 Workspace，不需要大量顶级 Resource。

---

## TASK-012 — Novel Bible 版本管理

**Skills：** `filament-ui`

**依赖：TASK-010｜P0**

### 后端
- `novel_bibles`
- version unique
- BibleStatus
- CreateBibleVersionAction
- 历史不可覆盖

### UI
Workspace `Bible`：
- 当前 Bible
- themes
- tone
- POV
- tense
- taboos
- hard_constraints
- ending_contract
- 历史版本

Action：
```text
Create New Version
```

### 验收
每次重大修改产生新 version，旧版可查看。

### 测试
- unique(novel_id, version)
- version history
- current version resolution

---

## TASK-013 — Volume 管理

**Skills：** `filament-ui`

**依赖：TASK-010｜P0**

### 后端
- volumes
- sequence unique
- VolumeStatus

### UI
Workspace `Planning / Volumes`：
- sequence
- title
- goal
- climax
- target_words
- status
- progress

### 验收
可管理多卷；序号不重复。

---

## TASK-014 — Story Arc 管理

**Skills：** `filament-ui`

**依赖：TASK-013｜P0**

### 后端
- story_arcs
- progress CHECK 0..1
- Arc type/status

### UI
Volume 下展示 Arc：
- type
- title
- goal
- stakes
- beats
- progress
- completion conditions

### 验收
能一眼看到当前主线 / 支线推进情况。

---

## TASK-015 — Novel Workspace Planning 汇总视图

**Skills：** `filament-ui`

**依赖：TASK-013,014｜P1**

### UI
按：

```text
Volume
 └ Arc
    └ Progress / Beats
```

展示。

支持：
- Active only
- Completed
- All

### 验收
不进入数据库即可判断小说当前规划结构。

---

# M2 — Characters / World / Foreshadowing

## TASK-020 — Character 管理

**Skills：** `filament-ui`

**依赖：TASK-010｜P0**

### 后端
- characters
- JSONB casts
- locked_fields
- Character status

### UI
Workspace `Characters`：
- name
- role
- status
- current location
- important flags

SlideOver：
- profile
- personality
- motivation
- abilities
- knowledge
- current_state
- locked fields

### 验收
主要人物可在 Workspace 内管理，无需顶级菜单跳转。

---

## TASK-021 — World Entity 管理

**Skills：** `filament-ui`

**依赖：TASK-010｜P0**

### 后端
- world_entities
- type：location/item/faction/organization/rule/concept

### UI
Workspace `World`：
Tabs / Filters：
```text
Locations
Items
Factions
Rules
Other
```

SlideOver：
- description
- attributes
- rules
- current_state
- locked fields

### 验收
世界实体统一维护，不拆多个 Resource。

---

## TASK-022 — Foreshadowing 管理

**Skills：** `filament-ui`

**依赖：TASK-013｜P0**

### 后端
- foreshadowings
- status
- importance
- due window CHECK

### UI
Workspace `Foreshadowing`：
- title
- status
- importance
- setup chapter
- due window
- owner arc
- payoff chapter

Badge：
- Due
- Overdue
- Critical

### 验收
Critical / Due 伏笔明显可见。

---

## TASK-023 — Dashboard Due Foreshadowing Widget

**Skills：** `filament-ui`

**依赖：TASK-022,003｜P1**

### UI
Dashboard 展示：
```text
Due Soon
Critical Due
Overdue
```

点击进入对应 Novel / Foreshadowing。

### 验收
日常打开 Dashboard 即可知道哪些伏笔需要处理。

---

## TASK-024 — Character / World Quick Picker

**Skills：** `filament-ui`

**依赖：TASK-020,021｜P1**

### 目标
给后续 Plan、Fact、Event 页面提供统一实体选择。

### 后端/UI
建立可复用 Filament Select / Search 组件：
- 仅当前 Novel
- Searchable
- type aware

### 验收
后续页面不重复实现实体选择逻辑。

---

# M3 — Story State Foundation

## TASK-030 — Story State Version 0

**Skills：** `filament-ui`, `story-engine`

**依赖：TASK-012,020,021,022｜P0**

### 后端
- story_state_versions
- `version >= 0`
- Story State schema_version
- checksum
- `InitializeNovelStateAction`
- 初始化幂等

### UI
Novel Overview：
```text
Story State: Not Initialized
[Initialize Story State]
```

初始化后：
```text
Current State Version: 0
```

### 验收
重复点击不会创建多个 State 0。

---

## TASK-031 — Story State Inspector

**Skills：** `filament-ui`, `story-engine`

**依赖：TASK-030｜P1**

### UI
Workspace `Story State`：

```text
Characters
Relationships
Locations
Items
World
Timeline
Open Threads
Foreshadowings
Reader Promises
```

支持 State Version 历史切换。

### 验收
Canonical State 默认只读。

---

## TASK-032 — Facts 数据模型

**Skills：** `filament-ui`, `story-engine`

**依赖：TASK-030｜P0**

### 后端
- facts
- source_type manual/story_event/bible
- hardness
- confidence
- status
- locked

### UI
Story State → Facts：
- subject
- predicate
- value
- hardness
- source
- locked
- status

Filters：
- Locked
- Active
- Source

### 验收
Facts 可搜索和过滤。

---

## TASK-033 — Manual Fact / Lock Fact

**Skills：** `filament-ui`, `story-engine`

**依赖：TASK-032｜P0**

### UI
Actions：
```text
Add Manual Fact
Lock
Unlock
Supersede
```

Locked Fact 使用明显锁图标。

### 验收
可以在 UI 创建：
```text
角色甲 / can_swim / false / locked
```

Locked Fact 不允许普通 inline edit。

---

## TASK-034 — State Diff Viewer

**Skills：** `filament-ui`, `story-engine`

**依赖：TASK-031｜P1**

### UI
选择两个 State Version：
```text
v12 → v13
```

显示：
- changed paths
- before
- after

### 验收
后续 Canonical Commit 可以直接复用该组件显示 State Change。

---

## TASK-035 — Story State 基础服务

**Skills：** `filament-ui`, `story-engine`

**依赖：TASK-030｜P0**

### 后端
- StoryStateService::current
- findVersion
- previewPatch
- diff

### UI
无新增顶级页面，031/034 使用这些 Service。

### 测试
- current state
- version lookup
- stable checksum
- diff

---

# M4 — Chapter Planning

## TASK-040 — Chapter 数据模型与列表

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-013｜P0**

### 后端
- chapters
- ChapterStatus
- sequence unique

### UI
Workspace `Chapters`：
- sequence
- title
- status
- word_count
- review decision
- cost
- state version

### 验收
可创建 planned Chapter，sequence 不重复。

---

## TASK-041 — Chapter Plan 数据模型与手工编辑

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-040｜P0**

### 后端
- chapter_plans
- version unique
- PlanStatus

### UI
Chapter → Plan：
- chapter_function
- arc_contribution
- reader_promise
- target_words
- POV
- tone
- time_anchor
- hook_type
- must_reveal
- may_hint
- must_not_reveal
- required_facts
- forbidden_conflicts
- due_foreshadowings
- scene_plans Repeater

### 验收
不调用 AI 也能建立一个完整可执行 Plan。

---

## TASK-042 — Scene 数据模型

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-041｜P0**

### 后端
- scenes
- sequence unique
- SceneStatus

### UI
Chapter → Scenes：
- sequence
- goal
- conflict
- turn
- outcome
- POV
- location
- status

### 验收
Plan 中的 scene_plans 可同步/初始化 Scenes。

---

## TASK-043 — PlanValidator

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-041,033｜P0**

### 后端
校验：
- 实体引用
- POV
- Knowledge
- Locked Facts
- Critical Due Foreshadowing
- Completing restrictions

### UI
Plan 页面 Findings Panel：
- Valid
- Warning
- Blocked

### 验收
明显冲突的 Plan 无法进入生成。

---

## TASK-044 — Planning Preview

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-041,042,043｜P1**

### UI
一个页面同时看到：

```text
Chapter Function
Arc Contribution
Reader Promise
Scene 1 → Scene 2 → Scene 3
Due Foreshadowings
Required / Forbidden Facts
```

### 验收
生成正文前可人工快速检查“这一章为什么存在”。

---

## TASK-045 — Chapter Detail 工作台

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-040,041,042｜P1**

### UI Tabs
```text
Overview
Plan
Scenes
Draft
Events
Review
State Changes
Runs
```

### 验收
后续整个章节流水线围绕此页展开。

---

# M5 — AI Gateway / Cost / Prompt

## TASK-050 — AiProvider 基础接口

**Skills：** `filament-ui`

**依赖：TASK-001｜P0**

### 后端
- AiProvider interface
- AiRequest
- AiResponse
- 当前 Provider 实现
- Fake Provider
- timeout / error mapping

### UI
Settings → AI：
- provider
- model
- connection status
- Test Connection

### 验收
可以从 UI 发起一次测试请求并显示 model / latency / success。

---

## TASK-051 — AI Settings Resolution

**Skills：** `filament-ui`

**依赖：TASK-050,004｜P1**

### 后端
支持：
```text
Global Default
→ Novel Override
```

Stage：
- planner
- writer
- assembler
- extractor
- reviewer
- rewrite
- summary
- embedding

### UI
Settings + Novel Settings：
- 当前实际解析后的 Model

### 验收
Job 内不写死模型名。

---

## TASK-052 — Usage / Cost Tracking

**Skills：** `filament-ui`

**依赖：TASK-050｜P0**

### 后端
- usage_records
- provider/model/tokens/cached_tokens/latency/cost/request_id

### UI
Dashboard：
- Today's Cost
- Today's Tokens

Run SlideOver：
- 本次调用 Cost / Tokens

### 验收
任意 Provider Request 可追踪到 Run / Chapter / Novel。

---

## TASK-053 — Budget 基础

**Skills：** `filament-ui`

**依赖：TASK-052｜P0**

### 后端
支持：
- daily hard limit
- novel total limit
- chapter max cost

### UI
Settings → Budget。
Novel Overview 显示：
```text
Used / Limit
```

### 验收
Hard Limit 达到后不创建新 Provider Request。

---

## TASK-054 — Prompt Version 约定

**Skills：** `filament-ui`

**依赖：TASK-050｜P1**

### 后端
先使用项目文件 / config 维护：
```text
chapter-planner-v1
scene-writer-v1
assembler-v1
event-extractor-v1
reviewer-v1
rewrite-v1
summary-v1
```

Run 保存 prompt_version。

### UI
Settings 只读显示当前版本。

### 验收
历史 Run 可知道当时使用哪个 Prompt。

---

## TASK-055 — AI Debug Test 页面

**Skills：** `filament-ui`

**依赖：TASK-050,052｜P1**

### UI
Settings → AI Debug：
- Task Type
- Model
- Prompt Version
- 简单输入
- Run Test
- Response
- Tokens
- Cost
- Latency

### 验收
不用进入 Tinker 就能验证 Provider 行为。

---

# M6 — Generation Pipeline

## TASK-060 — GenerationRun / Artifact 数据模型

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-040,050｜P0**

### 后端
- generation_runs
- generation_artifacts
- RunStatus / Stage / ArtifactType
- idempotency_key unique
- Artifact immutable

### UI
`Generation`：
- Novel
- Chapter
- Stage
- Status
- Model
- Duration
- Cost
- created_at

SlideOver：
- context_snapshot
- artifact
- error
- usage

### 验收
任意生成阶段都可以从 UI 调试。

---

## TASK-061 — GenerateNextChapterAction / Preflight

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-060,030,053｜P0**

### 后端
- Preflight
- next sequence
- create/resume Chapter
- 同 Novel 活跃 Workflow 保护

### UI
Novel Overview：
```text
[Generate Next Chapter]
```

失败显示具体原因：
- Story State 未初始化
- paused
- budget exceeded
- blocked review
- active workflow exists

### 验收
重复点击不得产生两个同 sequence Chapter。

---

## TASK-062 — AI Chapter Planner

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-061,043,054｜P0**

### 后端
- PlanChapterJob
- structured output
- schema validation
- input_hash / idempotency
- plan artifact

### UI
Chapter Plan：
```text
[AI Generate Plan]
[Regenerate Plan]
```

显示 Run 状态。

### 验收
从 UI 可生成合法 Plan，并可人工修改。

---

## TASK-063 — ContextBuilder L0 / L1

**Skills：** `filament-ui`, `generation-pipeline`, `memory-context`, `story-engine`

**依赖：TASK-030,033,062｜P0**

### 后端
- ContextRequest
- ContextSnapshot
- L0 Hard Constraints
- L1 Current State
- TokenBudget

### UI
Run SlideOver：
- L0
- L1
- token allocation
- state version

### 验收
一次生成用了哪些硬约束和 State 可见。

---

## TASK-064 — ContextBuilder L2 Recent Story

**Skills：** `filament-ui`, `generation-pipeline`, `memory-context`, `story-engine`

**依赖：TASK-063,040｜P0**

### 后端
- recent chapter window
- summaries / story events
- previous chapter ending

### UI
Context Inspector 增加 Recent Story Section。

### 验收
近期上下文不依赖 pgvector。

---

## TASK-065 — Scene Generation

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-063,064｜P0**

### 后端
- GenerateSceneJob
- sequential execution
- scene_draft artifact
- temporary_state_delta
- provider retry

### UI
Chapter → Scenes：
```text
Generate
Retry
View Artifact
View Run
```

显示：
- status
- words
- duration
- cost

### 验收
Scene 2 不会在 Scene 1 未成功时执行。

---

## TASK-066 — Chapter Assembly

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-065｜P0**

### 后端
- AssembleChapterJob
- ordered scene checksums
- chapter_draft artifact

### UI
Chapter → Draft：
```text
[Assemble Chapter]
```

显示：
- 完整 Draft
- Scene 切换
- Artifact version

### 验收
多 Scene 可形成完整 Draft。

---

## TASK-067 — Pipeline Timeline UI

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-060,062,065,066｜P1**

### UI
Chapter Overview 显示：

```text
Plan       ✓
Context    ✓
Scene 1    ✓
Scene 2    ✓
Assembly   ✓
Events     ○
Review     ○
Commit     ○
Memory     ○
```

点击 Stage 打开 SlideOver。

### 验收
一眼知道章节现在卡在哪里。

---

## TASK-068 — Generation Failure UX

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-060｜P1**

### UI
失败 Run 显示：
- error_code
- stage
- retryable?
- recommended action

Actions：
```text
Retry
Resume
Open Chapter
```

### 验收
不再只看到“Job failed”。

---

# M7 — Event / Review / Rewrite

## TASK-070 — Story Event Candidate Extraction

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-066,035｜P0**

### 后端
- StoryEventCandidate DTO
- EventType
- ExtractStoryEventsJob
- event_candidate artifact

### UI
Chapter → Events：
- type
- subject
- payload
- confidence
- evidence

Badge：
```text
Candidate
```

### 验收
候选事件可见但不会写入正式 story_events。

---

## TASK-071 — StatePatchBuilder

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-070｜P0**

### 后端
- StatePatch
- deterministic Event Appliers
- state_patch artifact

### UI
Chapter → State Changes：
- path
- operation
- before
- after
- source event

### 验收
提交前可看到“这一章会改变什么”。

---

## TASK-072 — StateValidator

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-071,033｜P0**

### 后端
至少实现：
- Locked Fact
- Dead Character
- Knowledge
- Location
- Item Ownership
- Ability
- World Rule

### UI
Findings Panel：
- severity
- code
- message
- evidence
- related fact
- related state

### 验收
Golden Conflict 在 UI 明确 BLOCK。

---

## TASK-073 — Reviews 数据模型与 Narrative Review

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-072｜P0**

### 后端
- reviews
- ReviewDecision
- ReviewChapterJob
- 7 维评分

### UI
Chapter → Review：
- 总分
- 7 维分
- Decision
- Findings

### 验收
PASS / REWRITE / NEEDS_ATTENTION / BLOCK 清晰可见。

---

## TASK-074 — Review Inbox

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-073｜P1**

### UI
顶级 `Review` 页面默认只显示：
```text
NEEDS_ATTENTION
BLOCK
```

每条显示：
- Novel
- Chapter
- Decision
- top finding
- score
- age

### 验收
每天只需打开 Inbox 即可处理异常。

---

## TASK-075 — Rewrite Loop

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-073｜P0**

### 后端
- RewriteChapterJob
- max 2 attempts
- finding_hash
- 新 Artifact
- Rewrite 后重新 Extract / Validate / Review

### UI
Actions：
```text
Rewrite Scene
Rewrite Chapter
```

显示：
```text
Original
Rewrite #1
Rewrite #2
```

### 验收
超过次数自动 NEEDS_ATTENTION。

---

## TASK-076 — Draft / Rewrite Diff

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-075｜P1**

### UI
Review 页面支持文本 Diff：
- before
- after
- finding resolved?

### 验收
能快速判断 Rewrite 是否真的修复问题。

---

# M8 — Canonical Commit / 正式章节

## TASK-080 — story_events 正式表与回滚字段

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-070｜P0**

### 后端
- story_events
- status active/invalidated
- invalidated_at
- indexes
- evidence

### UI
Story State Inspector → Story Events：
- active / invalidated
- chapter
- scene
- event type
- evidence

### 验收
正式事件可查询且默认只读。

---

## TASK-081 — CanonicalCommitService

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-071,073,080｜P0**

### 后端
实现：
- SELECT novel FOR UPDATE
- expected state version
- review PASS
- artifact checksum
- Story Events
- Fact Changes
- StoryStateVersion N+1
- Chapter pointer
- Novel pointer
- transaction
- exactly-once

### UI
Review PASS 后：
```text
[Commit Canonical]
```

Commit 前 Modal 显示：
- Draft version
- State vN → vN+1
- Event count
- Fact changes
- State changes

### 验收
一次 Commit 后 Chapter canonical，State 版本增加，重复 Commit 不重复写入。

### 测试
- normal
- duplicate
- wrong state version
- transaction exception
- already canonical

---

## TASK-082 — Canonical Chapter Viewer

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-081｜P1**

### UI
Chapter Draft 页面增加：
```text
Canonical
```

显示：
- canonical artifact
- canonical_at
- word_count
- state version
- review result
- cost

### 验收
可以明确区分 Draft 与正式正文。

---

## TASK-083 — Canonical Commit 自动化

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-081｜P0**

### 后端
Review PASS 且 auto_commit=true 时：
```text
CommitChapterJob
```

人工模式仍保留按钮。

### UI
Novel Settings：
```text
Auto Commit after PASS
```

### 验收
自动模式和手动模式都走同一个 CanonicalCommitService。

---

## TASK-084 — Manual Canonical Correction

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-081｜P1**

### 后端
- Correction Event
- 新 State Version
- reason
- 不直接编辑旧 State

### UI
Story State Inspector：
```text
[Manual Correction]
```

要求填写 reason。

### 验收
正式状态修复可追踪，不直接修改历史 State JSON。

---

# M9 — Memory / Context / pgvector

## TASK-090 — memories 数据模型与 pgvector

**Skills：** `filament-ui`, `memory-context`

**依赖：TASK-081｜P0**

### 后端
- memories
- MemoryType / MemoryStatus
- vector column
- embedding_model
- validity window
- pgvector 基础 migration

### UI
顶级 `Memory` 页面：
- Novel
- type
- summary
- salience
- source
- status
- embedding status

### 验收
Memory 可查询，invalid 默认过滤。

---

## TASK-091 — UpdateMemoryJob

**Skills：** `filament-ui`, `generation-pipeline`, `memory-context`, `story-engine`

**依赖：TASK-090,081｜P0**

### 后端
- 只从 Canonical 创建
- source validation
- dedup
- salience
- idempotency

### UI
Canonical Chapter：
```text
Memory Created: N
```

点击进入本章 Memory。

### 验收
Draft 不创建正式 Memory。

---

## TASK-092 — Embedding Pipeline

**Skills：** `filament-ui`, `memory-context`

**依赖：TASK-090,050｜P0**

### 后端
- GenerateEmbeddingJob
- fixed model/dimensions
- retry
- embedding_model
- pending behavior

### UI
Memory：
```text
Embedding Ready / Pending / Failed
```

Actions：
```text
Retry Embedding
```

### 验收
Embedding 失败不影响 Canonical Chapter。

---

## TASK-093 — L3 Vector Retrieval

**Skills：** `filament-ui`, `memory-context`

**依赖：TASK-092,063｜P0**

### 后端
- MemoryQueryBuilder
- novel/status/model filters
- vector similarity
- candidate_k/final_k

### UI
Memory Inspector：
- Query
- Filters
- Top Results
- Similarity
- Salience

### 验收
禁止跨 Novel 和 invalid Memory 泄漏。

---

## TASK-094 — Ranking / Dedup / Token Assembly

**Skills：** `filament-ui`, `memory-context`

**依赖：TASK-093｜P0**

### 后端
- similarity
- salience
- recency
- entity match
- dedup
- token budget

### UI
Memory Inspector：
- final score
- selected/rejected
- reason

### 验收
Top-K 不被同一事件重复摘要占满。

---

## TASK-095 — Context Inspector

**Skills：** `filament-ui`, `memory-context`

**依赖：TASK-063,064,094｜P1**

### UI
Run → Context：
```text
L0
L1
L2
L3
L4
Token Allocation
Truncated Sections
Selected Memory
```

### 验收
能解释“为什么 AI 会知道这件事”。

---

## TASK-096 — Memory Rollback Invalidity

**Skills：** `filament-ui`, `memory-context`, `story-engine`

**依赖：TASK-090｜P0**

### 后端
回滚 Chapter 后：
```text
source chapter memories → invalid
```

### UI
Memory 页面可查看 invalid，但默认不参与 Retrieval。

### 验收
回滚剧情不会通过 RAG 重新污染后文。

---

## TASK-097 — Retrieval Evaluation

**Skills：** `filament-ui`, `memory-context`

**依赖：TASK-094｜P1**

### 后端
固定测试集：
- early fact
- early foreshadowing
- knowledge boundary
- item ownership
- invalid memory
- wrong novel

### UI
Memory → Evaluation：
可先做简单表格：
- case
- expected
- hit/miss
- leakage

### 验收
RAG 调参有固定基准，不凭感觉。

---

# M10 — 自动生成 / Pause / Resume / Recovery

## TASK-100 — Auto Generate 开关

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-083,091｜P0**

### 后端
Post-Commit：
```text
CheckNextAction
→ GenerateNextChapterAction
```

不预先 Queue 多章。

### UI
Novel Overview：
```text
[Start Auto Generate]
[Stop Auto Generate]
```

显示当前：
```text
Auto: ON/OFF
```

### 验收
每次只在上一章 Canonical 后生成下一章。

---

## TASK-101 — Graceful Pause

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-100｜P0**

### 后端
- novel → paused
- 不派发新 Stage
- 已发 Provider Request 可保存 Artifact
- Commit 前再次检查 Pause

### UI
Novel Overview：
```text
[Pause]
```

显示：
```text
Paused at: Scene 2 / Review / ...
```

### 验收
Pause 后无新 Canonical Commit。

---

## TASK-102 — Resume Resolver

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-101,060｜P0**

### 后端
根据 DB 状态恢复：

```text
canonical → post-commit
PASS → commit
REWRITE → rewrite
draft → review
scenes complete → assemble
partial scenes → next scene
plan → scene1
none → plan
```

### UI
```text
[Resume]
```

Modal 显示：
```text
Detected Resume Point
```

### 验收
不依赖 Redis Queue 中有没有 Job。

---

## TASK-103 — Worker Crash / Stalled Run Recovery

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-102｜P0**

### 后端
- 检查长时间 running Run
- 标记 failed / worker_lost
- Artifact 已存在则复用

### UI
Generation：
```text
Stalled / Worker Lost
[Recover]
```

### 验收
模拟 Worker Crash 后可恢复。

---

## TASK-104 — Emergency Stop

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-100｜P1**

### 后端
阻止：
- 新 Provider Request
- Canonical Commit

### UI
Dashboard / Settings：
```text
[Emergency Stop]
```

二次确认。

### 验收
用于预算、安全或系统性错误时快速停机。

---

## TASK-105 — Auto Stop Rules

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-100｜P0**

### 后端
必须停止：
- Hard Conflict
- NEEDS_ATTENTION
- BLOCK
- Provider Retry Exhausted
- Rewrite Exhausted
- Budget Limit
- State Version Conflict
- Volume Gate
- Ending Audit Block
- Pause

### UI
Novel Overview：
```text
Auto Generation Stopped
Reason: ...
Recommended Action: ...
```

### 验收
异常不会继续生成几十章。

---

## TASK-106 — Recovery Dashboard

**Skills：** `filament-ui`, `generation-pipeline`

**依赖：TASK-102,103,105｜P1**

### UI
Generation 顶部：
```text
Failed
Blocked
Recoverable
Needs Attention
```

快捷 Actions：
```text
Resume
Retry
Open Review
Open Context
```

### 验收
单人运营时不用查日志即可处理大部分异常。

---

# M11 — Volume Gate / Ending Controller

## TASK-110 — Volume Completion Gate

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-014,022,081｜P0**

### 后端
检查：
- Volume Goal
- Climax
- Required Arcs
- Character Stage Changes
- Due Foreshadowings
- blocking findings

### UI
Volume Detail：
```text
Completion Checklist
```

每项：
```text
PASS / WARNING / BLOCK
```

### 验收
不满足关键条件时不能轻易结束当前卷。

---

## TASK-111 — Ending Contract UI

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-012｜P0**

### UI
Bible → Ending Contract 结构化展示：
- protagonist final state
- main conflict resolution
- theme payoff
- required foreshadowing payoff
- character arc requirements
- allowed open endings

### 验收
Ending 不再只是一段自由文本。

---

## TASK-112 — Closure Debt 计算

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-014,022,031,111｜P0**

### 后端
来源：
```text
Open Arcs
Open Reader Promises
Due Foreshadowings
Important Relationships
World Crisis
Ending Contract Gap
```

### UI
Novel Overview：
```text
Closure Debt: 14
Critical: 2
```

点击查看明细。

### 验收
可以知道“为什么小说现在还不能完结”。

---

## TASK-113 — Completing Mode

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-112,043｜P0**

### 后端
进入：
```text
novel.status = completing
```

Plan Gate 限制新：
- 核心主线
- 核心人物
- 硬规则
- 高重要度伏笔

### UI
Novel 顶部明显显示：
```text
COMPLETING
```

Planning 页面显示：
```text
Closing Restrictions Active
```

### 验收
进入收束期后 Planner 不继续无止境开坑。

---

## TASK-114 — Ending Audit

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-112,113｜P0**

### 后端
EndingAuditJob：
- Ending Contract
- Critical Closure Debt
- Foreshadowing
- Arc
- Character Arc
- State gaps

### UI
Ending Audit 页面：
```text
PASS / BLOCK
```

逐项显示证据。

### 验收
Critical Debt > 0 时不能 completed。

---

## TASK-115 — Complete Novel

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-114｜P0**

### 后端
Audit PASS 后：
```text
novel.status = completed
```

completed 默认停止生成。

### UI
```text
[Complete Novel]
```

完成后：
- Completed Badge
- 生成按钮禁用
- Ending Audit 可查看

### 验收
小说真正进入完结状态。

---

# M12 — 回滚 / 稳定性 / 100章验收

## TASK-120 — Latest Canonical Chapter Rollback

**Skills：** `filament-ui`, `memory-context`, `story-engine`

**依赖：TASK-081,096｜P0**

### 后端
仅允许当前最新 Canonical Chapter：
- invalidated story events
- restore novel pointer
- state pointer
- invalidate memories
- rebuild derived facts/projections
- state version 不复用

### UI
Canonical Chapter：
```text
[Rollback Latest Chapter]
```

Modal 展示：
- affected state
- affected events
- affected memories
- reason required

### 验收
非最新 Chapter 无法直接 rollback。

---

## TASK-121 — Story State Rebuild

**Skills：** `filament-ui`, `generation-pipeline`, `story-engine`

**依赖：TASK-080,035｜P0**

### 后端
`StoryStateRebuilder`
Artisan：
```text
story:rebuild-state {novel} --dry-run
```

### UI
Story State Inspector：
```text
[Verify / Rebuild]
```

显示：
- current checksum
- rebuilt checksum
- diff

### 验收
默认 dry-run，不静默覆盖正式 State。

---

## TASK-122 — Projection Rebuild

**Skills：** `filament-ui`, `memory-context`, `story-engine`

**依赖：TASK-121｜P1**

### 后端
重建：
- characters.current_state
- world_entities.current_state
- foreshadowings.status

### UI
Story State：
```text
Projection Health
[Rebuild Projections]
```

### 验收
Projection 出错不影响 Canonical Source。

---

## TASK-123 — 20章 Smoke Run

**Skills：** `filament-ui`, `generation-pipeline`, `memory-context`, `story-engine`

**依赖：M1~M10 主链路｜P0**

### 目标
第一次真实连续生成验证。

### UI
Dashboard 增加：
```text
Long Run Progress
```

### 验收
20 章：
- 无重复 Commit
- 无跳章
- 可追踪 Cost
- State 连续
- Pause/Resume 可用

---

## TASK-124 — 50章 Reliability Run

**Skills：** `filament-ui`, `generation-pipeline`, `memory-context`, `story-engine`

**依赖：TASK-123｜P0**

### 验收重点
- Retry
- Worker Crash
- Memory
- Foreshadowing
- Review Rewrite
- Cost drift
- Context size

### UI
增加简单 Reliability Summary。

---

## TASK-125 — 100章 MVP Soak Test

**Skills：** `filament-ui`, `generation-pipeline`, `memory-context`, `story-engine`

**依赖：TASK-124｜P0**

### 验收
至少：
```text
≥100 Canonical Chapters
0 Duplicate Canonical Commit
0 Unrecoverable State Corruption
Locked Fact Golden Cases 100% Block
Pause 后无新 Commit
Resume 不跳章
Critical Foreshadowing 可阻断
所有费用可追踪
```

### UI
Dashboard：
```text
MVP Readiness
```

---

## TASK-126 — MVP Release Checklist

**Skills：** `filament-ui`, `generation-pipeline`, `memory-context`, `story-engine`

**依赖：TASK-125｜P0**

### 内容
- DB backup
- restore test
- queue restart
- state rebuild
- memory rebuild
- cost limit
- emergency stop
- Horizon
- scheduler
- logs
- config
- docs

### UI
Settings / System：
```text
System Health
```

### 验收
达到可长期单人运行状态。

---

# 4. 推荐真正的实施顺序

不要简单按 Task 编号全部从小到大执行。

推荐主路径：

```text
001
→ 002
→ 010
→ 011
→ 012
→ 013
→ 014
→ 020
→ 021
→ 022
→ 030
→ 031
→ 032
→ 033
→ 040
→ 041
→ 042
→ 043
→ 050
→ 052
→ 053
→ 054
→ 060
→ 061
→ 062
→ 063
→ 064
→ 065
→ 066
→ 070
→ 071
→ 072
→ 073
→ 075
→ 080
→ 081
→ 082
→ 090
→ 091
→ 092
→ 093
→ 094
→ 095
→ 100
→ 101
→ 102
→ 103
→ 105
→ 110
→ 111
→ 112
→ 113
→ 114
→ 115
→ 120
→ 121
→ 123
→ 124
→ 125
→ 126
```

P1 UI / Debug Task 可以穿插，例如：

```text
003
004
015
023
024
034
044
045
051
055
067
068
074
076
084
097
104
106
122
```

---

# 5. 第一阶段最推荐的 10 个 Task

如果现在正式开始开发，不要先冲 AI。

第一批建议：

```text
TASK-001 当前项目环境审计
TASK-002 Filament 导航基线
TASK-010 Novel
TASK-011 Novel Workspace
TASK-012 Bible
TASK-013 Volume
TASK-014 Story Arc
TASK-020 Character
TASK-021 World Entity
TASK-022 Foreshadowing
```

完成后，你已经有一个“看起来像真正产品”的 Filament 工作台，同时数据库基础也已经稳定。

第二批：

```text
TASK-030 Story State 0
TASK-031 State Inspector
TASK-032 Facts
TASK-033 Locked Facts
TASK-040 Chapter
TASK-041 Chapter Plan
TASK-042 Scene
TASK-043 PlanValidator
```

完成后，才开始：

```text
TASK-050 AI Provider
```

---

# 6. 任务拆分原则

以后新增 Task 时遵守：

```text
一个 Task 尽量只解决一个业务能力。
```

好的：

```text
TASK-072 StateValidator
```

不好：

```text
实现 Story Engine + Review + Memory + Filament
```

每个 Task 最好能回答：

```text
完成后我在 UI 里能看到什么？
```

如果完全看不到，也应该明确它是哪一个 UI 功能的基础依赖。

---

# 7. Codex Task 模板

每次复制：

```text
实施：
TASK-XXX — <任务名称>

使用 Skill：
- <主要 skill>
- <需要时添加 filament-ui>

开始前阅读：

- AGENTS.md
- docs/PRD.md
- docs/development/IMPLEMENTATION_PLAN.md
- <TASK 对应的 architecture 文档>
- DESIGN.md（仅当本任务涉及 Filament / UI）

从 IMPLEMENTATION_PLAN.md 中读取 TASK-XXX 的完整定义，
包括：
- 目标
- 依赖
- 后端实现
- Filament UI
- 验收标准
- 测试
- 完成定义

当前只实施 TASK-XXX。

要求：

1. 检查当前项目已有实现。
2. 检查 TASK-XXX 的依赖任务是否已经完成。
3. 如果依赖未满足，停止实施并报告缺失依赖。
4. 先输出实施计划，不立即修改代码。
5. 明确预计修改：
   - Migrations
   - Models
   - Enums
   - DTOs
   - Actions / Services
   - Jobs
   - Filament
   - Tests
   - Config
   中哪些内容。
6. 复用已有实现，不创建重复能力。
7. 严格限制在 TASK-XXX 范围内。
8. 不实施后续 TASK。
9. 不为了未来需求增加额外抽象。
10. 如果当前代码与 AGENTS.md / PRD / architecture 文档冲突，
    先报告冲突，不静默修改。
11. 涉及 Filament 时：
    - 遵循 DESIGN.md
    - 优先使用 Filament 原生组件和 styling API
    - Light / Dark 均保持可读
    - 不重写 Filament 核心组件
    - 尽量让本 Task 有明确 UI 可见结果
12. 涉及数据库时：
    - 检查 FK / UNIQUE / CHECK / INDEX
    - 检查 migration rollback
13. 涉及 Queue / Workflow 时：
    - 明确 idempotency_key
    - 明确 input_hash
    - 明确 Retry
    - 明确 duplicate execution
    - 明确 failure recovery
14. 涉及 Canonical Story 数据时：
    - Draft 不得修改 Canonical State
    - 必须遵循 Story State Version
    - 必须考虑重复执行和事务一致性
15. 完成后实际运行相关测试。
16. 如果涉及前端资源，运行：
    npm run build
17. 不得用“应该通过”代替实际测试结果。

完成后汇报：

## Summary
实施了什么。

## Files Changed
修改/新增哪些文件及用途。

## UI Changes
涉及哪些 Filament 页面、Widget、Action、SlideOver。
如果本任务无 UI，明确写：
No UI changes.

## Design Tokens Applied
仅 UI Task 输出：
- colors
- typography
- spacing
- surface
- borders
- radius
- status semantics

## Tests / Build
实际运行：
- 哪些 PHPUnit / Pest 测试
- php artisan 相关命令
- npm run build（如适用）
- 结果

## Acceptance Criteria
逐条核对 TASK-XXX 的验收条件：
- [x] ...
- [x] ...

## Follow-ups
只列出真正属于后续 TASK 的内容。
不要提前实现。
```

---

# 8. MVP 完成定义

MVP 不是“所有页面都有”。

真正完成标准：

```text
可以创建小说
可以维护 Bible / Character / World / Foreshadowing
可以初始化 Story State
可以规划 Chapter / Scene
可以自动生成 Scene / Chapter
可以 Review / Rewrite
可以 Canonical Commit
可以更新 Story State
可以长期 Memory / pgvector Retrieval
可以追踪 Context
可以自动生成下一章
可以 Pause / Resume
可以恢复 Worker / Provider Failure
可以回滚最新正式章
可以进入 Completing
可以被 Closure Debt 阻止错误完结
可以通过 Ending Audit 完结
可以连续生成 100 章
```

同时：

```text
单人能够看懂
单人能够 Debug
单人能够恢复
单人能够维护
```

# END OF IMPLEMENTATION_PLAN.md
