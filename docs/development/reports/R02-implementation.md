# R02 统一异常处理实施记录

日期：2026-10-17。执行者：Codex。开始提交：`ca5b57e`，开始时工作区干净。

范围：XNR-007、XNR-008、XNR-009；PRD §6、§7.3、§8.4 和 DESIGN P06。用户本轮明确授权执行 R02。XNR-006 已完成；XNR-034 的代码可用，但真实缩放、减少动态效果仍未验收，因此 XNR-009 保留 IN_PROGRESS，不宣告 R01/R02 全批关闭，也不进入 R03。

## 实施结果

### XNR-007：统一问题分类与生命周期

- `GenerationIssueCatalog` 使用稳定错误码、HTTP 状态和结构化元数据区分 PRD 全部异常类别。正文质量、评审证据/审计产物、Schema、容量、凭据、基础设施、来源、结局、摘要、派生数据、供应商配额、费用提醒与用户硬上限各有中文解释和处理建议。未知错误保留人工路径；不凭报错中文猜业务含义。
- `GenerationIssueService` 复用 R01 的 `generation_issues`。指纹包含小说、任务、章、阶段/目标、输入、来源/合同哈希、类别和规则；同一失败重复记录不会重复建卡或覆盖首次证据。
- 新链失败先写 Run，再在提交后观察并建 Issue。观察失败报告异常，保留原 Run；恢复扫描可补建。旧历史不导入。处理失败仍关联原问题，不递归修复修复器。
- 解决需要**同阶段、同输入、同来源**的 succeeded Run 和 validated Artifact，并复查当前来源及领域复验。当前只接通 Preflight 身份/来源检查复验器；未来 Review 请求成功但仍为 REWRITE 时不能关闭内容问题，领域接入必须补对应验证规则。记录解决 Run、Artifact、checksum 和原来源；不同来源的“后来成功”不能关闭问题。旧 failed Run 保留。
- 费用预警、默认未知费用和非关键派生失败为非阻塞提醒；没有把金额未知标为免费或强制暂停。摘要缺失仍是必需上下文的阻塞项。费用观察触发点随相应创作/统计阶段后续接入，本批实现分类与持久处理合同。

### XNR-008：共享动作入口和受限诊断

`GenerationIssueActions::preview/execute` 是页面和自动恢复的共享入口，实际开放：

| 动作 | 当前实际能力 | 关闭问题的条件 |
| --- | --- | --- |
| 从原断点重试 | 已接通的 Preflight 临时故障，保持冻结合同，新建 Run；恢复扫描复用该入口 | 实际执行和同源产物复验通过 |
| AI 分析原因 | 复用 reviewer 路由、任务已授权模型、冻结 Prompt/能力/价格、现有 Provider/Usage；保存只读 `repair_proposal` | **不关闭**，建议不是修复成功 |
| 保存人工处理说明 | 新建 Operation Run/Artifact、记录操作者和来源，刷新可查 | **不关闭**，人工备注不是复验 |
| 暂停整书任务 | 调用原任务控制 CAS，保留问题和在途候选 | **不关闭**，暂停不是解决 |

正文、证据、计划、派生数据等具体修复器仍由 XNR-019 等后续领域任务接入。模型可以提出目标白名单中的建议，但尚未实现的动作没有执行按钮，未知动作一律拒绝。模型输出不用于执行 SQL、命令、URL、类名或修改 Canonical。

问题默认最多 2 次处理尝试、累计实际执行 1800 秒，总截止时间默认不设，首次记录时冻结。排队/人工等待不计执行时间，崩溃按开始时间和执行租约保守封顶计入；每 Run 只结算一次累计时间。相同来源/要求/策略再次诊断直接拒绝；更换操作 ID 或 Run 不会重置次数和费用。排队后也检查时限。

金额仅由已启用的全局/小说/章节/Issue 上限控制，继续使用 `RequestUsageLedger` 的精确金额和发送前复核。原失败请求的 Usage 回连原 Issue；诊断保留章节身份，不绕过章上限；默认价格缺失仍可生成只读建议并显示待确认。任务没有授权诊断路由时明确禁用 AI，人工入口继续可用。R01 创建的空模型授权不会被本批自动扩大；R03 创建/预检需要按现有合同冻结实际授权范围。

人工与 AI 共用当前来源验证、Task control_version 和操作幂等。人工新稿改变当前指针后，旧 AI 响应保存为 rejected 候选，不覆盖新稿；发起处理不直接推进正式状态。显式调整硬上限仍走现有设置，修复器没有改上限或硬事实的动作。

### XNR-009：持久异常中心

- 新增 `GenerationIssues` Filament Page；一级“异常与审校”进入统一问题列表，旧审校记录保留入口。Generation、Review、小说顶部问题卡和章节详情均链接同一 Issue，不维护页面工作流。
- 筛选待处理、自动处理中与提醒、全部未解决、已解决历史；支持小说/章节上下文筛选。
- 详情展示原因、影响、保留内容、首次证据、累计次数/执行时间/截止时间、人工记录、AI 建议、已知金额/未知笔数/在途占用。普通文案使用中文动作含义，高级详情保留稳定码和来源。
- 最多三个优先选项，额外动作折叠。AI 不可用说明具体原因；常规操作有影响预览，不额外叠加确认。提交失败保留输入，过期预览明确拒绝；提供脱敏 JSON 诊断包下载。
- 不把未接通的正文/计划编辑显示为可用；不要求用户通过编辑原始 JSON 修复业务。当前可执行人工路径是具体配置定位、原断点重试、处理说明和暂停；领域编辑器与证据采用在其责任批次接入。

