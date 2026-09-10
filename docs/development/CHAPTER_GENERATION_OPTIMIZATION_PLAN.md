# 章节生成流程与内容质量优化方案

> 日期：2026-09-10  
> 范围：现状分析与优化设计，不修改应用代码、配置、数据库或现有业务数据。  
> 依据：`AGENTS.md`、`docs/PRD.md`、`docs/architecture/generation-pipeline.md`、`docs/architecture/memory-context.md`、`docs/architecture/story-engine.md`、当前代码、当前 PostgreSQL 数据与相关自动测试。
> 可执行任务：`docs/development/CHAPTER_GENERATION_OPTIMIZATION_TASKS.md`。

## 1. 结论

三个问题并非彼此独立：当前系统已经实现了各个生成阶段，却没有把它们组合成正常生产所需的端到端流水线；审校又会把普通、可自动修复的 warning 直接升级为人工处理；文风虽然进入了 Writer 和 Assembler 上下文，却没有进入 Reviewer 和 Rewriter，而且当前小说的 Bible 与创作风格设置存在 POV、基调冲突。

因此，主要问题不是“缺少生成能力”，而是以下三项系统性缺口：

1. **工作流编排缺口**：阶段存在，但正常路径的阶段推进依赖多个 UI 操作。
2. **审校决策缺口**：模型建议被当作最终分流依据，可修复问题没有进入自动 Rewrite。
3. **Style Contract 缺口**：文风没有形成单一、无冲突、贯穿生成—组装—审校—重写的权威约束；本方案确定由当前 Novel Bible Version 统一承载。

当前实现与项目文档存在明确冲突：

- PRD `G3 自动化程度` 要求正常章节自动规划、自动生成、自动 Review、自动重写、自动 Commit，只有硬冲突、连续 Rewrite 失败、重大 Ending 变化、成本硬限制、模型输出不可解析或用户暂停时才人工处理（`docs/PRD.md:188`）。
- Generation Pipeline 的 Definition of Done 要求“一章可从 Plan 自动运行到 Canonical Commit”（`docs/architecture/generation-pipeline.md:729`）。
- 当前代码没有实现这条正常端到端推进链。

## 2. 分析边界与证据状态

### 2.1 已确认事实

- 当前数据库只有一部小说《六环余光》，已有 3 章 Canonical，第 4 章停在 Review。
- 当前小说 `auto_generate=false`，`auto_commit` 未设置；最近一次 Auto Stop 原因为 `needs_attention`。
- 当前有效全局模型为 `gpt-5.6-luna`，各 Stage Override 为空；已检查的 Provider 驱动规划、写作、组装、提取、审校和重写 Run 使用该全局模型。人工 Override、Memory 与 Embedding 等非同类 Run 不计入该结论。
- 当前 Review 阈值为 80，自动 Rewrite 上限为 2。
- 4 章的首次 Review 都没有直接 PASS。
- Writer 和 Assembler 的 Run 快照包含当前 Style Profile。
- Reviewer 和 Rewriter 的输入及 Run 快照不包含当前 Style Profile。
- 当前相关测试通过：79 tests，343 assertions。

### 2.2 基于现有信息的推断

- 第 4 章各 Run 之间存在 10～38 秒的间隔，并与代码中的分阶段按钮边界一致。结合代码可判断正常操作需要逐段发起；仅凭时间戳本身不能证明每次间隔一定对应人工点击。
- 当前正文确实包含第一人称、自嘲、短对白和情境幽默，因此“完全没有采用轻松幽默、通俗爽快”不符合现有文本证据。更准确的描述是：文风强度、稳定性或用户期望的具体表现没有得到可靠保证。
- 当前正文中的幽默多为固定的自嘲—吐槽模式。它是否属于用户认为的“文风不匹配”，需要用户提供正向样例或更具体的风格标准才能最终确认。

### 2.3 当前无法确认

- 无法从代码和数据库确认用户心中期望的“轻松幽默 + 通俗爽快”具体应接近哪位作者、哪类作品或哪些文本样例。
- 无法确认创作风格设置是在 Bible 创建前还是创建后修改。只能确认当前两者冲突。
- 现有 4 章样本量不足以估计长期的首稿 PASS 率、自动 Rewrite 成功率和文风稳定率。
- 第 1、2 章的首次 `INVALID_STATE_PATCH` 是历史数据；当前 `reviewer-v4` 已增加前置产物检查，不能据此断言新章节仍会重复同一问题。

## 3. 问题一：正常章节需要逐步点击

### 3.1 已确认的直接原因

当前正常路径实际是：

```text
生成下一章（只创建/恢复 Chapter，并跳转）
→ 点击 AI 生成章节计划
→ 点击 Scene 1 生成
→ 点击 Scene 2 生成
→ ...
→ 点击组装章节
→ 点击提取事件
→ 自动 State Patch + Review
→ 根据 Review 再人工决定 Rewrite / Commit
```

具体代码证据：

