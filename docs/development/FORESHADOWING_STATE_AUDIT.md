# 伏笔事实与状态漂移审计

> 任务：FSO-001  
> 审计时间：2026-09-15 13:46:05 CST  
> 代码版本：`e4e34f1eceaac740338ca226b659725c5b101558`  
> 数据范围：本地 PostgreSQL `x_novel` 中的全部小说  
> 执行边界：只读查询；未修改业务数据，未调用模型，未修改 PHP 代码

## 1. 结论

本次数据库只有一部小说《六环余光》（Novel #2），因此“全库”审计结果等于该小说的审计结果，不能据此推断其他数据库或未来小说的实际数据情况。

已确认的核心问题如下：

1. 三条伏笔的领域表投影全部与当前 Canonical Story State 不一致：表中均为 `idea / reinforce_count=0`，Canonical State 中分别为 `reinforced / 11`、`reinforced / 7`、`reinforced / 5`。
2. 三条伏笔的第一条 Active Story Event 都是 `foreshadowing_reinforced`，没有先出现 `foreshadowing_planted`。这违反已经确认的生命周期 `idea → planted → reinforced`。
3. Critical 伏笔 #1 的兑现窗口为第 1～10 章，但第 11 章仍只产生强化事件，第 12 章 Plan 仍只引用其 ID。当前规则把“已选中 ID”当成已处理，没有在 `due_to` 后阻止普通规划。
4. 23 条 Active 伏笔事件的 evidence 全部能在对应 Canonical Artifact 正文中逐字找到，引用完整性没有发现错误；但“正文中有这句话”不能证明事件类型、目标伏笔或重复强化次数的语义判断正确。
5. Scene 上下文没有完整的伏笔动作契约；Event Extractor 又能看到全部当前伏笔，而不是仅看到本章授权目标。这为重复强化和错误绑定提供了条件。
6. 当前 `StoryStateRebuilder` 从空的 State Version 0 重放事件，而本小说的伏笔元数据从 State Version 1 才出现。只读重建结果与当前 State 有 31 处差异，因此不能直接用现有重建器执行 FSO-011 历史修复。
7. 23 条 Active 伏笔事件各自已有一条 Active Memory；另有一条来自已失效 Story Event #9 的 Memory，状态也已是 `invalid`。修正历史事件时必须同步失效并重建受影响 Memory。

## 2. 审计口径

### 2.1 事实来源

本报告严格区分以下数据：

| 来源 | 本报告中的含义 | 是否为 Canonical 内容事实 |
|---|---|---|
| `foreshadowings` 表 | 管理和查询投影 | 否；必须与 Canonical State 保持一致 |
| 当前 `story_state_versions.state` | 当前正式故事状态 | 是 |
| Active `story_events` | 已正式提交的状态变化及证据 | 是 |
| Chapter Plan | 生成时的规划输入 | 否；只证明计划选择，不证明正文落实 |
| Canonical Artifact 正文 | 正式正文证据来源 | 是 |
| Generation Run / Context Snapshot | 当时实际使用的生成上下文 | 否；用于解释流程行为 |
| Memory | Canonical 内容的检索投影 | 否；必须能追踪到有效正式来源 |

### 2.2 到期口径

依据已确认的 D-01、D-02 和 D-06：

- `due_from_chapter`～`due_to_chapter` 是兑现窗口，不是铺设窗口。
- 到期状态只按最新 Canonical 章节计算。
- 本次最新 Canonical 章节是第 11 章，因此伏笔 #1 为 `Overdue`；#2、#3 尚未进入兑现窗口。
- #2、#3 在兑现窗口前被铺设或强化，时间本身不构成错误。是否属于错误事件，需要结合事件生命周期、正文语义和本章动作授权分别判断。

### 2.3 判断边界

- “非法跳转”可以由事件顺序和已确认状态机确定性判断。
- “evidence 是否逐字存在”可以由 Canonical Artifact 确定性判断。
- “某句话是否足以构成强化”“属于 #1 还是 #3”“是否已经兑现开放式承诺”涉及故事语义，本报告不替用户作最终决定。

## 3. 数据快照

| 项目 | 数量或状态 |
|---|---:|
| 小说 | 1 |
| 章节 | 12 |
| Canonical 章节 | 11 |
| 当前 Canonical State Version | 13（记录 ID 14） |
| 伏笔 | 3 |
| Active 伏笔 Story Event | 23 |
| 已失效伏笔 Story Event | 1（Event #9） |
| Active 伏笔 Memory | 23 |
| Invalid 伏笔 Memory | 1（来源 Event #9） |

