# XNR-026 全模块集成与故障记录

日期：2026-10-18。执行者：Codex。状态：**DONE**。XNR-035 最后缺口已关闭，最终结果见文末与 R05 续办第 12 节；以下早期数据与阻塞保留为历史记录。

## 环境与结果

基线 `0c3a4f8`（R04）。专用 PostgreSQL `xnovel_refactor_test`、隔离 Redis 前缀、Fake Provider + Tracking/Usage；禁止意外 HTTP。所有正式数据均为测试夹具，不读写运行小说。没有 R06 真实请求或真实作品质量结论。

整合回归：**268 通过、1 跳过、2239 断言**，见 [结果](assets/R05/integration-pass.json)。跳过为 SQLite 专用项，必需 PostgreSQL 并发检查实际运行。命令：

```bash
php artisan test --configuration=phpunit.pgsql.xml tests/Feature/Refactor tests/Feature/IsolatedTestEnvironmentTest.php tests/Feature/GenerationStageContractTest.php tests/Feature/StateValidatorTest.php tests/Feature/StatePatchBuilderTest.php tests/Feature/MemoryUpdaterTest.php tests/Feature/MemoryInvalidatorTest.php tests/Feature/ManualCanonicalCorrectionTest.php tests/Feature/Filament/NovelResourceTest.php tests/Feature/Filament/NovelAiSettingsTest.php tests/Feature/Filament/BudgetUiTest.php tests/Feature/Filament/GenerationPageTest.php tests/Feature/Filament/NovelChapterDetailTest.php
```

收尾补验导出恢复、非空事件/事实事务失败、撤回续写及健康过期：**5 通过、60 断言**，[结果](assets/R05/recovery-pass.json)。最终受影响文件复验 **115 通过、958 断言**，见 [结果](assets/R05/final-affected.json)；各组重叠，不相加。导出快照全身份/临时发布的收尾复验 **6 通过、96 断言**，见 [导出专项](assets/R05/export-final.json)；未部署新表时的提示和已部署页面复验 **2 通过、29 断言**，见 [部署页面专项](assets/R05/deployment-ui-final.json)。

Pest JSON `result=passed` 时本机 Artisan 包装仍返回 1；报告同时保留原始结果，不声称退出码为零。

## 失败与修复记录

- 首次扩展回归为 255 通过、26 失败、29 错误、1 跳过：[原始记录](assets/R05/initial-integration-failures.json)。其中 14 项新预检要求 Summary 路由而 R03 夹具缺该路由；6 项旧 Memory 夹具使用 3 维而真实 PostgreSQL 列为 1536 维。已修正夹具并包含在 268 项通过回归中，没有更改实际向量列或放宽模型维度检查。
- 旧 `CanonicalCommitServiceTest` 35 个失败在独立临时 **R04 原提交**中复现：46 项中 11 通过、12 失败、23 错误、83 断言；失败测试名与本轮首次运行一致，[基线证据](assets/R05/r04-baseline-canonical.json)。主要为旧 Outline Completion 来源/契约与旧候选主体夹具。该套旧测试未删除、未放宽旧门禁，R05 不宣称全仓库全绿；R07 清理需按依赖处理。
- 新专项最初有两处夹具错误：阶段名断言和试图修改不可变 Artifact；按真实阶段名、创建新产物版本修正。后续非空 Fact 故障夹具显式提供受支持事实候选，不把空事件提交当作事实写入验证。
- 故障注入发现导出成功后文件丢失的交接失败误归初始化问题；现保留原冻结导出合同、使用一致输入身份，显示可执行的仅重建导出操作。复验成功关闭原 Issue，不增加 Usage。
- 最近章撤回凭证补齐新链 scope/logical identity；登录鉴权下载修正实际登录路由；正式提交与摘要准入分离，摘要配置故障不回滚正式章。各变化均有针对性测试。

## 模块证据映射

