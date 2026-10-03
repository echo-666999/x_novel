# Outline 生成可靠性发布记录

> 日期：2026-10-01  
> 范围：OGR-001～OGR-007  
> Git 基线：`8532616 feat: 执行OGR-006`  
> 发布状态：代码与文档改动保留未提交

## 发布结论

新版 Outline Pipeline 使用 `novel-outline-pipeline-v3`，阶段固定为：

```text
Foundation
→ Structure
→ Arc Beats × Arc（串行）
→ Skeleton Assembly（确定性）
→ Main Beat Detail × Beat（串行）
→ Finalize（确定性）
```

PostgreSQL 的 Batch、Run、Artifact 和 Draft Outline 是进度事实源。Filament 活动态每 3 秒读取持久进度，终态停止；失败后只允许通过 `ResumeNovelOutlineGenerationAction` 从最早缺失且来源有效的阶段继续。

## 真实数据处置

| 项目 | 结果 |
|---|---|
| 数据库 | PostgreSQL `x_novel` |
| 目标 | Batch #1 / Novel #2 |
| 旧合同 | `novel-outline-pipeline-v2` |
| 执行前 | `running`，6 个子 Run，1 个成功 Foundation Artifact |
| 执行动作 | `novel:outline-retire-legacy-batch 1 --execute` |
| 执行后 | `cancelled / pipeline_contract_upgraded` |
| 历史证据 | 子 Run、Artifact、Usage、AI Request Log、failed job 全部保留 |
| failed job | `a5827bdf-d462-498d-a15c-7879a23072a3`，未 retry、未删除 |
| 正式数据影响 | 无 Current/Draft Outline、Bible、Chapter、Story Event 或 Canonical 写入 |

恢复方式：为 Novel #2 从页面创建新的 v3 Batch，从 Foundation 重新生成。禁止直接恢复旧 Batch 状态、修改 Artifact `scope_id`、复制 Artifact 行或重试旧 failed job UUID。

## Queue 与超时

发布核对时 Horizon 为 running，并监听：

```text
redis:generation (1)
redis:default (1)
```

实际有效顺序：

```text
OpenAI Provider Timeout 150s
< Outline Job Timeout 330s
< Horizon Worker Timeout 360s
< Redis retry_after 420s
< Stalled Run 480s
```

`generation` 队列在旧批次处置前的 pending、reserved、delayed 均为空。迁移验证期间 Horizon 被显式 pause，迁移恢复后已 continue 并复核 running。

## Migration 记录

目标迁移：

```text
2026_09_30_100000_add_outline_structure_and_arc_beats_artifact_types
```

在确认没有 `outline_structure` / `outline_arc_beats` 真实 Artifact 后：

1. 指定路径 rollback 成功；
2. 指定路径 migrate 成功；
3. 最终状态为 Batch 2 / Ran；
4. 未执行 `migrate:fresh`，未删除业务行。

## 验证边界

- 真实 PostgreSQL：完成旧批次清点、显式退役、迁移 rollback/reapply、保留证据复核。
- 真实 Redis/Horizon：完成队列空闲核对、监听 Queue 与 Timeout 顺序核对、pause/continue 状态复核。
- 自动测试：使用 Fake Provider 验证新版完整主链、Timeout、截断、Retry Exhaustion、局部 Resume、重复投递和来源链门禁。
- 浏览器：使用隔离 SQLite 验证 queued、轮询阶段、持久失败、Arc 局部 Resume、Draft Outline、重复操作和终态停止轮询。
- 未执行真实 Provider 调用；没有真实模型成本、质量或完成耗时数据。

实际自动测试结果：

| 范围 | 结果 |
|---|---|
| 旧批次退役定向测试 | 6 passed / 26 assertions |
| 任务卡四组目标测试 + 退役测试 | 66 passed / 494 assertions |
| Outline、Queue、AI Settings、Filament、Timeout、Chapter Pipeline | 124 passed / 2 skipped / 846 assertions |
| 完整套件 | 1056 passed / 26 skipped / 6431 assertions / 0 failures / 2 warnings |

完整套件只报告 2 个 warning，命令输出的 `warning_details` 为空，因此本记录不推测其来源。Pint、PHP syntax 和 `git diff --check` 均通过。

## 发布与回退

发布前保留当前 PostgreSQL 备份和 Git 版本引用。回退代码前先停止创建新 Batch；已创建的 v3 Batch 保持 failed/cancelled 供诊断，不交给旧阶段图。数据库恢复和 Git 回退分别处理，不能用 `git reset` 代替数据库恢复。

遗留 `GenerateNovelOutlineSkeletonJob` 类暂时保留，以便历史 failed job 安全反序列化；非 running 批次执行该 Payload 时直接返回。只有在 retained failed job 完成长期归档后，才能单独评估删除兼容类。