第 12 章当前为 `blocked`。其阻塞原因是第二轮审校返回 `review_validation_failed`，具体为 Narrative Review style 审计状态与 Findings 不一致；这不是伏笔逾期门禁触发的结果。第 12 章规划已经成功，且继续选择了伏笔 #1。

## 4. 逐条伏笔审计

### 4.1 伏笔 #1：魔法代价的痛苦

| 项目 | 当前数据 |
|---|---|
| 领域表 | `idea`；强化 0 次；铺设/兑现章节为空 |
| Canonical State | `reinforced`；强化 11 次 |
| 重要度 / 兑现窗口 | `critical`；第 1～10 章 |
| 当前时限状态 | `Overdue`（最新 Canonical 为第 11 章） |
| Active 事件 | #15、#23、#28、#39、#50、#62、#67、#77、#86、#91、#97 |
| 对应章节 | 第 1～11 章每章 1 条，全部为 `foreshadowing_reinforced` |
| Ready Plan | 第 1～12 章的最新 Ready Plan 均选择 #1 |

**已确认事实**

- 第 1 章从 `idea` 直接产生 `foreshadowing_reinforced`，缺少 `foreshadowing_planted`，属于非法生命周期跳转。
- 第 11 章 Event #97 已超过 `due_to=10`，仍然只是强化，没有兑现、延期、放弃或修复结果。
- 第 12 章最新 Plan 仍包含 #1；计划成功说明现有门禁没有因为 Critical 逾期而阻止普通新章规划。
- 11 条 evidence 均逐字存在于各自章节的 Canonical Artifact。
- 第 1 章正文首次建立“使用魔法会付出代价、痛苦会回来”的信息，从语义上更接近首次铺设；这是基于正文的分类建议，不是当前数据事实。

**无法确认**

- 后续 10 条证据是否每一条都构成独立且有意义的强化，不能仅凭短 evidence 自动确定。
- `promised_payoff` 为“后续魔法代价不断影响主角成长与选择”，没有可判定的完成条件，无法确认何时算正式 `paid_off`。

**建议处置**

- 在 FSO-011 中保留原事件，通过 correction / invalidation 处理非法首事件和经人工确认的冗余事件。
- 按 D-05 把“魔法必有代价”迁为世界硬规则；另建具有具体结果、兑现窗口和验收条件的伏笔。具体新伏笔内容需要用户确定，不能由迁移脚本编造。

### 4.2 伏笔 #2：反派的阴影

| 项目 | 当前数据 |
|---|---|
| 领域表 | `idea`；强化 0 次；铺设/兑现章节为空 |
| Canonical State | `reinforced`；强化 7 次 |
| 重要度 / 兑现窗口 | `high`；第 15～30 章 |
| 当前时限状态 | `Upcoming` |
| Active 事件 | #24、#29、#40、#51、#68、#78、#87 |
| 对应章节 | 第 2、3、4、5、7、8、9 章，全部为 `foreshadowing_reinforced` |
| 结构化 Plan 选择 | 未进入任何最新 Ready Plan 的 `due_foreshadowings` ID 列表 |

**已确认事实**

- 第一条事件从 `idea` 直接强化，违反生命周期。
- 这些事件发生在兑现窗口之前；按 D-01，这一时间位置本身并不违法。
- 对应 Chapter Plan 的自然语言字段多次包含“黑暗观察者”“阴影”“档案干扰”等相关提示，因此不能断言正文完全脱离计划。
- 7 条 evidence 均逐字存在于对应 Canonical Artifact。

**无法确认**

- 第一条事件应改为 `planted` 的语义可能性较高，但仍需人工核对完整上下文。
- 后续每条是否都是有效强化，还是相同信息被重复提取，不能由 evidence 存在性决定。

**建议处置**

- FSO-007 应要求事件只能引用本章冻结动作契约中的伏笔，并校验当前生命周期。
- FSO-011 由人工确认首次铺设章节和应保留的强化事件，再生成 correction / invalidation。

### 4.3 伏笔 #3：牺牲的代价

| 项目 | 当前数据 |
|---|---|
| 领域表 | `idea`；强化 0 次；铺设/兑现章节为空 |
| Canonical State | `reinforced`；强化 5 次 |
| 重要度 / 兑现窗口 | `high`；第 20～35 章 |
| 当前时限状态 | `Upcoming` |
| Active 事件 | #41、#52、#69、#92、#98 |
| 对应章节 | 第 4、5、7、10、11 章，全部为 `foreshadowing_reinforced` |
| 结构化 Plan 选择 | 未进入任何最新 Ready Plan 的 `due_foreshadowings` ID 列表 |

