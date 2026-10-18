# R04 统一生成合同实施记录

日期：2026-10-18。执行者：Codex。实施前提交：`9e197de`（R03），初始工作区干净。授权范围：XNR-013–019；不自动执行 R05–R07。

本批代码与隔离回归已实施。XNR-034/009 的真实缩放、减少动态效果等依赖尚未闭环，因此 R04 在任务总表保留 IN_PROGRESS。正式提交、提交后摘要、自动续章、记忆、完结和导出仍属 R05；本报告不能作为完整小说生成或真实模型质量验收的结论。

## 1 实际生成流程

```text
概述 → 基础设定（R03）
    → 全书蓝图 → 首个近期目标窗口 → 原子初始化
    → 当前章计划 → Writer/Extractor/Reviewer/Rewrite 容量准入
    → 整章写作，必要时顺序片段 / 有限截断续写
    → 完整草稿 → 独立事件提取 → 确定性状态校验
    → 独立叙事审校
         PASS → 暂停等待 R05 正式提交
         可修复异常 → 产物修复 / 局部改写 → 新稿重新提取与审校
         无法自动处理 → 异常中心 → 明确建议 / 人工保存新稿并复验
```

### XNR-013：全书蓝图与近期目标

- `NovelPlanningService` 生成独立版本蓝图和默认 5 章窗口；剩余目标小于窗口容量时缩小数量。蓝图保存主线、结局、转折、承诺、人物发展、伏笔和可选分卷，不强制创建 Volume/Arc/Beat。
- 代码计算转折篇幅分配、标准承诺/章节目标 ID、目标内容 hash；窗口不足时保留未执行目标、仅补缺少槽位。远期未细化不阻止开篇。
- 未来目标修订创建候选，连续编辑累积到同一待采用版本链；当前稿继续使用原目标。未来主线修订同样等待章节边界。修改当前目标须明确选择重建，旧计划/旧稿保留，尚未发送的旧请求取消。
- 边界采用检查控制版本、同小说来源、正式状态和活动章节。需要重新绑定正式状态时创建新来源版本，绝不覆盖旧产物。真实跨正式章节的滚动推进需在 R05 接入 Commit 后集成验证。

### XNR-014：当前章计划与容量准入

- `NovelChapterPipeline` 保存具体章节标题、本章作用、人物变化、POV、时间、语气、必须/禁止内容、章末落点及可选片段边界。
- `ContextBuilder::forBook` 读取原始硬要求、结局、文风、当前正式状态、实体、锁定事实、近期正式摘要及有限可选记忆。新 Context Artifact 和 Run 快照可追溯实际输入；长期向量检索的完整降级机制仍由 R05 适配。
- 四条下游路由均以真实 Prompt/Schema、正文预估和输出余量检查容量；只裁剪可选记忆，不删除硬事实。Writer 容量决定整章/片段策略，Extractor/Reviewer 不足在 Writer 之前拦截。
- 实际正文/事件让审校输入超预算时保留成功正文，允许基于当前已授权路由重建 Admission；不重跑 Planner/Writer。路由进入 Admission 后冻结，后续全局设置不能静默换模型。

### XNR-015/016：整章与必要拆分

- `NovelStageContract` 为每个模型阶段冻结 Schema、Prompt 版本、来源和请求容量；一次昂贵 Run 只发送一次 Provider 请求。正常阶段用 succeeded，重复 Job 不再调用模型。
- 容量允许时一次写整章，不要求 Scene 记录。服务端生成 Unicode 段落 ID、偏移与 hash；保存完整原始响应、完成原因和验证后的产物，原始审计响应不能被当作有效正文。
- 必要拆分按顺序保存前驱 ID/hash、前文尾部和本章临时状态；只生成缺段。可解析截断正文只保留完整句边界并有限续写；无法可靠解析的响应保留原文并进入异常，不猜测缺失结构。
- `ChapterDraftService` 验证片段连续性、来源与重复边界；不静默删除重复内容。最终完整稿绑定全部片段，临时状态不写入正式事实。

### XNR-017/018：独立提取和审校

- 从同一完整草稿独立提取事件，校验实体归属、当前来源及精确段落/字符偏移。重复句子由显式位置区分；引文命中不被当作事件语义已经成立。
- 复用 `StatePatchBuilder`、`StateValidator`，增加显式 Candidate/Patch/Draft 参数，避免不同 Run 的“最新产物”混用；锁定事实和确定性规则先行。仅创建候选，不写正式 Story Event。
- Reviewer 看到同一正文、计划、事件候选和规则结果，独立检查叙事与事件语义；代码检查目标覆盖和证据，再将 hard / important / uncertain / suggestion 映射为 BLOCK / REWRITE / NEEDS_ATTENTION / PASS。
- 新 Review 分数为空，不伪造分数；一般风格建议不强制改写。Review 来源清单和规则版本可查，新稿不能继承旧 PASS。

