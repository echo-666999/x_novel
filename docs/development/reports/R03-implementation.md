# R03 单段概述与整书入口实施记录

日期：2026-10-18。实施前提交：`94bea9f`（R02），工作区起始无未提交改动。范围：XNR-010、XNR-011、XNR-012；用户明确授权执行 R03，不自动执行 R04–R07。

R03 代码和固定响应业务检查已实施。XNR-034/009 的视觉依赖尚未闭环，因此 XNR-010–012 在任务总表保留 IN_PROGRESS，不将本报告解释为整个项目已可自动完成小说。真实 Provider、整章链、三本小说和长篇验收均未执行。

## 1 对应需求与实际流程

对应 PRD F01/F03/F09、§3、§7.1–7.4、§8.1，以及 DESIGN P02/P06。原入口要求标题/题材并跳转旧大纲工作台，新入口改为只需一段概述：

1. 概述必填，最多 10000 字；其余创作选项折叠。未明确篇幅时显示 10 万字默认值、每章 3000 字。默认整书自动；可以选开篇或逐章审核。费用默认统计和优化，预警与四种金额硬上限分别设置，硬上限默认关闭。
2. 同一提交 UUID 在事务中只创建一本 Novel、一份意图和一个主任务。重复内容重试返回原结果；同 UUID 不同内容拒绝。保存草稿暂停；开始任务记录持久预检断点。
3. 预检核对现有连接配置、模型路由和能力并冻结请求。不发真实探测请求，不将配置存在称为服务在线。缺配置在 Issue 展示，修正配置后“重新检查”可再次验证。
4. 基础设定使用一个结构化请求，补齐主角、世界、冲突、文风、承诺、结局等普通缺项。原始要求逐条保留，用户明确值与 AI 补充分别标注来源。篇幅保留显式表单、概述或系统默认来源。
5. 明确矛盾集中展示选择，普通缺项不强迫补充表单。选择后新增意图版本，原文保持不变。格式、引用或硬要求覆盖失败时保留 rejected 候选并进入统一异常中心。
6. “请 AI 修复设定字段”基于原文、失败候选和错误修复，新建 Run/Artifact；仍受次数、时限、无进展、来源、控制版本和已启用费用上限约束。人工可以修改概述/明确选项或集中选择冲突，保存处理说明本身不会关闭问题。临时服务故障使用同冻结合同重试。
7. 基础设定完成后，真实全书蓝图和近期目标尚未实现，任务明确暂停并显示等待 R04，保留断点。不会调用旧四段大纲绕过新合同。
8. 固定响应测试提供有效蓝图与首个目标窗口后，统一入口原子初始化 Bible、初始人物地点、规划指针和 State 0；进入章节规划未就绪断点。实际规划生成、写作与提交后推进由 R04/R05 接入。

## 2 实施文件与职责

| 范围 | 主要文件 | 结果 |
| --- | --- | --- |
| 输入与版本 | `NovelIntentContract`、`CreateNovelGenerationTaskAction`、`ReviseNovelIntentAction` | 原文、默认值、明确值、模式、费用、UUID 幂等及旧来源失效 |
| 设定合同 | `NovelFoundationContract`、`config/prompts.php` | `book-foundation-v1`；Schema/来源/要求覆盖校验、失败响应保留、候选修复 |
| 整书编排 | `NovelBookWorkflow`、`ControlNovelBook`、`ExecuteGenerationRunJob`、`GenerationRunCoordinator` | UI/CLI/恢复扫描共用断点、租约与控制版本 |
| 初始化 | `ApplyNovelBlueprintAction::initializeBook` | 一次原子采用三份候选、State 0 与不可变采用凭证 |
| 异常复验 | `GenerationIssueActions`、`GenerationIssueService`、`GenerationIssues` | 临时重试、候选字段修复、严格来源复验；诊断仍只读 |
| 界面 | NovelForm、Create/Edit/ViewNovel、NovelOverview、book-task Blade、theme | 概述首屏、折叠选项、目标/费用摘要、影响确认、候选来源及真实停点 |
| 旧链隔离 | Start/Advance/GenerateNextChapter、NovelOutlinePipeline、Readiness、CommitChapterJob、CanonicalCommitService | 新任务不回落旧大纲/章节入口；暂停与人工模式不被自动提交绕过 |