**已确认事实**

- 第一条事件从 `idea` 直接强化，违反生命周期。
- 这些事件发生在兑现窗口之前；时间本身不构成错误。
- 5 条 evidence 均逐字存在于对应 Canonical Artifact。
- 其多条证据与伏笔 #1 的“魔法代价、选择、控制”语义高度重叠。

**无法确认**

- #3 是否应作为独立伏笔保留，或属于 #1 世界规则/代价主题下的重复绑定，现有结构化数据不足以确定。
- 第 5 章推开苏离等行为可能支持“牺牲”语义，但其他事件是否达到独立强化标准需要人工阅读全文。

**建议处置**

- 在 FSO-011 前由用户确认 #3 的独立承诺和可验收结果；若不能明确，应合并或废弃，而不是继续自动累计强化。

## 5. 异常清单

| 编号 | 严重度 | 已确认问题 | 证据 | 后续任务 |
|---|---:|---|---|---|
| AUD-001 | P0 | 三条领域投影均与 Canonical State 漂移 | `ProjectionRebuilder::inspect` 返回 `foreshadowing_drift_ids=[1,2,3]` | FSO-009、FSO-011 |
| AUD-002 | P0 | 三条伏笔均首次从 `idea` 直接进入 `reinforced` | 首事件分别为 #15、#24、#41；此前无 Active `planted` | FSO-007、FSO-011 |
| AUD-003 | P0 | Critical #1 超过兑现窗口仍可继续普通规划 | 第 11 章 #97 仍强化；第 12 章 Plan 成功且仍选择 #1 | FSO-003、FSO-004 |
| AUD-004 | P0 | 现有状态重建基线不能完整重建本小说 | Version 0 为空、元数据从 Version 1 出现；只读重建有 31 处差异 | FSO-009 前置修正、FSO-011 |
| AUD-005 | P1 | Plan 只传 ID，没有动作、目标 Scene、验收条件 | 第 1～12 章 `due_foreshadowings` 都只是 `[1]` | FSO-004～FSO-006 |
| AUD-006 | P1 | Extractor 可从全部当前伏笔中选择非本章结构化目标 | #2/#3 共 12 条 Active 事件未在结构化 ID 列表中 | FSO-005、FSO-007 |
| AUD-007 | P1 | 事件 evidence 完整，但语义分类和重复度未受确定性约束 | 23/23 引用有效；全部类型却都是 `reinforced` | FSO-006～FSO-008 |
| AUD-008 | P1 | #1 的承诺不可确定判断完成 | `promised_payoff` 只描述“不断影响” | FSO-002、FSO-011 |
| AUD-009 | P1 | 历史修正会影响检索 Memory | 23 条 Active 事件各有 Active Memory | FSO-009、FSO-011 |

### 5.1 现有实现与问题的对应关系

- `ChapterPlanner` 选择 `due_from_chapter <= 目标章节` 的全部非终态伏笔，没有用 `due_to_chapter` 截止，也没有生成动作对象。
- `PlanValidator` 对已选 ID 直接跳过后续到期检查；它只会阻止 Critical 伏笔未被选择，不会检查最晚兑现期限。
- `ContextBuilder` 记录伏笔 ID 和当前状态摘要，没有冻结完整动作、承诺、证据标准和目标 Scene。
- `StoryEventExtractor` 的 chapter-plan 摘要没有传入 `due_foreshadowings`，同时 current state 又包含全部伏笔。
- `StateValidator` 当前没有验证伏笔生命周期转换。
- `CanonicalCommitService` 提交事件和新 State 后只触发 Memory 更新，没有刷新完整领域投影。
- `ProjectionRebuilder` 只同步伏笔 `status`，没有同步强化次数和铺设/兑现章节。
- `StoryStateRebuilder` 固定以 State Version 0 为重建基线；本小说的 Version 0 缺少后来进入 Version 1 的基础元数据。

## 6. Evidence 与来源完整性

对 23 条 Active 伏笔事件逐条核对的结果：

- 23/23 的 evidence 都能在事件引用的 Artifact 正文中逐字找到。
- 23/23 引用的 Artifact 都存在。
- 23/23 Artifact 都是对应章节当前的 `canonical_artifact_id`。
- 未发现 evidence 指向草稿或已被替换 Artifact 的情况。