### XNR-019：修复与人工新稿

- `BookChapterRepair` 将结构/证据错误限定在失败产物；正文问题只修改有证据定位的段落，核对原 hash，其他段落保持原样。无有效差异立即停止；自动改写受章级次数和原问题次数/时限约束。
- 新稿重新提取、建补丁、独立审校，PASS 后才关闭原问题。首次失败、改写及后续提取/审校的费用均归入原 Issue；复验 Run 不刷新修复额度。金额硬上限仍默认关闭，仅用户主动启用时检查。
- `ReviseBookChapterDraftAction` 按 Novel→Task→Chapter 锁定，以 control_version、当前稿 ID/hash 和操作 UUID 保障 CAS/幂等。保存失败保留用户输入；保存成功后复验发生容量异常，已保存文字仍持久存在。
- 人工保存明确创建新稿并登记复验，不批准正式内容。在途旧 AI 响应被隔离为历史，不能覆盖新稿或阻止其正常推进。容量重建遵守暂停与同书串行边界。

## 2 文件、数据及事务边界

| 范围 | 主要实现 |
| --- | --- |
| 冻结模型合同 | `NovelStageContract`、`config/generation.php` |
| 三层规划 | `NovelPlanningService`、`NovelChapterPipeline`、`ChapterPlan` |
| 正文与修复 | `ChapterDraftService`、`BookChapterRepair`、`ReviseBookChapterDraftAction` |
| 编排与恢复 | `NovelBookWorkflow`、`ExecuteGenerationRunJob`、`GenerationRunCoordinator`、`GenerationStageGraph` |
| 独立校验 | `ContextBuilder`、`StatePatchBuilder`、`StateValidator`、`Review` |
| 统一异常 | `GenerationIssueActions`、`GenerationIssueService`、`GenerationIssueCatalog` |
| 界面 | `ViewNovelPlanning`、`ViewBookChapter`、对应 Blade、异常中心、章节列表和工作台链接 |

使用现有 Novel/Task、Outline、Chapter/Plan、Run/Artifact、Review、Issue、Usage，以及初始化所需 Bible/实体/State 0。没有新增表、依赖、队列或基础设施；没有创建正式章节事件、Facts 或正式 Memory。旧业务链留待 R07 清理，新小说的旧章节详情入口返回 404，不能从旧页面调用场景操作。

两项数据库迁移仅在隔离测试库执行：

1. `2026_10_18_115324_allow_unscored_narrative_reviews.php`：Review 八个评分字段允许空，更新中文字段/表备注；已有无评分记录时拒绝有损回滚。
2. `2026_10_18_115535_allow_three_layer_chapter_plan_sources.php`：旧四层计划外键允许空；PostgreSQL CHECK 要求 book-plan-v1 具有 Novel/蓝图/窗口/状态及非空目标引用，更新中文备注。存在新计划时拒绝恢复旧非空链。

模型请求在锁外运行；沿用 R01 租约与持久断点。阶段采用、指针更新与下一步登记共用整书控制事务，失败回滚采用并另存失败 Run/Issue；成功响应可以复用。手工修订只追加版本，历史 Artifact 不覆盖。暂停允许在途响应保存，但不继续下个收费阶段。服务端来源验证包括意图、正式状态、当前计划、目标 hash、硬约束 hash 和当前稿指针。

新增及改动的关键业务规则有中文注释。隔离 PostgreSQL 自动测试实际读取本批迁移的表/字段中文备注，并验证有损回滚被拒绝；不以 Migration 源码中的注释代替数据库元数据。

## 3 实际验证结果

环境：`phpunit.pgsql.xml`，隔离 PostgreSQL `xnovel_refactor_test`、测试 Redis 前缀、FakeAiProvider + Tracking/Usage，禁止意外 HTTP。未发送真实模型请求。

主回归：**233 通过、1 跳过，1869 断言**。跳过项为 PostgreSQL 下不适用的 SQLite 专用检查。命令：

```bash
php artisan test --configuration=phpunit.pgsql.xml tests/Feature/Refactor tests/Feature/IsolatedTestEnvironmentTest.php tests/Feature/GenerationStageContractTest.php tests/Feature/StateValidatorTest.php tests/Feature/StatePatchBuilderTest.php tests/Feature/Filament/NovelResourceTest.php tests/Feature/Filament/NovelAiSettingsTest.php tests/Feature/Filament/BudgetUiTest.php tests/Feature/Filament/GenerationPageTest.php tests/Feature/Filament/NovelChapterDetailTest.php
```