1. `GenerateNextChapterAction` 只创建或恢复 Chapter，不派发 `PlanChapterJob`（`app/Actions/Generation/GenerateNextChapterAction.php:18`）。
2. Novel 页的“生成下一章”只调用上述 Action，然后跳转章节工作台（`app/Filament/Resources/Novels/Pages/ViewNovel.php:188`）。
3. `PlanChapterJob` 成功后只结束自身，不派发首个 Scene（`app/Jobs/PlanChapterJob.php:37`）。
4. 普通 Scene 按钮创建 `new GenerateSceneJob($sceneId)`，`cascade` 使用默认值 `false`（`app/Filament/Resources/Novels/Pages/ViewNovelChapter.php:1144`）。
5. Scene 串行级联能力已经存在，但只在 `cascade=true` 时生效；当前普通生成没有启用它（`app/Jobs/GenerateSceneJob.php:49`）。
6. 即使启用 Scene 级联，最后一个 Scene 成功后也不会派发 Assembly；`dispatchNextScene()` 只查找下一个 Scene（`app/Jobs/GenerateSceneJob.php:70`）。
7. 普通“组装章节”派发的 `AssembleChapterJob` 没有开启后续链；只有 Rewrite 路径的 `continueRewrite=true` 才会继续事件提取（`app/Jobs/AssembleChapterJob.php:42`）。
8. UI 手工提取事件时传入 `continueRewrite=true`，此后才会自动 Build State Patch 和 Review（`app/Filament/Resources/Novels/Pages/ViewNovelChapter.php:1518`；`app/Jobs/ExtractStoryEventsJob.php:44`）。
9. Review PASS 只在小说 `auto_commit=true` 时派发 Commit；当前小说没有开启该设置（`app/Jobs/ReviewChapterJob.php:45`）。
10. Review 为 REWRITE 时，`ReviewChapterJob` 不派发 `RewriteChapterJob`（`app/Jobs/ReviewChapterJob.php:40`）。
11. “开始自动生成”目前只修改 `auto_generate` 设置，不启动当前章；下一章自动生成只会在一次 Canonical Commit 之后由 `CheckNextAction` 触发（`app/Actions/Generation/SetAutoGenerationAction.php:10`；`app/Actions/Generation/CheckNextAction.php:24`）。

### 3.2 为什么局部实现看起来正确，但整体仍然繁琐

当前代码按早期 Implementation Plan 的 Batch 分别完成了 Plan、Scene、Assembly、Review 等单项 UI 和测试。各阶段可以独立调试，局部恢复链也已经存在；但没有一个测试从“一次生成下一章”开始断言最终到达 PASS/Commit。

现有测试主要覆盖：

- Scene 顺序与局部 cascade；
- Rewrite 后重新 Assembly/Extract/Review；
- Event Extract 后 Build Patch/Review；
- PASS + `auto_commit=true` 后 Commit；
- Commit 后生成下一章。

缺失的是把这些局部链连接起来的正常生产路径测试。这解释了为什么 79 个相关测试全部通过，而用户仍必须逐步点击。

### 3.3 推荐方案

新增一个**简单、确定性的章节推进动作**，建议命名为 `AdvanceChapterPipelineAction`（建议新增，当前不存在）。它不是新的 Workflow Engine，也不增加队列类型；职责只有一个：读取 PostgreSQL 中的 Chapter、Run、Artifact、Review、State Version，判断并只派发下一个合法 Stage。

推荐状态推进：

```text
无 Chapter
→ GenerateNextChapterAction
→ PlanChapterJob
→ 第一个未完成 Scene
→ 后续未完成 Scene（严格串行）
→ AssembleChapterJob
→ ExtractStoryEventsJob
→ StatePatchBuilder
→ ReviewChapterJob
→ PASS: Stop，等待用户手动 Canonical Commit
→ REWRITE: RewriteChapterJob（次数允许且问题可自动修复）
→ NEEDS_ATTENTION / BLOCK: Stop
```

每个 Job 成功后调用同一个推进动作。推进动作必须继续使用现有：

- `GenerationStageGate`：Pause 后不派发新 Stage；
- `GenerationJobDispatcher`：临时 pending 标记与去重；
- `ShouldBeUnique`、`idempotency_key`、`input_hash`：重复投递安全；
- `GenerationRun` / `GenerationArtifact`：恢复与追踪；
- PostgreSQL 状态：唯一业务进度来源。

不建议仅靠 Laravel `Bus::chain` 固定整条链，因为 Scene 数量动态、Rewrite 会回环、Pause/Resume 需要从持久化状态恢复。也不建议引入第三方工作流引擎。

### 3.4 UI 调整

Novel 页保留一个主操作：

```text
生成下一章
```

行为应为“创建/恢复下一章并启动流水线”，而不是只创建 Chapter。

章节工作台中的 Plan、Scene、Assembly、Extract、Review 按钮继续保留，但定位改为：

- 调试；
- 手工重跑；
- 故障恢复；
- 指定范围重生成。

它们不再是正常章节生成必须依次执行的主流程。

“开始自动生成”应在打开开关后立即调用同一个推进动作：

- 若当前无活动章，创建并启动下一章；
- 若有可恢复活动章，从持久化断点继续；
- 后续仍坚持每次只在上一章 Canonical 后创建下一章。

### 3.5 已确认的自动化边界：运行到 PASS

已经确认正常章节自动生成运行到 Review `PASS` 后停止，不自动执行 Canonical Commit：

```text
一次启动
→ 自动 Plan / Scene / Assembly / Event / Patch / Review / Rewrite
→ PASS
→ 等待用户点击“提交正式章节”
→ Canonical Commit
```

因此，新流水线不能因现有 `auto_commit=true` 而自动派发 `CommitChapterJob`。`auto_generate` 也只能推进当前章到 PASS；只有用户提交当前章并完成 Canonical Commit 后，系统才可以创建下一章。这一选择保留了每章一次最终确认，同时意味着系统不会无人值守连续提交多章。

该决策与当前 PRD 中“正常章节自动 Commit”的目标不一致。实施代码前必须先同步 `docs/PRD.md` 和 Generation Pipeline 架构文档，明确新的自动化终点是 PASS；本次只在本优化方案中记录决策，不修改其他文档。

