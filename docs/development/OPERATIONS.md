# 新版整书生成运维手册

日期：2026-10-18。适用：R01–R05 新任务（`pipeline-v1`）及其 `book-derived-v1` 派生记录。R05 只在隔离库验证；本手册不表示运行环境已经迁移、Worker 已重载或 R06 已开始。

## 1 部署前与切换

1. 确认当前代码、迁移和前端构建来自同一版本，记录发布提交和任务状态。使用运行环境自己的备份流程保存 PostgreSQL 和私有导出目录。`.env`、连接密钥不得放进报告或 Git。
2. 先在隔离 PostgreSQL 执行 `php artisan test --configuration=phpunit.pgsql.xml`。配置强制数据库 `xnovel_refactor_test`、测试 Redis 前缀、禁止意外 HTTP；不在运行数据库使用 `migrate:fresh`。
3. 运行 `npm run build`。R05 无新增迁移，但依赖 R01–R04 迁移。切换时先用 `php artisan migrate:status` 核对，再在已确认环境执行 `php artisan migrate --force --no-interaction`。已有新数据时，不得强行回滚 R04 的可空评分和三层计划约束。
4. R06 才退出旧任务并准备全新真实验收。先暂停项目自己的主任务，等待在途请求保存/结算，记录旧 Run 和费用；不要清空 Redis 或使用 `queue:clear` 来代替退出任务。
5. 确认当前采用 Horizon 还是普通 Worker。Horizon 用 `php artisan horizon:terminate` 请求退出，由已有进程管理器重新拉起；普通 Worker 用 `php artisan queue:restart`。**命令返回不等于重启成功**：核对新进程及新消费心跳/执行实现标识。只处理本项目，不停止共享 Redis、PostgreSQL 或其他项目进程。
6. 确认 Cron/已有调度进程每分钟运行 `php artisan schedule:run`，`php artisan schedule:list` 只证明任务已注册。不要为每次部署再启动第二个 Scheduler。
7. 后台设置配置启用连接及基础设定、Planner、Writer、Extractor、Reviewer、Rewrite、Summary 路由。Embedding 是可降级派生能力，但实际请求仍经过费用账本。冻结的旧 Run 不随全局设置静默换模型。
8. 核对有效时限关系：`Job timeout ≤ Horizon timeout < Run lease < Redis retry_after`，连接 HTTP timeout 小于 Job timeout；以生效配置为准。配置错误会生成可见 Issue，不能靠反复重投解决。

默认以完成小说为目标。费用用于统计、未知提示和优化，不必填写金额；只有用户主动开启的硬上限才阻断新请求。缺价格不能记作零费用；供应商余额不足仍是实际请求故障。

## 2 日常观察

- 工作台以 Task 状态展示排队、运行、暂停、待处理、正文完成待导出、整书完成；正式字数来自 Canonical Chapters，不以草稿字数假报完成。
- 默认生成页按整书任务展示；`/x/generation?runs=1` 查看执行记录。逐章核对计划、正文、提取、审校、正式提交、摘要的来源；不要只查 Laravel 日志。
- 设置健康检查区分配置与实际观测：DB/Redis 探测、Horizon Master、Scheduler 最近心跳、Worker 最近消费、Provider 最近请求、持久待办和日志可写性。
- Scheduler 心跳 180 秒、Worker 消费心跳 420 秒后显示过期。Worker 空闲可能没有新消费；不能仅据过期判定离线。Provider 最近成功也不代表全部连接都可用。
- 正常链与派生分别恢复；正式状态、锁定事实、必需摘要缺失必须阻断。只缺向量时可从同小说有效正式记忆执行 SQL 召回，并展示降级原因。

## 3 常见故障与处理

