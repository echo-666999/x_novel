# 生成工作流质量优化验证与恢复说明

> 任务：GWQ-014、GWQ-015（Batch D）  
> 验证日期：2026-09-17  
> 当前阶段：冻结 dry-run、自动回归和登录态浏览器回归已完成；真实恢复执行待审核  
> Provider：自动测试全部使用 Fake Provider 或脱敏事故夹具；本轮尚未调用真实 Provider

## 1. Bible 变更恢复边界

`novel:recover-bible-chapter` 默认只输出 JSON 报告，不写入数据。报告冻结以下输入：

- Expected Bible Version、Bible ID；
- Expected Canonical State Version 与 checksum；
- Chapter 状态；
- Plan ID、版本与状态；
- Scene ID、顺序、状态与当前 Artifact 指针；
- Generation Run ID；
- Artifact ID、类型与 checksum。

执行阶段必须提供同一份 `plan_hash` 和有效 User ID。Action 在 PostgreSQL 事务锁内重新计算报告，来源链有任何变化都会拒绝旧 hash。它只允许恢复当前 Canonical 章之后的下一章；创建新 Bible Version 后，从 Chapter Planning 重新开始，旧 Run、Artifact、原始响应和 Usage 保持不变。

只读命令：

```bash
php artisan novel:recover-bible-chapter 2 12 \
  --expected-bible=5 \
  --expected-state=14 \
  --target-pov=第一人称
```

审核通过后的显式命令：

```bash
php artisan novel:recover-bible-chapter 2 12 \
  --expected-bible=5 \
  --expected-state=14 \
  --target-pov=第一人称 \
  --execute \
  --plan-hash=1e29a14191465d04021bdb325c96fabe2192e0db5ca5de99857dd6799a836b3f \
  --actor=1
```

上面的 execute 命令截至本记录尚未运行。它会产生真实业务写入并派发 Chapter Planning，后续预计需要 1 次 Planning、3 次 Scene、1 次 Assembly、1 次 Extraction、1 次 Review，以及按审校结果 0～2 次 Rewrite Provider 请求。

## 2. 《六环余光》冻结报告

当前只读快照：

| 项目 | 已确认值 |
|---|---|
| Novel | ID 2，`generating`，当前 Canonical Chapter 11 |
| Canonical State | v14，checksum `e0367a6534f0650e15d4485f949d22c6163721e4e65f683d8f7f9f4e056e3b15` |
| Current Bible | ID 5 / v5，热血、第三人称全知、过去时 |
| 目标 Bible | v6；只把 POV 改为第一人称，其余 Bible 内容不变 |
| Chapter 12 | ID 12，`blocked`；Plan 16；Scene 40～42 |
| 旧来源链 | Run 341～350；Artifact 220～230 |
| 失败审计 | Run 350 保留，含 1 条 Usage；不删除原始失败记录 |
| Story State 重放 | 完整 baseline v1 → current v14；`matches=true`，0 差异 |
| Projection | Character、World Entity、Foreshadowing 均无漂移或错误 |
| Canonical 数据 | 106 Story Events；101 Memories，其中 88 Active |
| World Entity | faction 1、location 2、concept 1 |
| Story Arc | 5 条，均无可验证的结构化历史 Beat 完成证据 |
| 冻结 plan hash | `1e29a14191465d04021bdb325c96fabe2192e0db5ca5de99857dd6799a836b3f` |

World/Arc 历史项只列为候选。现有数据不足以证明应新增独立 Rule Entity 或回填 Beat 完成记录，因此恢复流程不会根据自然语言猜测并写入正式数据。

## 3. 事故回归夹具

`tests/Fixtures/generation_workflow_quality_incidents.php` 保存脱敏、确定性的事故结构，并由现有测试直接消费：

| 事故 | 回归行为 |
|---|---|
| Run 350 审计状态与 Finding 矛盾 | 对同一 Draft/State/Bible/Prompt 只修复一次缺失维度，最终状态从 Findings 确定性派生 |
| Chapter 12 Coverage 误判 | 聚焦复核可用正文证据推翻 false negative，结果在本次 Review 内复用 |
| Chapter 11 两轮 Rewrite 后仍失败 | 达到自动 Rewrite 上限后进入 `NEEDS_ATTENTION`，不再循环派发 |
| 不完整 v0 State | 选择最新完整、无章节基线，不把不完整 v0 当作权威起点 |
| Header Action 无响应 | 三个 Header Action 均绑定真实 Livewire 页面方法和页面对话框 |

Fake Provider 验证的是 Laravel 流程、Schema、门禁、来源链和幂等行为。脱敏事故夹具验证已发生的响应结构不会再次触发同类缺陷。二者都不能证明真实模型的文学质量。

## 4. 自动验收结果

| 验收域 | 结果 |
|---|---|
| Review / Rewrite | 86 passed，361 assertions |
| Canonical Commit / Pipeline | 40 passed，330 assertions |
| Story State / Recovery / Projection | 45 passed，256 assertions |
| Arc / World Entity | 56 tests：52 passed，4 skipped，214 assertions |
| Filament 服务端 / Livewire | 66 passed，625 assertions |
| Filament 真实浏览器 | Dashboard、4 个 Story State Header Actions、自动提交开关、Review/Commit 门禁与通知均通过 |
| Bible Recovery 专项 | 4 passed，46 assertions |
| 完整测试 | 820 tests：799 passed，21 skipped，4938 assertions，2 warnings |
| Pint | passed |
| 变更 PHP 语法 | passed |
| Migration 状态 | 27 项全部 `Ran` |
| `git diff --check` | passed |

测试框架没有返回两个 warning 的明细，因此无法确认其具体来源。完整测试没有失败。

真实浏览器首次打开 Dashboard 时发现 `DueForeshadowingsWidget` 的 raw SQL 使用未加连接前缀的表名，PostgreSQL 返回 `missing FROM-clause entry for table "foreshadowings"`。查询现已改为使用 Eloquent 已加载数据执行时间窗筛选，避免绕过连接的 `x_` table prefix；对应 Widget 5 项测试、17 assertions 通过，随后 Dashboard 在真实 PostgreSQL 上正常显示。

浏览器回归只执行了页面导航、打开/关闭对话框和未保存的开关切换：

- “校验重建结果”显示完整 baseline v1 到 current v14，checksum 一致且不写入；
- “恢复 Canonical State”在无差异时显示持久错误通知和错误编号；
- “重建投影”与“人工修正”均显示明确的确认表单，没有确认执行；
- 自动提交开关从关闭切到开启后立即恢复关闭，没有保存；
- Chapter 12 显示 `需要重写`、正式版本 `未提交`、Commit `等待中`，未出现可绕过 Review 的提交操作。

## 5. 尚未完成的发布门禁

1. 用户审核本报告和冻结 hash 后，显式执行真实恢复命令。
2. 等待 Chapter 12 新流水线完成后，只读核对所有新 Run/Artifact 均使用 Bible v6 和正确 State Version；旧 v5 来源不得进入 Commit。

在前两项完成前，GWQ-014 与 GWQ-015 保持 `IN_PROGRESS`，Batch D 不宣称发布验收完成。