## 4. 问题二：首次审校总是进入人工处理

### 4.1 当前数据事实

| Chapter | 首次 Review | 总分 | 首次主要原因 | 当前代码下应有的处理性质 |
|---|---:|---:|---|---|
| 1 | BLOCK | 86.95 | 缺少 State Patch，另有 3 条 warning | 历史流程产物缺失；当前版本应在 Review 前终止并恢复产物链 |
| 2 | BLOCK | 88.90 | 缺少 State Patch、字数超限、连续性/计划/重复/节奏问题 | 产物缺失需恢复；正文问题多数可自动 Rewrite |
| 3 | NEEDS_ATTENTION | 96.65 | 1 条 plan warning | 可定位、可自动修复，不应立即人工处理 |
| 4 | NEEDS_ATTENTION | 92.30 | 1 条 plan warning | 可定位、可自动修复，不应立即人工处理 |

当前共有 21 条 Review 记录，4 章中只有 3 章最终 Canonical。该数据说明人工处理频繁，但不能把四章的根因视为完全相同。

### 4.2 审校决策逻辑的问题

当前最终 Decision 规则为：

```text
StateValidator blocked → BLOCK
否则模型 recommended_decision 为 NEEDS_ATTENTION/BLOCK → NEEDS_ATTENTION
否则字数错误 / 模型建议 REWRITE / 总分低于 80 → REWRITE
否则 → PASS
```

这段逻辑带来三个问题：

1. **模型顶层建议权重过高**：模型只要返回 `NEEDS_ATTENTION`，Laravel 就直接要求人工处理，即使总分 96.65 且只有一条 warning。
2. **Finding 结构不足以可靠分流**：Reviewer Schema 只有 dimension、severity、message、evidence，没有稳定 code、scene_id、修复范围、是否可自动修复、是否需要人类决策。
3. **REWRITE 没有自动启动**：即使最终 Decision 正确得到 REWRITE，也仍需点击 Rewrite。

这与 PRD/架构定义冲突。项目文档规定：

- 明确、可定位的问题进入 REWRITE；
- NEEDS_ATTENTION 只用于 Rewrite 耗尽、重大歧义、Ending 冲突或真正需要人工决策；
- 正常路径自动 Rewrite。

### 4.3 内容源头的问题

第 3、4 章被指出的问题都是 Scene Outcome 与正文不一致：

- 第 3 章计划要求林墨“不擅自追赶”，正文却写他立即追出。
- 第 4 章计划要求“获得继续调查许可”，正文却写“不能自行追查”。

Scene Writer 已收到对应 goal/conflict/turn/outcome，但仍违反结果。Assembler 收到了完整 Chapter Plan，却主要被要求做衔接、统一语气和去重，没有输出可验证的计划覆盖结果。`SceneDraftPayload.self_check` 当前只是任意 JSON 对象，既没有固定字段，也没有被后续流程使用。

因此，审校频繁触发还反映了写作与组装阶段缺少可执行的 Plan Adherence 约束，而不仅是 Reviewer 太严格。

### 4.4 推荐的确定性 Decision Matrix

模型的 `recommended_decision` 应作为参考信息，Laravel 依据结构化 Findings 和确定性规则作最终分流。

推荐规则：

1. **BLOCK**
   - `StateValidator` 的 hard finding；
   - Locked Fact 冲突；
   - 不可接受的 Canonical 前置条件冲突。

2. **NEEDS_ATTENTION**
   - 自动 Rewrite 已达到上限且问题仍存在；
   - Finding 明确标记需要用户选择，且不能从 Canonical 数据确定答案；
   - Ending Contract 的重大方向变化；
   - Plan 本身互相矛盾或无法满足；
   - 预算硬限制等非正文自动修复问题。

3. **REWRITE**
   - 有明确文本证据、定位范围和修复指令的 continuity/plan/character/progress/repetition/pacing/style error；
   - 字数确定性校验失败；
   - 总分低于阈值且存在可执行 Finding；
   - 重要 warning 经策略判定必须修复，但不需要人工决策。

4. **PASS**
   - 无 hard finding；
   - 总分达到阈值；
   - 无必须修复的 error；
   - 普通 warning 可随 Review 保留，但不阻塞提交。

不要使用“只要有 warning 就 PASS”，也不要使用“模型只要建议 NEEDS_ATTENTION 就人工处理”这两个极端规则。

### 4.5 Reviewer 输出结构优化

建议为每条 Narrative Finding 增加：

```text
code
dimension
severity: warning | error
scope: paragraph | scene | chapter
scene_id?
message
evidence
repair_instruction
requires_human_decision: bool
```

限制：

- 模型不得创建 `hard`；hard 只来自确定性校验。
- `requires_human_decision=true` 必须说明缺少的事实或必须由用户选择的分支，不能用它表达普通写作质量问题。
- `scene_id` 必须引用本章 Scene。
- 能定位到 Scene 的问题优先 Scene Rewrite；跨 Scene、章节长度或整体节奏问题才 Whole Chapter Rewrite。

### 4.6 自动 Rewrite 闭环

Review 完成后：

```text
REWRITE
→ 自动选择最小修复范围
→ RewriteChapterJob
→ Extract Events
→ Build State Patch
→ State Validation
→ Review
```

现有 `RewriteChapterJob` 后半段已经实现重新提取、补丁和复审，应复用，不要复制流程。