| 现象 | 先看什么 | AI 路径与人工操作 | 恢复成功的证据 |
| --- | --- | --- | --- |
| 一直排队 | Task 待办、Run 派发时间、Worker 心跳、Horizon 和实际监听队列 | 无需 AI 猜测；恢复进程后执行一次对账 | 同一 Run 被认领；没有第二份正式结果 |
| Provider 超时/429 | 请求日志 ID、是否已发送、Usage 未知、退避和尝试数 | 可恢复错误有限重试；用户可查看原因和剩余次数，先解决连接/配额 | 新尝试有新 Run/Usage，原记录保留 |
| 结构/证据无效 | 原始响应、失败字段、当前正文与来源 | AI 只修失败产物；人工按字段/原文证据修正后复验 | 独立校验通过；不继承旧 PASS |
| 硬事实或来源冲突 | 锁定事实、控制版本、正式状态、当前稿 hash | 不自动覆盖锁定事实；核实修改原因，重建受影响上下文或保存新稿 | 新稿重新提取/审校，仍只由 CanonicalCommitService 提交 |
| 已提交但摘要失败 | 章节 Canonical 指针、待办 canonical_chapter_id、Summary 路由 | 保留正文；修配置后从异常中心重建对应合同，或修摘要证据 | 正式状态版本不重复增加；摘要绑定原正式正文 |
| 向量失败 | 记忆正式来源、模型/维度、derived Run/Usage、剩余尝试 | 主链可在证据完整时 SQL 降级；修连接后从记忆页重试。相同输入三次耗尽不能无限重复点击 | 向量模型/维度一致；问题有成功派生 Run；正文未变 |
| 完结仍有债务 | 完结页逐项主线、承诺、人物弧线、伏笔证据 | 默认有限自动补章；耗尽后人工填写具体收束方向并授权 1–3 章，仍逐章审校并重新审计 | 所有必需项有正式证据且无 critical 债务；不能按字数强行完成 |
| 正文完成、导出失败 | 冻结清单、正式章序、文件权限/磁盘、文件 hash | 不需要模型；修存储后在异常中心重试导出，或重新下载冻结正式快照 | 文件存在且校验通过，Task 才 completed |
| 人工保存提示版本过期 | 打开时稿件 ID/hash、控制版本、当前计划 | 保留编辑文本；复制待保存内容，刷新核对新版本，再有意识地合并提交 | 新 Artifact 和独立审校；旧稿不覆盖 |

异常中心提供 AI 诊断/允许的修复动作，以及人工说明、暂停、修配置、改稿/重建入口。不存在安全自动动作时明确解释原因；按钮点击或诊断完成不等于原问题解决。完结授权在完结页操作，向量重试在记忆页操作；已结束任务的派生故障不重开正文主链。

## 4 对账、重建和撤回

以下命令已核对实际 `artisan help`，示例中的 `<小说ID>` 需替换为目标小说主键：

```bash
php artisan generation:recover-automatic
php artisan story:rebuild-state <小说ID> --dry-run
php artisan memory:rebuild <小说ID>
```

`generation:recover-automatic` 会读取持久断点并可能派发下一请求，**不是只读诊断**。暂停任务不自动恢复；成功且输入相同的产物优先复用。该命令还保留旧链恢复分支，运行切换必须先按 R06 退出旧任务，不能在旧任务仍活动时当作只处理新版的命令。

`story:rebuild-state --dry-run` 只校验。未经核对不要移除 dry-run。`memory:rebuild` 派发正式章节记忆重建并可能触发 Embedding 费用；`--sync` 是同步执行选项，不等于无费用。不要用旧摘要补录命令替代新链的必需摘要合同。

最近章撤回从状态页执行：先暂停，填写原因。只允许最新正式章；追加恢复状态版本，失效该章事件/记忆/后续候选，保留不可变历史，清除失效完结/导出证据。人工正式修正同样要求暂停；不能靠编辑 Chapter 表直接改正式内容。

## 5 故障交接信息

记录：发布提交、Novel/Task/Chapter/Run/Issue ID、control_version、当前正式状态、相关 Artifact/checksum、错误代码、最后进展和观测时间、已执行动作、Usage 已知/未知、下一步及验证方式。只保存脱敏日志；不要附完整 `.env`、API Key 或原始连接凭据。

若需要回退代码，先暂停并保存现场，评估新数据能否被目标版本理解。R05 使用既有表字段，不代表退回旧代码就能安全续写新任务。失败时保留暂停和历史，不能清库重跑来掩盖正式一致性问题。