不新增依赖、队列、表或基础设施。没有恢复旧 AGENTS.md 业务架构，也没有执行 R07 大规模清理。保留旧小说路径仅供隔离回归和后续删除，不实现旧小说迁移。

## 3 数据、事务、重复执行和恢复

使用已有 novels、novel_generation_tasks、generation_runs/artifacts/issues、novel_outlines、novel_bibles、characters、world_entities、story_state_versions、usage_records。没有 Schema 修改；`NovelOutline.kind` 是已有字段的 Fillable 补充。因此本批不新增数据库备注迁移、不修改运行库元数据。新增/修改业务逻辑均补中文注释，解释来源、事务、幂等、暂停和采用边界。

- 创建：Novel/Task/Intent/Run 同事务；提交身份唯一，失败不留下半本小说。
- 执行：沿用 R01 的小说串行认领、租约、唯一逻辑步骤、输入 hash、发送前费用检查和 PostgreSQL 权威断点。重复 Job 不再次请求模型。
- 输入修改：锁 Novel→Task，CAS 校验 control_version，追加新意图；旧排队 Run 取消，在途旧结果隔离。历史费用与尝试不清零。来源被替换的 Issue 明确记录替换原因，不冒充原问题复验成功。
- 初始化：同事务锁定小说/任务/规划版本，检查同小说、当前意图、产物类型、校验状态、来源 checksum、规划链及空初始状态。所有正式初始化写入与 OperationRecord 一起提交；中途异常整体回滚。错误另外持久化为失败 Run/Issue，避免把已成功模型响应当作初始化复验成功。
- 暂停：阻止新阶段和正式提交；在途候选可保留。继续不重建小说；尚未接通阶段仍显示未就绪。已初始化后的概述修订暂停为 `future_revision_not_ready`，未来规划失效重算尚待后续批次。
- 模式：开篇计数只看正式章节与必需摘要；达到目标暂停，继续时转整书并留策略历史。逐章审核仅对当前正文、当前状态的 PASS 停在人工提交边界，旧自动 Commit Job 不提交。完整人工提交/摘要/下一章循环仍属 R05。
- 产物：不创建未来 Story Event、Fact、Memory、Volume 或 Arc。原始硬要求及选择进入 Bible，文风使用 `book-v1` 自然语言描述，旧必填 POV/时态列引用原文与文风来源，不硬编码第三人称/过去时覆盖用户要求，供 R04 Context 读取。

CLI：`php artisan novel:book <task-id> advance`，控制动作使用 `pause/resume/recheck --version=<当前 control_version>`。命令与页面调用同一服务；本轮没有对运行小说执行这些命令。

## 4 真实执行的验证

自动测试使用 `phpunit.pgsql.xml` 固定隔离库 `xnovel_refactor_test` 和 `xnovel-refactor-test:` Redis 前缀，默认禁止意外 HTTP。使用固定响应 Provider 并经过 Tracking/Usage 账本；未消耗真实模型费用。

主回归 **185 项通过、1 项跳过，1438 个断言**；跳过项是 PostgreSQL 下不适用的 SQLite 检查。最终审查补充概述篇幅数值边界并取消旧 POV/时态硬编码后，仅复跑受影响的 R03 两个测试文件：**30 项通过，190 个断言**。两组有重叠，不相加计算。结果见 [自动测试摘要](assets/R03/automated-tests.json)，覆盖以下命令：

```bash
vendor/bin/pest -c phpunit.pgsql.xml tests/Feature/Refactor tests/Feature/IsolatedTestEnvironmentTest.php tests/Feature/Filament/NovelResourceTest.php tests/Feature/Filament/NovelAiSettingsTest.php tests/Feature/Filament/BudgetUiTest.php tests/Feature/Filament/GenerationPageTest.php tests/Feature/Filament/NovelChapterDetailTest.php --compact
```

新增场景包括仅概述、输入上限、重复提交、篇幅冲突、自然语言篇幅证据、候选结构与来源、要求遗漏、集中选择、新意图隔离在途结果、无未来正式数据、初始化重复/回滚/暂停、初始化异常独立可见、开篇摘要边界、人工 PASS 来源、配置重检、旧入口拒绝、受限 AI 修复的重复执行/费用/复验及矛盾裁决禁止。Livewire 覆盖新建、草稿、错误保留输入、编辑影响、修复选项。