还应修正 Rewrite 次数口径：`ChapterRewriter::attemptCount()` 当前统计本章所有 `rewrite_draft`，包括人工修订产物；UI 的 `automaticRewriteArtifacts()` 会排除 `manual_edit`。后端和 UI 必须采用同一“自动 Rewrite 次数”定义，避免人工编辑消耗自动重写预算。

## 5. 问题三：正文与主文风、辅助文风不匹配

### 5.1 已确认当前文风设置

当前小说的创作风格为：

```text
故事基调：热血
主文风：轻松幽默
辅助文风：通俗爽快
语言时代感：现代口语
节奏：适中
叙事视角：第一人称
```

Writer/Assembler 快照中的展开结果还包含：

```text
语言华丽度 2/5
对白占比 4/5
环境描写 2/5
心理描写 2/5
幽默程度 4/5
文学性 2/5
```

当前正文确实使用第一人称，并多次出现自嘲、人物反差与短对白。因此可以确认 Style Profile 被传入并产生了部分效果；不能确认它达到了用户期望的强度或具体审美。

### 5.2 权威信息冲突

当前 Bible v1 为：

```text
tone：严肃且充满希望
pov：第三人称有限视角
tense：过去时
```

当前创作风格为：

```text
story_tone：热血
narrative_pov：第一人称
primary_style：轻松幽默
secondary_style：通俗爽快
```

Planner 同时收到两组数据。实际 Chapter Plan 把它们混合成了“热血、轻松幽默、通俗爽快”；正文采用第一人称。这说明当前系统在冲突时事实上偏向创作风格设置，但代码和文档没有声明该优先级。

这是内容不稳定的直接风险：不同模型或不同阶段可能各自选择不同约束。

### 5.3 Style Profile 传递断层

| Stage | 当前是否收到 Style Profile | 问题 |
|---|---|---|
| Chapter Planner | 是 | 同时收到冲突的 Bible tone/pov/tense，没有明确优先级 |
| Scene Writer | 是 | Style 位于额外的 `writing_constraints`，ContextBuilder 本身没有 L4；缺少风格样例和禁用表达 |
| Assembler | 是 | 同时收到 Bible style constraints 与 Editorial Style Profile，冲突仍未消解 |
| Reviewer | 否 | `style_score` 没有目标 Style Contract，只能评一般可读性 |
| Rewriter | 否 | 重写可能修好剧情问题，同时把正文改离目标文风 |
| Rewrite 字数修复 | 否 | 二次压缩/扩写同样可能继续漂移 |

数据库中的 Review 对现有章节给出了 88～97 的高 style score，但 Reviewer 的上下文没有“轻松幽默 + 通俗爽快”目标。这个分数不能证明正文符合用户设置，它最多说明模型认为正文的一般文风/可读性较好。

### 5.4 ContextBuilder 与架构文档的差距

架构定义五层上下文：L0 Hard Constraints、L1 Current State、L2 Recent Story、L3 Long-term Memory、L4 Style。

当前 `ContextSnapshot` 只定义并序列化 L0～L3。Style 由 SceneGenerator/Assembler 临时追加到 `writing_constraints`，没有统一的 L4：

- 没有 `style_contract_version` 或 checksum；
- 没有风格正向样例；
- 没有禁用表达或反例；
- Reviewer/Rewriter 无法复用同一份冻结风格；
- Run Inspector 无法横向判断各阶段是否使用相同风格版本。

### 5.5 文风描述本身不够可执行

当前 Primary/Secondary Style 的自然语言指令是有效的起点，但仍有三个缺口：

1. 没有定义主文风与辅助文风的优先级。辅助文风可能被模型理解为并列目标。
2. 六项 1～5 参数只有数字，没有转换成明确的写作行为和禁止行为。
3. 没有用户认可的文本样例。对于“轻松幽默”，模型可能稳定地产生通用吐槽，但不一定是用户希望的幽默类型。

### 5.6 已确认的合并方向：Novel Bible 是唯一权威来源

本方案采用已经明确的产品决策：把“创作风格”和“文风高级设置”迁入小说圣经，与现有“叙事基线”统一管理。合并完成后，当前 Bible Version 是该小说叙事与文风约束的唯一权威来源。

这项决定消除以下重复来源：

| 当前字段 | 当前来源 | 合并后的权威字段 |
|---|---|---|
| `story_tone` | `novels.settings.editorial` | `novel_bibles.tone` |
| `narrative_pov` | `novels.settings.editorial` | `novel_bibles.pov` |
| `tense` | `novel_bibles` | `novel_bibles.tense`，保持不变 |
| `subgenre`、`target_platform` | `novels.settings.editorial` | 当前 Bible Version 的作品定位 |
| `primary_style`、`secondary_styles` | `novels.settings.editorial` | 当前 Bible Version 的文风基线 |
| `language_era`、`pacing` | `novels.settings.editorial` | 当前 Bible Version 的文风基线 |
| 六项 `style_parameters` | `novels.settings.editorial` | 当前 Bible Version 的文风高级设置 |

`story_tone` 和 `narrative_pov` 不应作为别名继续长期保存，否则仍然存在两个可修改入口。合并后统一使用 Bible 的 `tone`、`pov`、`tense`。`novels.settings` 继续保存小说级运行策略或技术设置，不再保存叙事与文风事实。

需要明确一点：`subgenre` 和 `target_platform` 并非纯粹的句法风格字段，但它们目前属于“创作风格”，并会影响规划和正文表达。按照本次合并范围，建议将其放进 Bible 的“作品定位”，而不是继续留在 Novel 基础设置中。

### 5.7 推荐的数据结构

