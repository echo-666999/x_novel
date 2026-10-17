# XNR-005 执行权与持久推进

日期：2026-10-17。基线提交：99588fb；本次修改未提交。状态：DONE（R01 执行基础，非整书业务阶段已全部接通）。依赖：XNR-003/004 的隔离环境和 Schema。对应 PRD A04/A11/A13/A16/A26/A29。

## 实现

- GenerationRunCoordinator 在 Novel → Task → Run 锁顺序下排队、认领、保存结果和控制暂停/继续/取消。逻辑输入按规范化 JSON 哈希，JSONB 键顺序不改变身份。不同逻辑步骤也不能并行推进同一小说。
- 每个 Run 是一次尝试，技术重试最多 3 次尝试，退避 10/30 秒。成功结果复用；修改输入产生新逻辑身份，不能覆盖旧产物。暂停恢复使用 control_version CAS。
- queued Run 和 Task.pending_transition 在数据库持久化。投递使用 afterCommit 和可过期的派发标记；队列不成为执行权来源。入队前退出、重复投递可以补派原 Run；租约过期建立有 retry_of_run_id 的新尝试。
- 收尾检查 token、租约、任务归属和来源哈希/当前指针/正式状态版本。过期 Worker 或失效来源只能留下 rejected 候选；不能推进任务。源变化持久记录明确错误和待处理停点。
- 暂停后的合法响应仍保存；恢复采用保存的后继意图，不重付已完成请求。长任务没有 Worker 租约，扫描器不会因暂停数日将整书任务判死。
- Worker 丢失后，in_flight Usage 转为 unknown 并保留金额占用；只有发送前的 prepared 可确认 not_sent。释放租约不再清空费用。
- 旧恢复扫描排除 pipeline-v1 和拥有新版 Task 的小说，避免两个调度器抢同一任务。旧业务入口仍保留到 R07。

新 Job 当前只接入无模型调用的 Preflight **执行身份检查**，产物明确记录 scope=execution_foundation；完整模型预检、创作阶段和正式提交分别由 R03/R04 接入。未接入阶段明确失败，不能生成伪造正文。R01 没有修改 Canonical Commit 规则或写正式事件。

## 时限与恢复

本机实际生效：Job 330 秒，Horizon 360 秒，Run lease 390 秒，Redis retry_after 420 秒，满足先停止 Worker、再失效执行权、再重投。代码默认 Horizon 350 / Redis 480；Job 会核实实际生效关系，错误配置留下可见失败。未修改 `.env`，未重启或消费现有运行 Worker。

恢复入口仍为既有 AutomaticGenerationRecoveryService；其先处理新版 Task。运维检查顺序：当前 Task 身份/状态 → pending_transition → Run 状态、来源与租约 → Usage 是否未知 → 补派。不得直接把 unknown 费用改为零，不得恢复 paused/needs_attention 收费，不得重置 attempt。

Retry：原逻辑输入下新建尝试并累计技术次数。Resume：同 Task 恢复暂停并复用有效产物。Restart：变更输入或策略需新身份，不抹除历史；用户界面与问题累计限制由后续对应任务接入。正常阶段成功是 succeeded；没有真实检查点交接时不制造 deferred。

## 实际验证

- 重复认领/重复收尾只有一个采用结果；保存后暂停不能推进；恢复复用保存的后继。
- 过期租约产生 attempt=2，旧 Worker 晚到只保存隔离候选；源哈希变化前置阻断；暂停 4 日不被补偿器恢复。
- 独立 PostgreSQL 连接争用主任务，唯一约束拒绝第二个未结束身份。
- 实际 Redis Worker 消费重复消息，仅一个结果。
- 两个独立 PHP Worker 子进程分别在认领后、保存响应和后继意图后 `exit(17)`；补偿后分别产生新尝试和下一步骤，没有丢失已保存结果。只操作专属测试队列，finally 清理其 reserved 项。
- 最终联合回归命令及 120 passed / 1 skipped 记录见 [R01 汇总](R01-implementation.md)。固定响应和故障夹具没有真实 Provider 请求。

## 恢复边界

所有 DDL/测试数据只在 xnovel_refactor_test；生产部署和 Worker 切换未执行。不可用本报告证明真实供应商延迟、整本小说续写、任意历史回滚或线上运维已验收。中文注释解释锁顺序、旧响应隔离、费用未知和任务暂停原因；结构备注见 XNR-004。