旧入口测试已按新业务边界调整：新建页断言新 Task/工作台，题材变为可选普通文本；旧 AI 模型覆盖改在旧小说编辑页测试；旧全链测试用显式旧小说夹具，保留原断言，不假称新建入口仍能执行旧链。

另外实际执行 CanonicalCommitServiceTest、AdvanceChapterPipelineActionTest、GenerateNextChapterActionTest、ChapterPipelineOrchestrationTest：**78 项，38 通过、27 错误、13 失败**。用 `git archive 94bea9f` 创建独立临时副本、复用依赖并校验 App 类加载到该副本后，在同一隔离配置复跑：结果及失败测试集合一致，见 [基线对照](assets/R03/legacy-regression.json)。最初临时 runner 的根路径/类映射不正确，已修正并重跑，错误配置的输出不作为基线证据。

已确认的旧失败类别：过期 Outline Completion 合同、字符串候选 ID 被按 bigint 查询、旧向量维度夹具、旧硬预算断言。它们仍未修复；不能据“R03 相关回归通过”宣称整个仓库测试通过。R04/R05 需要以新合同替换并验证相应场景。

`vendor/bin/pint --dirty`、变更 PHP 语法检查、`git diff --check` 和 `npm run build` 已执行。构建存在非阻断的可选 fontaine 提示，未为此添加依赖。未执行全仓库测试、真实 Provider、百万字或三本作品验收。

## 5 浏览器操作与视觉证据

使用临时隔离 Herd 站点、合成测试账号、测试库、Fake + Tracking Provider 和禁止意外 HTTP；测试后关闭标签并移除临时站点，不使用原有小说数据。

实际操作：仅概述创建→预检→设定候选→未就绪暂停；修改概述并确认影响→新版本→继续；异常中心预览重试→执行→成功复验。固定响应夹具第二次误用同 Provider request ID 时，真实账本唯一约束阻止了重复记账；修正测试夹具为每个 Run 独立 ID 后复验成功，原错误和未知费用历史保留。不是供应商真实故障，也不是 R06 小说验收。

深浅主题 320/390/768/1440 共 8 组视口均 scrollWidth=innerWidth，见 [测量](assets/R03/responsive.json)。390×844 下底部主按钮 bottom=828 可见；Tab 到折叠选项存在可见焦点；检查时控制台无 error/warn。模拟视口不等于真机，未宣称浏览器版本或第二引擎通过。最后新增的 AI 字段修复入口只有领域/Livewire 证据，尚无独立浏览器执行证据。

| 页面 | 证据 |
| --- | --- |
| 创建页深色桌面 | [截图](assets/R03/create-desktop.png) |
| 创建页浅色桌面 | [截图](assets/R03/create-light-desktop.png) |
| 深色 390 | [截图](assets/R03/create-dark-390.png) |
| 浅色 320 | [截图](assets/R03/create-light-320.png) |
| 设定工作台 | [桌面](assets/R03/workspace-desktop.png)、[移动视口](assets/R03/workspace-mobile.png) |

真实 200%/400% 缩放、系统减少动态效果与全页双引擎仍待验收。XNR-034/009 因此尚未关闭，R03 不越过依赖宣告 DONE；XNR-035 尚未启动。

## 6 后续交接与部署边界

R04 必须接入真实 blueprint/goal_window 生成及语义校验、当前章计划、Context、正文/提取/审校与局部修复；按架构文档的最小来源合同交接，不把测试蓝图或目标夹具加入生产。R05 再验证正式提交、人工模式恢复、摘要/续章、记忆、收束、导出及完整界面。语义忠实度不能仅凭 Schema 和原文覆盖检查宣称已验证，仍需真实模型验收。

本轮未改 `.env`，未迁移或清理运行库，未重启运行 Horizon/Worker，也未提交 Git。上线执行前需要按运维流程让长驻 Worker 加载新代码，并核对队列配置和真实健康状态；本报告不声明部署或真实生成成功。临时基线和浏览器隔离目录在验证后清理。