已确认在 Novel Bible 增加可空 JSONB `style_profile`。新创建的 Bible Version 必须保存通过结构校验的完整对象，历史版本允许为 `null`，避免用当前默认值伪造历史设置。

不建议新增独立 `style_contracts` 表。现有 Bible 已具备不可原地修改、按版本创建、当前版本指针等能力，直接复用最简单，也能使历史 Run 的风格来源可追踪。

推荐保留现有标量字段：

```text
novel_bibles.tone
novel_bibles.pov
novel_bibles.tense
```

并在 `novel_bibles` 增加一个低频读取、整体版本化的 `style_profile` JSONB。建议结构为：

```json
{
  "subgenre": "东方玄幻",
  "target_platform": "general",
  "primary_style": "light_humorous",
  "secondary_styles": ["accessible_brisk"],
  "language_era": "modern_spoken",
  "pacing": "balanced",
  "parameters": {
    "ornateness": 2,
    "dialogue_ratio": 4,
    "description_density": 2,
    "psychology_density": 2,
    "humor_level": 4,
    "literary_level": 2
  }
}
```

上例只表达结构，不能据此认定这些值就是迁移后的最终选择。尤其是当前小说的 `tone` 和 `pov` 存在冲突，最终值必须由用户确认。

选择一个 JSONB 而非为每项高级参数新增列，依据是这些字段低频查询、总是随 Bible 整体版本化，且参数集合属于同一结构。`tone`、`pov`、`tense` 继续保留为独立列，因为它们已经存在、是高可见度叙事基线，并需要直接展示和校验。

建议约束：

- 新创建的 Bible Version 必须提供 `tone`、`pov`、`tense`、`primary_style`、`language_era` 和 `pacing`；
- `secondary_styles` 最多两项，不能与 `primary_style` 重复；
- 六项高级参数必须是 1～5 的整数；
- 配置型字段只接受 `config/narrative.php` 中存在的稳定 code；
- `style_profile` 在数据库层至少限制为 JSON object，在 Laravel 层校验内部结构；
- 历史 Bible 的 `style_profile` 可以为 `null`，用于准确表达“当时尚未记录”，不能用当前默认值伪造历史设置。

正向样例和禁用表达对提升风格稳定性有价值，但它们是新增能力，不是本次字段合并的必要条件。若后续实施，应作为 Bible 的可选版本化内容加入，不能阻塞这次合并。

### 5.8 Bible 表单与版本交互

已确认扩展现有 Bible Version 的创建、展示和校验流程，使叙事基线与全部文风设置在同一个版本中创建、查看和验证。

小说圣经页面建议把现有表单整理为以下顺序：

1. **作品定位**：一句话定位、主题、子题材、目标平台。
2. **叙事与文风基线**：基调、视角、时态、主文风、辅助文风、语言时代感、故事节奏。
3. **文风高级设置**：华丽度、对白占比、环境描写、心理描写、幽默程度、文学性；默认折叠。
4. **写作边界**：禁忌、硬约束。
5. **结局契约**：保持现有职责。

已确认从 Novel 新建/编辑表单移除“创作风格”和“文风高级设置”，避免第二个写入口。新建小说后的流程调整为：

```text
创建 Novel 基础信息
→ 创建首个完整 Bible Version（同时确定叙事与文风基线）
→ 才能进入章节规划与生成
```

若通过 AI 生成小说蓝图创建 Bible，蓝图候选结构也必须包含同一套 `tone`、`pov`、`tense` 和 `style_profile`，采用前允许用户审阅；采用后仍通过现有 Bible Version 创建动作落库。不能先把文风写回 `novels.settings.editorial` 再同步到 Bible。

修改任一叙事或文风字段都必须创建新 Bible Version，不能原地更新。新版本表单应预填当前版本全部内容，并在版本历史中显示基调、视角、时态、主文风、辅助文风和高级参数的变化。这样“为什么后续章节文风发生变化”可以追溯到明确的 Bible Version。

### 5.9 现有数据迁移方案

已确认数据迁移采用“创建新 Bible Version”方式，不修改现有版本。Editorial 独有的文风字段可以自动复制；与 Bible 重复的基调、视角若存在冲突，必须由用户选择。

当前 `NovelBible` 明确禁止原地修改内容。因此，迁移不能回填并改写现有 Bible Version 的历史含义，推荐分三步完成：

1. **增加承载能力**：为 Bible 增加可空的 `style_profile`，并让新版本写入完整结构；历史版本保持 `null`。
2. **创建迁移版本**：对每部小说复制当前 Bible 的非冲突内容，把现有 Editorial 的主/辅文风、语言时代感、节奏、高级参数、子题材和目标平台带入一个新的 Bible Version。
3. **关闭旧来源**：所有当前 Bible 都完成迁移后，删除 UI 写入口和运行时读取回退，再清理 `novels.settings.editorial`。

不能对重复字段静默设置优先级。迁移判断规则应为：

- Editorial 独有字段可以按原始 code 原样带入新 Bible Version；
- `tone/story_tone` 或 `pov/narrative_pov` 只有在确定性映射后语义一致时才可自动确认；
- 两者不一致、无法映射或含义不等价时，必须展示两侧原值，由用户选择新 Bible Version 的最终值；
- 在冲突解决前，自动章节生成应停止并提示“叙事与文风基线待迁移”，不能由不同 Stage 各自选择。

自动复制只能复制现有原值，不能在迁移过程中替换风格 code、补造缺失值或根据正文反推设置。创建新版本成功后，应校验新版本包含完整 `style_profile` 并已成为 Current Bible；迁移重复执行时，已经完成迁移的小说必须跳过，避免产生内容相同的重复版本。