## 数据、事务、重复与中断

没有新增 Migration、表或列，没有修改运行数据库结构。因此本批不涉及新增数据库中文备注；复用 R01 已落库的中文备注和约束。实际写入范围是隔离库中的 Task/Issue/Run/Artifact/Usage，未生成 Story Event、正式 Story State 或 Canonical Chapter。

锁顺序沿用 Novel → Task → Issue/Run；费用发送沿用全局设置 → Novel → Task → Issue 的账本锁。所有模型网络请求均在短事务外。唯一约束作为去重的最终保护。诊断 Job 复用 `ExecuteGenerationRunJob` 的认领与租约；重复投递不产生第二次请求。Run 的失败记录与 Issue 的补建分离，避免问题表暂时失败吞掉原异常。

补正一个 R01 暴露的缺陷：Chapter 的 `current_plan_id/current_draft_artifact_id/summary_artifact_id` 原未加入 Fillable，批量保存会静默忽略；现已补齐，并通过来源指针竞态测试验证。新增/修改代码均有中文职责、边界或原因注释。

## 实际验证

最终扩大回归命令：

```sh
vendor/bin/pest -c phpunit.pgsql.xml tests/Feature/Refactor tests/Feature/IsolatedTestEnvironmentTest.php tests/Feature/Filament/GenerationPageTest.php tests/Feature/Filament/NovelChapterDetailTest.php tests/Feature/Filament/BudgetUiTest.php --compact
```

**133 项：132 passed / 1 skipped / 943 assertions，约 62 秒。** 跳过项为 PostgreSQL 下不适用的 SQLite 专属维度检查，不计为通过。随后加强 Review 复验用例，使候选与原失败的 input_hash 明确相同，单独执行 `GenerationIssueTest.php`：**29 passed / 71 assertions**。未跑整个旧项目测试库，不声明全量通过。

覆盖真实双 PostgreSQL 会话的同问题记录争用、提交后回调/缺 Issue 对账、同源复验和错误来源拒绝、重复操作/投递、跨尝试累计次数与实际时间、截止时间、无进展诊断、非法模型动作、原错误保留、Issue/章节主动硬上限、未知费用、AI 未授权、人工新稿与晚到结果、登录权限、页面输入保留、已解决历史、下载和脱敏。扩大回归中的首次失败为两处新增测试夹具/文字断言问题，已修正并重跑；没有删测试或放宽业务门禁。

`vendor/bin/pint --dirty --format agent`、`git diff --check`、PHP 语法检查及 `npm run build` 均成功。保留原有可选 fontaine 构建提示，没有新增依赖。

此前阶段回归：R01+R02 Refactor 73 passed / 367 assertions。全部使用隔离 PostgreSQL `xnovel_refactor_test`、项目测试隔离前缀；Provider 使用 Fake 或测试 HTTP，不发生真实收费调用。

浏览器使用临时 Herd 站点 `xnovel-r02-browser.test`：入口强制 testing 和固定隔离库、独立 Session，禁止未模拟 HTTP，并绑定 Tracking + Fake Provider。仅有一本文字标注的合成小说；不是 R06 三本真实小说验收。验收后关闭标签并移除 Herd link，后续测试重建隔离库清除该夹具。项目 `.env`、现有小说、真实 Provider、运行 Worker 均未修改。

实际浏览器操作：登录夹具账号 → 问题列表/详情 → 保存人工说明 → 刷新核对持久记录 → 查看 AI 影响预览 → 假模型诊断 → 建议保存但原问题保留 → 重复同输入诊断禁用并显示原因 → 人工说明预览后模拟另一操作暂停 → 旧预览提交被拒且原输入完整保留。

浏览器：Chrome（当前会话）；桌面原视口 1709px，响应式深/浅主题各检查 1440×900、768×1024、390×844、320×800，8 组均 `scrollWidth == innerWidth`，无横向溢出。键盘 Tab 从输入移至可用按钮，焦点为 2px solid。捕获的 console error/warn 为空。时间显示已改为应用配置时区，未知金额不以零合计替代。

截图和宽度测量：[assets/R02](assets/R02)。包括桌面、浅色、320px 深浅、手机和版本冲突输入保留。截图仅为隔离夹具，不包含已有小说或真实凭据。

未测：真实 Provider、完整小说生成、所有新领域编辑器、真实浏览器 200%/400% 缩放、系统减少动态效果、跨浏览器。快捷键没有产生可确认缩放变化，未以手机视口冒充缩放验收。XNR-034 不据此标 DONE。

## 恢复与后续

回退本批代码不删除 Issue/Usage/Artifact 历史。若需关闭新入口，保留持久问题和原 Run，通过同一应用服务或修复后的对账恢复；不要清空次数和费用。未执行 R07 删除，也未提交 Git。

XNR-007、008 的服务与测试可验收；XNR-009 的实现和当前浏览器操作已验证，但依赖 XNR-034 仍未完成，状态保持 IN_PROGRESS。下一步补齐该视觉依赖及整体验收后再关闭 R02；不自动执行 R03/R06。