收尾增加原始响应审计、修复链费用/时限归属、暂停保护、多次未来目标编辑及 UI 保存回归后，复跑受影响文件：**63 通过，445 断言**：

```bash
php artisan test --configuration=phpunit.pgsql.xml tests/Feature/Refactor/NovelGenerationPipelineTest.php tests/Feature/Refactor/GenerationIssueTest.php tests/Feature/Refactor/GenerationIssueActionsTest.php
```

两组有重叠，不相加计算。新增流水线测试文件共 23 个场景，包含无 Scene 整链、重复 Job、结构修复保正文、局部改写及重新审校、原问题费用归属、人工 CAS、精确证据定位、硬冲突、风格建议、截断续写、在途暂停、前置/实际容量不足、未来修订、必要片段、晚到响应、硬约束失效、数据库备注、无进展停止、阶段枚举与 Livewire 新稿立即刷新。

收尾第一次复跑出现 1 个测试断言失败：计数包含首次失败 Review，测试却只预计三次修复/复验；核对原有账本规则后改为修复链 Run=3、问题全部 Usage=4，最终 63 项通过。该修正没有删除失败费用。Pest JSON 为 passed 时本机 Artisan 包装仍返回退出码 1，报告保留原始结果，未据退出码宣称命令返回 0。

证据：[自动测试摘要](assets/R04/automated-tests.json)、[静态检查摘要](assets/R04/static-checks.json)。`vendor/bin/pint --dirty --format agent`、30 个变更 PHP 文件语法检查、`git diff --check`、`npm run build` 已执行；构建保留已有可选 fontaine 非阻断提示，没有安装额外依赖。

没有运行全仓库测试。R03 报告记录的旧提交/章节流水线失败基线，本轮未声称已修复；R05 需按新合同验证 Commit、回滚和完整推进。固定短文本验证合同与恢复机制，不能证明真实模型小说质量、篇幅完成率或长篇稳定性。

## 4 浏览器及视觉证据

临时隔离 Herd 站点使用测试库、合成账号、固定 Provider 与禁止意外 HTTP；验证后关闭标签、移除站点和临时目录。运行小说未参与验证。

实际操作：保存未来主线候选，当前蓝图保持不变；人工保存新稿，重新提取与审校登记；展开旧稿/新稿对照。发现 Livewire Schema 复用旧 View 数据导致保存后显示旧正文/旧 PASS，已改为延迟读取当前稿并复验：正文立即更新，状态显示等待当前稿独立审校。

| 页面与状态 | 证据 |
| --- | --- |
| 深色规划桌面 | [截图](assets/R04/planning-dark.png) |
| 浅色规划 320/390/768/1440 | [320](assets/R04/planning-light-320.png)、[390](assets/R04/planning-light-390.png)、[768](assets/R04/planning-light-768.png)、[1440](assets/R04/planning-light-1440.png) |
| 深色阅读保存新稿和版本对照 | [桌面](assets/R04/reader-saved-dark.png)、[390](assets/R04/reader-dark-390.png) |
| 浅色阅读 320 | [截图](assets/R04/reader-light-320.png) |

以上测量视口的 scrollWidth 等于 innerWidth，检查时未见控制台 error/warn；[测量与操作摘要](assets/R04/responsive.json)。这不是实际浏览器缩放或真机检查。真实 200%/400% 缩放、OS 减少动态效果、第二引擎、全部异常视觉组合尚未验收，完整阅读偏好/导出及全站一致性留在 R05/XNR-035。

## 5 当前断点与后续交接

- 自动模式 PASS 后是 `commit_not_ready`，逐章审核是 `awaiting_manual_commit`。R05/XNR-020 从当前 Plan/Draft/Event/Patch/Review 的来源链适配统一 CanonicalCommitService，不能只看 PASS 字样提交。
- R05 接入正式提交后，验证窗口跨章节采用、摘要/续章、开篇/人工模式、记忆、收束与导出，并补全视觉依赖；三本真实小说和长篇评测属于 R06。
- 本轮未迁移运行数据库，未改 `.env`，未修改原有小说，未重启运行 Horizon/Worker，未提交 Git。上线切换仍需独立执行迁移、让 Worker 加载新代码并核实状态；本报告不声称已部署。
- 不删除已产生的新链历史和费用。新增 Schema 已存数据时，回滚代码不能顺便回滚为不支持新数据的旧结构；按迁移防护和后续切换手册处理。
