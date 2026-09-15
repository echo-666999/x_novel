# 伏笔生成闭环验证与恢复说明

> 任务：FSO-012  
> 验证日期：2026-09-15  
> 验证方式：Laravel 流程、SQLite 内存测试数据库、Fake Provider  
> 外部影响：没有调用真实 AI Provider，没有产生模型费用，没有修改现有小说业务数据

## 1. 已确认的端到端行为

`ChapterPipelineOrchestrationTest` 使用同一个 Novel、同一条 Critical 伏笔和三个连续章节，实际执行以下流程：

```text
idea
→ 第1章 Plan plant
→ Scene/Assembly fulfilled Coverage
→ ForeshadowingPlanted Candidate
→ Review PASS
→ 用户手动 Canonical Commit
→ planted State/Event/Memory/Projection
→ 第2章上下文读取 planted
→ reinforce → PASS → 手动 Commit
→ reinforced State/Event/Memory/Projection
→ 第3章上下文读取 reinforced
→ pay_off → PASS → 手动 Commit
→ paid_off State/Event/Memory/Projection
```

测试确认每一章在 PASS 后、Commit 前仍处于 Review，Story Event 和 State Version 均未提前产生；Commit 后才创建 Active Event、新 State Version 和 Active Memory。三次投影刷新后 `status`、`reinforce_count`、`setup_chapter_id`、`payoff_chapter_id` 与 Canonical State 和 Active Events 一致。

## 2. FSO-012 场景覆盖矩阵

| 必须场景 | 自动验证证据 | 已确认行为 |
|---|---|---|
| `idea → planted → reinforced → paid_off` | `ChapterPipelineOrchestrationTest` 新增三章闭环用例 | 每章读取上一正式版本，最终投影为 `paid_off` |
| 同章 `plant → pay_off` | `PlanValidatorTest`、`StoryEventExtractionTest` | 合法顺序可进入生成并形成候选 |
| `idea → reinforced` | `PlanValidatorTest`、`StoryEventExtractionTest`、`StateValidatorTest` | 规划、提取和 State 校验均阻止 |
| 未选中或未来伏笔事件 | `StoryEventExtractionTest` | 不允许生成候选，不修改 Canonical State |
| Critical due 缺少动作 | `PlanValidatorTest` | 产生阻断 Finding |
| Critical 在 `due_to` 只 reinforce | `PlanValidatorTest` | 必须改为解决动作，否则阻断 |
| Critical overdue 普通下一章 | `PlanChapterJobTest` | Provider 调用前以 `critical_foreshadowing_overdue` 停止 |
| 用户授权延期后恢复 | `ForeshadowingManagementActionsTest` | 窗口后移并记录审计，下一章重新通过 Planning Gate |
| Coverage 缺失进入 Rewrite | `SceneGenerationTest`、`ChapterAssemblyTest`、`ChapterReviewTest` | 形成稳定、可定位、可自动修复 Finding |
| 改变承诺或窗口 | `ChapterReviewTest` | 进入 `NEEDS_ATTENTION`，不让 Rewrite 改 Canonical 定义 |
| PASS 不自动 Commit | `ChapterPipelineOrchestrationTest`、`ChapterReviewTest` | 停在 Review，等待用户提交 |
| Commit 后完整投影刷新 | `CanonicalCommitServiceTest`、三章闭环用例 | 由 Canonical State 与 Active Events 重建全部投影字段 |
| Projection Job 重复执行 | `ProjectionRebuilderTest`、`RefreshNovelProjectionJobTest` | 不重复累计强化次数，旧任务不能覆盖新指针 |
| Projection 失败后恢复 | `RefreshNovelProjectionJobTest` | Canonical 指针和版本保留，可独立重试 |
| Latest Chapter Rollback | `CanonicalCommitServiceTest` | 恢复指针、失效派生数据并恢复伏笔投影 |
| 历史整数数组 Plan | `PlanValidatorTest`、`StoryEventExtractionTest` | 只兼容读取，不被当作新动作授权 |
| 失效 Event | `ProjectionRebuilderTest`、`ForeshadowingHistoryRepairTest` | 不进入状态重放、Memory 或强化次数 |
| 重复投递、暂停、恢复 | `AdvanceChapterPipelineActionTest` 及各阶段测试 | 基于持久化 Run/Artifact 恢复，不重复调用或越过暂停点 |

## 3. 当前版本和兼容边界

当前运行配置：

| 阶段 | Prompt Version |
|---|---|
| Chapter Planner | `chapter-planner-v7` |
| Scene Writer | `scene-writer-v12` |
| Assembler | `assembler-v10` |
| Event Extractor | `event-extractor-v6` |
| Reviewer | `reviewer-v10` |
| Rewrite | `rewrite-v11` |

Context Snapshot 为 Schema v3。完整伏笔动作契约为 `foreshadowing-contract-v1`，Scene 阶段保存在 `l0.foreshadowing_contract`，顶层保存 checksum；后续阶段保存同一冻结契约和 checksum。

`chapter_plans.due_foreshadowings` 的历史整数数组仍可读取，但不能授权新伏笔动作，也不能满足 Critical 到期校验。新计划只使用结构化 `foreshadowing_actions`。历史 `due` 内容状态和 `foreshadowing_due` 事件只作兼容读取，不产生新值。

## 4. 运行和恢复边界

- 正常运行自动推进到 Review PASS，然后停止。只有用户执行“提交正式章节”才能进入 Canonical Commit。
- 暂停时不创建新阶段，已有请求完成后只保存 Artifact；恢复依据 PostgreSQL 中的 Chapter、Generation Run、Artifact、Review 和 State Version 判断断点。
- 技术性 Provider 故障由相同阶段重试；Schema、业务规则、State Version 或 Locked Fact 冲突不得无脑重试。
- Coverage 或可修复审校问题进入一次包含全部目标的 Rewrite；Rewrite 后重新执行 Assembly、Event Extraction、State Patch 和全量 Review。
- Projection Refresh 失败不回滚正式章节。重试当前 State Version 对应的刷新任务，或在伏笔管理页使用幂等投影重建；过期版本任务会直接退出。
- Critical 逾期时普通规划被阻止。用户必须从伏笔管理入口安排兑现、明确延期或明确放弃；延期和放弃均保留操作者、原因与版本证据。
- 仅支持回滚最新 Canonical Chapter。回滚会恢复 Canonical 指针、失效该章事件和 Memory，并按恢复后的 State Version 刷新投影。
- 历史语义错误继续使用冻结计划 `dry-run → 用户明确批准 → --execute`，不能直接改写旧 Event 或旧 State Version。

## 5. 实际验证结果

| 验证层级 | 结果 |
|---|---|
| 新增三章伏笔端到端用例 | 1 passed，66 assertions |
| 新增/修改测试文件回归 | 6 passed，151 assertions |
| FSO 受影响模块回归 | 285 tests：283 passed，2 skipped，1462 assertions |
| 完整 Laravel 回归 | 798 tests：777 passed，21 skipped，4798 assertions，2 warnings |

测试框架没有提供两个 warning 的明细，因此当前无法确认其来源，也不能据此断言它们与 FSO 无关。最终完整回归应在全部测试和文档修改完成后再运行一次，以最终输出为准。

## 6. 验证限制

Fake Provider 只能证明 Laravel 流程、Schema、门禁、状态隔离、幂等和恢复行为，不能证明真实模型每次都能写出文学质量合格的伏笔内容。本任务没有运行真实 Provider，因此不对实际模型的语义判断准确率、正文质量、延迟或费用作结论。