当前《六环余光》的 Bible 是“严肃且充满希望 / 第三人称有限视角 / 过去时”，Editorial 是“热血 / 第一人称”。两组值明确不一致。用户已经确认迁移后的权威基调采用“热血”，权威视角采用“第一人称”；`tense` 没有 Editorial 重复来源，本方案保留当前 Bible 的“过去时”。迁移时应把这三个值与其余 Style Profile 一起写入新的 Bible Version，不修改 Bible v1。

已确认迁移期暂时保留旧 Editorial 读取能力，但第 4 阶段明确把它限制在迁移预览、冲突对照和数据复制中。章节生成不得再以旧 Editorial 作为 Style Contract 来源；Current Bible 缺少合法 `style_profile` 或迁移尚未完成时，生成前置检查必须停止流水线。兼容读取只用于迁移窗口，不应形成长期双读逻辑。

### 5.10 Style Contract 的生成方式

已确认切换唯一运行时来源：`NarrativeStyleProfile` 和 Bible 创建后的全部章节生成阶段只读取当前 Bible Version。

合并后，`NarrativeStyleProfile` 不再从 `novels.settings.editorial` 组装风格，而是只从当前 Bible Version 读取：

```text
Bible tone / pov / tense
+ Bible style_profile
→ 规范化并展开为 Style Contract
→ 注入 L4 Style
```

Bible Version 本身就是 Style Contract 的业务版本，不需要再维护另一套可编辑版本号。运行时可以根据 Bible ID、版本和规范化后的风格字段计算 checksum，用于 `input_hash`、Run 快照和调试，但 checksum 不是新的业务数据源。

“全部生成阶段”在此处指 Chapter Planner、Scene Writer、Assembler、Reviewer、Rewriter 和长度修复。首次 AI 小说蓝图生成发生在 Current Bible 创建之前，无法读取尚不存在的 Bible；它只能生成包含完整叙事与文风设置的 Bible 候选，用户采用后创建首个 Current Bible，后续章节阶段才能以该版本为唯一来源。

章节生成前应执行统一前置检查，至少确认：

- 小说存在 Current Bible；
- Current Bible 的 `style_profile` 存在并通过结构校验；
- 基调、视角冲突已经处理；
- 当前小说没有处于待迁移状态。

任一条件不满足时不得派发新的生成 Stage，并应向用户显示缺少的迁移条件，不能回退到 Editorial 后继续生成。

Style Contract 至少展开为：

```text
bible_id / bible_version / checksum
tone / pov / tense
subgenre / target_platform
primary_style / secondary_styles
language_era / pacing
expanded_parameters
```

其中 Primary Style 是主体表达方式；Secondary Styles 只能补充指定特征，不能覆盖 Primary。数字参数在进入 Prompt 前转换成可执行约束，例如对白密度、句长倾向、心理描写密度、环境描写上限和幽默使用位置。严肃危机等场景可以降低笑点密度，但不能改变 POV、时态或叙述声音。

### 5.11 所有相关 Stage 使用同一 Bible 契约

同一个 Chapter Pipeline 必须冻结并复用同一个 Bible Version 及其 Style Contract：

- Planner：章节 tone 只能在 Bible 允许的范围内形成局部变体；
- Writer：严格按 Primary + 指定 Secondary 特征写 Scene；
- Assembler：保持 Scene 已有叙述声音，不重新选择风格来源；
- Reviewer：逐项对照目标 Style Contract 和正文证据，不再只给泛化 style score；
- Rewriter：修复 Finding 时必须保留原 Pipeline 冻结的 Style Contract；
- 所有长度扩写/压缩请求：继续注入同一契约，避免修长度时洗掉文风。

Style Contract 的 Bible Version/checksum 必须进入各 Stage 的 `input_hash` 和 `context_snapshot`。Bible 产生新版本后，新启动的 Pipeline 使用新版本；已经开始的 Pipeline 是否切换版本不能静默决定，建议保持原快照直到本章结束，若用户要求立即应用则显式重启本章生成。

### 5.12 本次合并的非目标

本次合并不需要：

- 新建 Style Contract 表或独立风格版本系统；
- 引入新的 Agent、工作流引擎或外部依赖；
- 修改已经 Canonical 的章节正文或历史 Run 快照；
- 自动判断当前冲突数据哪一侧“更正确”；
- 同时重构题材、平台、模型或费用配置体系。

## 6. 内容质量的进一步优化

### 6.1 让 Scene Outcome 成为明确验收项

当前计划已有 goal/conflict/turn/outcome，应先加强现有字段的执行和验证，不急于增加大量表或 Agent。

建议：

- Writer Prompt 明确 `outcome` 为不可省略、不可反转的验收项；
- `self_check` 改成固定 Schema，逐项声明 goal/conflict/turn/outcome 是否落实，并提供正文证据；
- Laravel 校验引用与字段完整性，但不把模型自检当作事实；
- Assembler 同时获得每个 Scene 的验收项，组装后返回一个结构化 coverage 列表；
- Coverage 缺失直接进入一次定向修复，不等到最终 Review 才发现。

不建议为此增加独立“Critic Agent”。仍由 Laravel 控制一次定向修复是否执行。

### 6.2 区分计划错误与正文错误

第 4 章的“继续调查许可”本身存在解释空间：允许补充证词/协助调查与禁止单独接触晶石可以同时成立。Reviewer 把它判为正文违背计划，但也可能是 Plan 的验收表述不够精确。