| 模块 | 本批处理与验证 |
| --- | --- |
| F01 概述 | 复用 R03 意图/候选/矛盾机制；NovelBookWorkflowTest 重跑 |
| F02 配置预检 | 增加必需 Summary 路由；观测型健康检查、过期状态、统一费用策略 |
| F03 原子初始化 | 复用 R03 同事务初始化；固定响应经真实入口执行 |
| F04 三层规划 | 跨正式状态滚动下一章；有限收束窗口；无 Arc/Beat/Scene 依赖 |
| F05 计划与上下文 | 正式状态/摘要前置；可选同书记忆，缺证据阻断 |
| F06 正文生成 | R04 整章/拆分/截断测试重跑；不改正文生成策略 |
| F07 提取与审校 | 独立来源与规则重验；提交不继承旧 PASS |
| F08 修复 | R02/R04 问题修复测试；摘要准入、导出恢复和有限补章人工入口 |
| F09 提交与续章 | 正常/重复/CAS/暂停/真实双连接竞争；事件、事实、状态、指针、凭证各写入故障回滚；摘要断点补派 |
| F10 记忆 | 正式来源、回滚失效、必需摘要门禁、SQL 回退、1536 维独立计费/幂等 |
| F11 收束 | 达字数但债务未清阻断；有限补章授权不清累计次数；PASS 才允许正文完结 |
| F12 阅读导出 | Livewire 正式阅读/任务页；鉴权下载；冻结清单/损坏文件/丢失恢复，无模型重写 |

## 限制与下一检查点

测试中的 55 字短章和固定 PASS 只验证程序合同，不能证明模型判断的真实性、语义审校质量、实际目标篇幅或百万字稳定性。R06 的三本真实作品、策略对照、100 章及百万字验证均未运行。

本轮未迁移运行数据库、修改 `.env`、重启实际 Worker、清理旧任务或删除旧业务实现。新链代码需要按 [运维手册](../OPERATIONS.md) 切换，不能认为当前运行服务已经使用最终代码。XNR-035 缺口关闭后才可关闭 XNR-026 并进入 R06。

## R05 续办最终回归（2026-10-18）

同一整合命令在续办修复后重跑：**280 通过、1 跳过、2346 断言**，见 [原始结果](assets/R05/followup-final-regression.json)。新增系统反馈测试，扩展摘要问题归因、编辑冲突显式刷新/保存、AI 修复停止原因清理的断言。Pint、前端构建和 diff 检查通过；前述旧测试基线未重跑，不扩大通过声明。

浏览器补验已将摘要、导出、有限补章、AI 修复与人工新稿动作对照到持久记录；具体修复、最终矩阵及仍缺项见 [续办记录](R05-followup-acceptance.md)。XNR-035 的 Safari、纯字体放大、减少动态效果和剩余状态/展开交互未全部通过，**XNR-026 依赖仍未关闭，R06 不满足入场条件**。

再次续办修复规划编辑器共享控制版本及冲突恢复丢失输入的问题。受影响的 `NovelGenerationPipelineTest` 完整复验 **25 passed / 381 assertions**，见 [结果](assets/R05/planning-conflict-regression.json)；不与上述整合结果相加。Pint/构建/diff 检查通过。本轮浏览器与桌面连接均不可用，新增规划控件尚未实际浏览器复验，不能关闭视觉依赖。详见 [续办记录第 7 节](R05-followup-acceptance.md#7-再次续办规划冲突修复与环境阻塞2026-10-18)。

## 2026-10-18 最终字体与预警修复后回归

同一整合命令最终 **293 passed / 1 skipped / 2467 assertions，276970 ms**，见 [结果](assets/R05/font-final-regression.json)。包含本轮健康时间戳与费用预警回归；SQLite 专用项跳过，PostgreSQL 并发项实际执行。Pest 报告 passed，Artisan 包装退出码仍为 1。42/460 的受影响文件组与整合组重叠，不相加。构建、Pint 与 diff 检查通过。

XNR-035 纯字体、Safari 小屏与核心提交键盘已补验，但剩余状态/完整交互证据尚未闭环，故 XNR-026 仍 IN_PROGRESS；不执行 R06。具体范围及环境清理见 R05 续办第 11 节。


## 最终 R05 关闭与 R06 入场

同一整合命令在最终 PHP/行为代码上重跑（之后仅追加弹窗 CSS，并完成构建与六组浏览器复验）：**293 passed / 1 skipped / 2472 assertions，259333 ms**，见 [原始结果](assets/R05/close-final-regression.json)。Pest result=passed，Artisan 包装退出码 1 的既有差异保留；不声称全仓库通过，旧 Canonical 35 项基线失败未在本轮修复或重跑。Pint、前端构建、diff 检查通过。

XNR-035 剩余三类状态/键盘/颜色验收已修复复验完成，详见 [续办第 12 节](R05-followup-acceptance.md#12-剩余三类验收闭环2026-10-18)，XNR-026 更新 DONE。具备启动 R06 的前置条件；实际环境准备、凭据及 Worker/Scheduler 核验仍需 XNR-027，R06 四项维持 TODO。本轮未执行任何 R06/R07 操作、运行库迁移、实际 Worker 重启或真实模型请求。