因此，本次没有把任何事件标记为“伪造证据”或“引用错误”。异常集中在生命周期、动作授权、语义分类、重复强化和投影同步。

## 7. Plan 与上下文链路

### 7.1 最新 Ready Plan

- 第 1～12 章的最新 Ready Plan 都包含 `due_foreshadowings=[1]`。
- #2、#3 从未进入这一结构化数组。
- #1 在第 1～10 章位于兑现窗口内；第 11～12 章已超出窗口。
- 计划中部分自然语言约束涉及 #2/#3 的内容，但它们没有形成可校验的伏笔动作契约。

### 7.2 第 12 章实际上下文

- Scene 生成 Context Snapshot 的 `foreshadowing_ids` 为 `[1]`。
- 当前 State 摘要同时暴露 #1、#2、#3 的状态和窗口。
- Context 中没有 `plant/reinforce/pay_off/defer/abandon` 动作、目标 Scene 或验收证据。
- Event Extraction Context 没有传递 Plan 的 `due_foreshadowings`，却能看到全部当前伏笔。
- 第 12 章尚未正式提交的 Event Candidate 再次提出强化 #1 和 #3。这不是 Active Story Event，但证明当前链路仍存在相同风险。

## 8. 重建与迁移风险

### 8.1 Canonical 重建基线

本小说的 State Version 0 为空；State Version 1 在尚无 Canonical 章节时已经包含人物、世界和三条伏笔的基础数据。当前 `StoryStateRebuilder` 固定从 Version 0 重放 Active Events。

只读执行重建得到：

- 当前版本：13；
- 参与重放的 Active Events：89；
- checksum 不一致；
- 差异：31 处，其中包括三条伏笔的 title、importance、due_from、due_to 等 12 个元数据路径。

这些差异不是伏笔事件本身能够补回的，因为强化事件只更新状态和次数。无法仅凭当前证据确认 Version 1 当初为何独立于 Version 0 创建，但可以确认：在修正重建基线前，直接运行现有 rebuild 不能安全地产生等价状态。

### 8.2 Projection 重建范围

现有 Projection Rebuilder 检出 7 个总漂移，其中伏笔漂移 3 个；另外 4 个属于人物/世界投影，不在 FSO-001 的处理范围。即使运行现有伏笔 projection rebuild，也只会同步 `status`，不会修复计数和章节引用。

### 8.3 Memory 下游

- Active 伏笔事件 23 条，对应 Active Memory 23 条。
- 历史 Event #9 已 invalidated，其 Memory #9 也为 `invalid`。
- FSO-011 若失效或纠正事件，必须按最终有效事件集合重建相关 Memory，避免检索到已撤销的强化。

## 9. 必须由人工确认的内容决策

以下事项无法从数据库结构或短 evidence 确定，迁移脚本不得代替用户决定：

1. #1 第 2～11 章中哪些是真正的递进强化，哪些只是同一代价信息的重复提取。
2. #2 的首次铺设章节，以及第 3～9 章各事件是否增加了新的、可追踪的信息。
3. #3 是否保留为独立伏笔；若保留，其具体承诺、兑现结果和与 #1 世界规则的边界是什么。
4. 按 D-05 新建的具体伏笔应承诺什么结果、在哪个窗口兑现、以什么正文证据验收。
5. 历史 correction / invalidation 的最终清单。报告只列出确定性非法跳转和待复核候选，不擅自决定故事含义。

## 10. 推荐实施顺序

1. FSO-002 先把 D-01～D-07 及本报告发现的重建基线约束写入 Source of Truth。
2. FSO-003、FSO-004 实现统一时限计算、动作契约和 Critical 逾期门禁。
3. FSO-005～FSO-008 让 Writer、Assembly、Extractor、Validator、Review 和 Rewrite 使用同一冻结契约。
4. FSO-009 同时解决完整投影刷新和可靠状态重建基线；未解决前不得执行历史修复。
5. FSO-010 提供可见的内容状态、时限状态和人工恢复入口。
6. FSO-011 根据人工确认结果，以 correction / invalidation 和重建修复现有小说，不静默覆盖历史。
7. FSO-012 用 Fake Provider 完成主链、重试、回滚和兼容性回归。

## 11. 本任务实际变更

- 新增本审计报告。
- 未新增一次性审计命令：当前只有一部小说和 23 条相关事件，现有只读查询足以完成审计；新增长期维护入口没有复用收益。
- 未修改 PHP、Migration、配置、测试或业务数据。
- 未调用真实 Provider。