推荐在 Plan 中把 Outcome 拆成可验证的行为边界，例如：

```text
允许：补充证词、在记录员安排下协助调查
禁止：单独接触晶石、自行追查
```

这样 Writer、Assembler、Reviewer 使用同一组边界，避免模型在含糊文本上互相否定。

### 6.3 建立文风评测样本

仅靠模型给自己打 style score 不足以判断风格匹配。建议为每部小说保存少量用户认可的短样例：

- 叙述段落；
- 对话段落；
- 紧张场景；
- 轻松场景；
- 明确不希望出现的表达。

先用现有章节做离线评测，不需要新基础设施。评测至少回答：

- POV/tense 是否一致；
- 主文风特征是否持续存在；
- 辅助文风是否只是补充而非抢占主体；
- 严肃场景的风格变体是否仍属于同一叙述声音；
- Rewrite/Assembly 前后是否发生风格漂移。

## 7. 推荐实施顺序

### P0：记录已定决策并确认剩余 Review 规则

已经确定的规则：

1. 自动章节流水线运行到 Review `PASS` 后停止，由用户手动执行 Canonical Commit。
2. 当前冲突数据迁移采用“热血 / 第一人称”，时态继续采用当前 Bible 的“过去时”。
3. Novel Bible 是 tone、POV、tense、主/辅文风、语言时代感、节奏和高级文风参数的唯一权威来源；不再把 Editorial 作为并列来源。
4. Bible 承载阶段增加 `style_profile`，扩展 Bible Version 创建、展示和校验，并在迁移窗口暂时保留旧 Editorial 读取能力。
5. 现有小说通过新 Bible Version 完成迁移；Editorial 独有字段按原值自动复制，基调和视角冲突由用户选择，禁止原地修改旧版本。
6. Bible 创建后的全部章节生成阶段只读取 Current Bible；Novel 表单移除旧入口，未完成迁移的小说在生成前被阻止。

已经确认普通 warning 的分流规则：可自动修复则 REWRITE；不影响发布且无需修复可 PASS；真正需要用户选择或 Rewrite 耗尽才 NEEDS_ATTENTION。Hard Conflict 仍无条件 BLOCK。

实施代码前还需把第 1 项同步到 PRD/Architecture，因为它改变了现有文档要求的自动 Commit 终点。

已经确认第 6 阶段的实施顺序：先完成 Bible 承载能力、现有数据迁移和唯一运行时来源切换，再实施以下三组工作：

1. 自动章节流水线，正常路径一次启动并运行到 Review PASS；
2. Review 决策优化与自动 Rewrite 闭环；
3. Style Contract 全链路接入及端到端测试。

这项确认确定了工作范围和先后依赖；普通 warning 的最终决策矩阵已经按上述规则确认。

### P1：自动章节流水线（第 6 阶段已确认）

涉及现有文件：

- `app/Actions/Generation/GenerateNextChapterAction.php`
- `app/Actions/Generation/SetAutoGenerationAction.php`
- `app/Actions/Generation/CheckNextAction.php`
- `app/Services/GenerationStageGate.php`
- `app/Services/GenerationJobDispatcher.php`
- `app/Services/ResumeResolver.php`
- `app/Jobs/PlanChapterJob.php`
- `app/Jobs/GenerateSceneJob.php`
- `app/Jobs/AssembleChapterJob.php`
- `app/Jobs/ExtractStoryEventsJob.php`
- `app/Jobs/ReviewChapterJob.php`
- `app/Jobs/RewriteChapterJob.php`
- Novel/Chapter Filament 页面。

建议新增：

- `app/Actions/Generation/AdvanceChapterPipelineAction.php`（当前不存在）。

验收：

- 一次“生成下一章”可在无异常时推进到 PASS，并且不会自动 Canonical Commit；
- 多 Scene 自动严格串行；
- 每个成功 Job 只派发一个下一 Stage；
- 重复投递不会重复调用已成功的昂贵阶段；
- Pause 后不派发下一 Stage；
- 任意 Worker Crash 后可从 PostgreSQL 状态恢复。

### P2：Review 决策与自动 Rewrite（第 6 阶段已确认）

涉及：

- `app/Services/ChapterReviewer.php`
- `app/Services/ChapterRewriter.php`
- `app/Jobs/ReviewChapterJob.php`
- `app/Enums/ReviewDecision.php`
- Review/Chapter UI。

验收：

- 一条普通 warning 不会直接导致 NEEDS_ATTENTION；
- 第 3、4 章这类可定位 Plan Finding 会自动进入最小范围 Rewrite；
- Rewrite 最多 2 次，之后才 NEEDS_ATTENTION；
- 人工修订不消耗自动 Rewrite 次数；
- Hard Conflict 永远不会被模型高分或 Override 自动提交。

### P3：Style Contract 全链路（第 6 阶段已确认）

涉及：

- `app/Services/NarrativeStyleProfile.php`
- `app/Services/ContextBuilder.php`
- `app/Data/ContextSnapshot.php`
- `app/Services/ChapterPlanner.php`
- `app/Services/SceneGenerator.php`
- `app/Services/ChapterAssembler.php`
- `app/Services/ChapterReviewer.php`
- `app/Services/ChapterRewriter.php`
- `config/narrative.php`
- `app/Models/NovelBible.php`
- `app/Actions/Novels/CreateBibleVersionAction.php`
- `app/Filament/Resources/Novels/Pages/ManageNovelBible.php`
- `app/Filament/Resources/Novels/Schemas/NovelBibleDetails.php`
- `app/Filament/Resources/Novels/Schemas/NovelForm.php`
- Novel 创建/编辑页面与小说蓝图创建流程；
- 一个为 `novel_bibles` 增加 `style_profile` 的数据库迁移（尚未创建，不在本次文档任务中实施）。

验收：

- Novel 编辑表单不再保存 `settings.editorial`，Bible 页面成为唯一写入口；
- 新 Bible Version 同时保存 tone、POV、tense、主/辅文风和高级参数，旧版本保持不可变；
- Bible Version 的创建、详情展示、历史展示和输入校验覆盖完整 `style_profile`；
- 旧 Editorial 只允许迁移预览和数据复制读取，不能作为章节生成回退来源；
- 未迁移、缺少 Current Bible 或 `style_profile` 校验失败的小说不能启动新的生成 Stage；
- 迁移重复执行不会为同一组内容创建重复 Bible Version；
- 所有创意、审校、重写、长度修复 Run 快照包含相同 Bible Version/Style Contract checksum；
- 迁移后的权威值为“热血 / 第一人称 / 过去时”，并写入新的 Bible Version；
- Reviewer 能引用目标风格规则和正文证据解释 style score；
- Rewrite 后风格指标不会无提示漂移；
- 创建新 Bible Version 后，不复用旧 Style Contract 的生成结果；
- 当前《六环余光》的 Bible v1 保持不变，迁移结果通过新版本表达。

### P4：Plan Adherence 前置质量门

验收：

- Scene goal/conflict/turn/outcome 有固定自检结构；
- Assembly 可指出缺失的 Scene Outcome；
- 明确、局部的缺失先定向修复，不直接整章重写；
- 最终 Review 不再频繁发现本可在 Scene/Assembly 阶段捕获的计划遗漏。

### P5：端到端测试与质量基线（第 6 阶段已确认）

建议新增一个当前不存在的端到端 Feature Test，例如：

- `tests/Feature/ChapterPipelineOrchestrationTest.php`

至少覆盖：

```text
single trigger → plan → scenes sequentially → assembly → events → patch → review
PASS → stop without dispatching canonical commit
manual commit after PASS → canonical
REWRITE → rewrite → extract → patch → review → PASS
rewrite exhausted → NEEDS_ATTENTION
hard finding → BLOCK
pause between any two stages → no next dispatch
duplicate delivery → exactly one effective artifact/commit
worker crash → resume from persisted stage
style change → old artifact not reused
review/rewrite snapshots contain Style Contract
```

先记录当前基线，再设质量目标。样本只有 4 章，不建议现在凭空设定“首稿 PASS 必须达到某个百分比”。

## 8. `.env.example` 与配置一致性

用户指定的 `.env.example` 已检查。

已确认：

- `.env.example` 写的是 `AI_MODEL=gpt-4.1-mini`，各 Stage Override 为空。
- 当前实际运行配置是 `AI_MODEL=gpt-5.6-luna`，各 Stage Override 为空。
- `AiSettingsResolver` 在 Stage 值为空时回退到全局 `AI_MODEL`。
- 当前数据库中的相关 Run 均记录为 `gpt-5.6-luna`。

结论：示例配置不能重现当前运行模型，但没有证据表明这直接造成了三个用户症状。它会增加排查难度，建议：

- 让 `.env.example`、`config/ai.php` 默认值和设置页“Resolved Model”说明保持一致；
- 文档明确“空 Stage Override 回退全局模型”；
- 继续以每个 Run 的 `model_policy` 作为历史事实，不根据 `.env.example` 推断历史调用模型。

## 9. 风险与约束

- 不能为了减少点击绕过 Event Extraction、State Patch、State Validation、Review 或 Canonical Commit。
- 自动化只改变“由 Laravel 自动派发下一步”，不改变 Draft/Canonical 隔离。
- 同一 Novel 仍然串行生成章节与 Scene。
- NEEDS_ATTENTION/BLOCK 仍然必须停止自动生成。
- Style 设置变化不能追溯修改已经 Canonical 的正文；既有正式章节保持不可变，需要修改时走明确的回滚/重生成流程。
- 已有 Bible Version 不得为了补入 Style Profile 而原地改写；迁移必须创建新版本。
- `settings.editorial` 的兼容读取只能存在于迁移窗口，不能成为长期双来源。
- 当前第 1、2 章的历史缺失 State Patch Review 必须保留，不应删除或改写审计记录。

## 10. 完成标准

本优化完成后，正常用户路径应为：

```text
点击“生成下一章”一次
→ 自动计划
→ 自动顺序生成全部 Scene
→ 自动组装
→ 自动提取事件与构建状态补丁
→ 自动审校
→ 可修复问题自动最多重写 2 次
→ PASS 后停止并等待用户确认
→ 用户点击“提交正式章节”
→ Canonical 后更新 Memory
→ auto_generate 开启时再启动下一章并运行到 PASS
```

只有以下情况进入人工处理或恢复界面：

```text
Hard Conflict
真正需要用户选择的歧义
Rewrite 耗尽
Ending 重大方向冲突
预算硬限制
不可恢复的结构化输出/Provider 失败
用户主动暂停
```

同时，任意一次正文生成、组装、审校或重写都能回答：

```text
使用了哪个 Bible/State/Plan？
使用了哪个 Bible Version 和 Style Contract checksum？
主文风和辅助文风分别要求什么？
叙事与文风是否全部来自同一个 Bible Version？
Reviewer 为什么认为风格通过或不通过？
Rewrite 是否保持了目标文风？
```
