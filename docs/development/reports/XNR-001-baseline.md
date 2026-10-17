# XNR-001 当前基线与旧故障证据

执行批次：R00。执行日期：2026-10-17，北京时间约 20:48 起。执行者：Codex。任务状态以 [任务总表](../REFACTOR_TASKS.md) 为准。

范围：PRD P01–P15 的只读核查、旧日志/失败队列脱敏采样、模块与配置边界盘点。未修复旧小说、修改应用代码、迁移数据库、重启服务、消费队列或调用真实模型。

## 1 基线和事实边界

| 项目 | 本次结果 |
| --- | --- |
| Git HEAD | `66d5c1b33ecb006e1a86ba11d6d8c8f64701166a`；开始时 `git status --short` 无输出 |
| 需求版本 | PRD 1.4、DESIGN 2.0、任务文档 1.3；原任务编制时 `3efe867` 是历史基线 |
| 当前实际依赖 | Boost application-info：PHP 8.4、Laravel 13.37.0、Filament 5.10.2、Livewire 4.4.7、Horizon 5.50.1、Pest 5.3.2 |
| 数据库 | 当前连接 PostgreSQL；Schema 工具返回 36 张逻辑表，实际迁移默认前缀 `x_` |
| 小说链数据 | novels、chapters、generation_runs、generation_artifacts、reviews、usage_records 各为 0 |
| 运行配置记录 | users 1；ai_provider_connections 2；ai_model_routes 11；ai_model_prices 6；仅查询数量，不查询账号或密钥值 |
| 遗留队列记录 | failed_jobs 47：generation 46、default 1；jobs 0。未检查 Redis 队列，因此不能据此宣布所有队列为空 |
| 当前金额配置 | generation 币种 USD，daily_hard_limit / novel_total_limit / chapter_max_cost 均 null；代码仍有独立内容修复金额限制 |
| 路由 | `php artisan route:list --except-vendor --json` 成功，30 条；实际存在创建、大纲、规划、章节、生成、审校、记忆、设置入口 |
| 开发指令 | 磁盘 AGENTS 为 Laravel Boost 指引；`.ai/rules` 不存在；未发现可应用的仓库 SKILL.md。用户会话提供的真实性/范围指令继续有效 |

当前空小说库与 PRD §2.2 的旧快照不同。旧 ID 仅用于诊断历史，不证明现有作品状态；没有导入、重建或续跑任何旧小说。查询不是同一事务快照，数量不能外推生产故障率。

本次 tools 数据库 schema/query 为只读。一次针对 information_schema 的查询被工具自动前缀化为不存在的 `information_schema.x_tables`，返回错误；之后使用 schema 工具及业务表 SELECT 完成核查，没有为绕过错误改变配置。

## 2 P01–P15 逐项核查

代码路径均相对仓库根，方法名用于后续定位；当前行为与目标差异是已授权改造范围，本批只记录，不提前修复。

| 问题 | 本次证据与结论 | 分类 / 后续任务 |
| --- | --- | --- |
| P01 入口不自动 | `NovelForm::configure` 标题/题材/字数 required，premise 无 required；`CreateNovel::mutateFormDataBeforeCreate` auto_generate/auto_commit=false，创建后重定向 outline | 当前静态行为确认；XNR-010/012 |
| P02 创建预算丢弃 | CreateNovel 第 43 行附近 unset budget_limits，构造 settings 无 budget；EditNovel 第 54 行附近转换并保存 settings.budget | 当前静态缺陷；未做浏览器复现。夹具 R00-009；XNR-006/010 |
| P03 初始全书细化 | `NovelOutlinePipeline::dispatchNextStage` 按全部 Arc/Beat 推进；`prepareBatch` 要求 1–12 卷；Finalize 后才采用 | 当前结构确认；XNR-013 |
| P04 篇幅不一致 | failed_jobs #29 保留“约 144 章，预算合计 67–94 章”；v6 常量和按 Arc 分配逻辑已存在 | 历史故障确认，不能推断 v6 同缺陷仍在；R00-001；XNR-013/014 |
| P05 容量反复失败 | failed_jobs #36：31115 输入 + 20000 最大输出 >48000；日志有 #512/#514/#516 成对规划请求/响应 | 历史超限确认；PRD 的 48921/48582/48070 与修复 Run 链已不能从现库重新核验，不能把日志 token 当这些容量估计值。R00-002；XNR-006/014 |
| P06 冗余/证据合同 | #457/#458 每次输入 arc_contributions=1、输出 arc_beat_audits=0；#616 的 12 条 outline_completion 字符串证据有 2 条非正文子串；failed_jobs #44 留有 Handoff 汇总不一致 | 可复核历史日志投影；#617 无完整成对日志，不能声称一并重验。R00-003/004/007；XNR-017/018/019 |
| P07 修复超时 | failed_jobs #41/#42 保留 1800 秒超时；`ContentSelfHealingService::start` 创建修复容器时立即设 started_at，`assertWithinLimits` 用 started_at 差值 | 精确口径是从修复容器开始计时，包含子任务等待；不是每次模型执行时长，也不直接使用 created_at。旧 #480/#500 等无完整链。R00-005；XNR-008 |
| P08 Deferred 与失败混合 | RunStatus 仍只有五种状态；GenerationFailurePolicy 识别两个 deferred 错误码；ChapterRepairRecommendation 已抑制部分被成功续跑取代的历史失败 | 当前语义差异确认；旧数据库的 32 条数量未重新核验。R00-008；XNR-005/007/009 |
| P09 恢复入口分散 | `RequestAiChapterRepairAction::preview/handle`、Generation/Review 页、手工计划编辑及重写 Action 分担不同修复 | 当前结构确认；不误报为完全没有修复能力。XNR-007/008/009 |
| P10 分类与兜底 | `GenerationFailurePolicy::category/recommendedAction` 使用错误码子串与通用兜底；异常字段映射未形成跨尝试 Issue | 当前静态证据；旧 category 空值因 Run 已删无法重查。XNR-007 |
| P11 整书持有者缺失 | 存在大纲 Batch、GenerationStageGraph、AdvanceChapterPipelineAction、NovelLifecycleAdvancer、CanonicalPostCommitDispatcher；Schema 无 novel_generation_tasks/generation_issues | 不是局部推进器缺失，而是无统一独立整书目标/问题身份；XNR-004/005/012 |
| P12 版本漂移 | failed_jobs #32/#33 保留 Prompt 与冻结 Run 不一致；NovelOutlinePipeline 同时含 v2–v6 合同常量 | 历史故障与当前兼容负担确认，无法确定引发旧失败的具体部署。R00-006；XNR-006/027/032 |
| P13 提前复用 | `prepareBatch` 在完整 hash 之前按合同版本+卷数返回 sameBatch，后面才构造 premise 等输入 hash | 当前静态风险；未交互复现。R00-010；XNR-013 |
| P14 文档依赖悬空 | 两个 DocumentationTest 读取不存在的 generation-pipeline.md/data-model.md；SystemHealthService 检查旧 MVP_RELEASE_CHECKLIST.md | 已实际运行两个测试均失败，不能只报告“可能失败”；XNR-025/026/032 迁移有效风险覆盖 |
| P15 测试环境偏差 | phpunit.xml 为 SQLite :memory:、sync queue；部分测试使用 PostgreSQL 特性条件分支 | 配置确认；本次没有 PG 并发/Queue 故障验证。XNR-003/026 |

补充费用风险：`BudgetGuardAiProvider` finally 调用 release；`BudgetService` 缺 Run 时没有持久预留；`AppServiceProvider` 的 Embedding 包装链没有 BudgetGuard；`AiCostCalculator` 缺价格返回 0.0；修复 limits 要求 max_cost>0。这些均与默认完成小说、未知费用和用户自选硬上限的目标不同，由 XNR-006 统一改造，不在 R00 修补。

## 3 请求、响应与故障来源

原始日志保留在 storage/logs，本次只读取。按日志记录边界解析完整请求/响应，不能按单行截断后判断模型缺字段。日志含字符串内换行，解析允许该格式；部分历史文件有非 UTF-8 字节，关键 #457/#458/#579/#616 记录额外检查无替换字符。

| 历史 Run | 配对 request log ID | 本地证据位置 | 本次可确认的范围 |
| --- | --- | --- | --- |
| 457 | `57a6af2e-3789-482f-bbe8-a18b83f9ebfc` | laravel-2026-10-15.log:8476 / 8481 | 输入 1 项，审计返回 0 项，响应记录 HTTP 200 |
| 458 | `cc310943-7488-428a-b44a-7c6b47904dd4` | 同文件:9076 / 9081 | 同样的审计项缺失 |
| 512 | `3891c2f0-6317-4634-8101-7b39ab6f1240` | laravel-2026-10-16.log:7 / 14 | 完整规划请求/响应存在；不是 48921 容量估计的证明 |
| 514 | `c91dd4de-f14a-4211-84ac-92492afc559e` | 同文件:15 / 22 | 同上 |
| 516 | `017e5875-17bb-4186-84bd-61db74e54711` | 同文件:23 / 30 | 同上 |
| 579 | `2aabcbc8-2a71-444f-ab5b-c775d48138f5` | 同文件:909 / 914 | 成对审校日志存在；汇总错误以 failed_jobs #44 为独立证据，不伪造复现全部旧 Validator 结果 |
| 616 | `7e701ffc-8ab3-4cf1-920e-aa4a8e1ebdee` | 同文件:2014 / 2015 | milestone_completion[2] 与 handoff_readiness[6] 的字符串证据不在 chapter_draft 中 |

Artifact/Review/Usage 已不存在，无法重新串起每个旧故障的全部数据库链；不恢复旧库、不伪造缺失字段。failed_jobs 的同类错误与请求日志未经恢复的直接 FK 关联明确不作为确认事实。

离线交付：[夹具与使用说明](../../../tests/Fixtures/refactor_r00_README.md)，17 个场景，其中 6 个历史观测投影、11 个人工构造目标合同场景。历史来源含 12 条必要 failed_jobs 投影、7 对日志元数据及逐字匹配结果；没有原始序列化 Job、完整小说正文、账户数据、凭据或完整私有端点。

## 4 模块盘点及保留/重构/删除候选

“保留”指业务能力或可复用实现，不表示代码无需修改；“删除候选”必须在 R07 完成动态引用和回归核查后删除。本批未删除任何代码。

| 模块 / PRD 功能 | 已有入口 | 新职责与候选处置 | 实施任务 |
| --- | --- | --- | --- |
| 创建与意图 F01 | NovelForm/CreateNovel/EditNovel | 重构单概述、模式、版本化意图；移除旧创建默认布尔权威 | 010/011/012 |
| 预检 F02 | AiSettingsResolver、AiModelRouteService、GenerationRequestBudget、GenerationOutputCapacityGuard | 复用单一路由，统一全链能力/费用；减少重复预算与旧四阶段耦合 | 006/014/025 |
| 初始设定 F03 | NovelOutlinePipeline Foundation、ApplyNovelBlueprintAction、StartNovelGenerationAction | 重构为基础包产物与原子初始化；移除旧层级必填门槛 | 011/012 |
| 三层规划 F04 | NovelOutlinePipeline、NormalizedNovelOutlineValidator、OutlineProgressResolver、NovelOutlineChecksum | 重构蓝图+窗口；保留规范化 hash 原则；旧 v2–v6 分支和 Beat/Arc 专用节点是删除候选 | 013/031/032 |
| 本章与上下文 F05 | ChapterPlanner、PlanAdmissionService、ContextBuilder、ReviewPromptCapacityEstimator | 复用冻结/容量原则；改为当前章最小合同，状态与硬事实不能裁剪 | 014 |
| 写作 F06 | SceneGenerator、ChapterAssembler、GenerateSceneJob | 重构整章优先+必要片段；去强制 Scene 表与覆盖审计链，保留截断恢复 | 015/016 |
| 提取/审校 F07 | StoryEventExtractor、StatePatchBuilder、StateValidator、ChapterReviewer | 保留独立判断与确定性 State Patch；移除模型重复汇总与 Writer 自证权威 | 017/018 |
| 修复 F08 | ContentSelfHealingService、GenerationFailurePolicy、ChapterRepairRecommendation、修复 Actions | 合并策略与问题计数，保留小修复器，移除兜底吞错/隐性金额上限 | 007/008/009/019 |
| 正式提交/续章 F09 | CanonicalCommitService、CanonicalPostCommitDispatcher、ContinueAutoGenerationJob | 保留事务正式边界与摘要续章分离，加入任务身份/持久推进和当前来源校验 | 020/021 |
| 记忆 F10 | MemoryUpdater/Retriever/Ranker/Invalidator/Embedder | 保留来源与召回，Embedding 补统一费用和恢复合同；向量非事实权威 | 022 |
| 完结 F11 | EndingAuditService、NovelLifecycleAdvancer、ForeshadowingLifecycleResolver | 保留闭合审计，去强制旧 Arc 层级，按蓝图承诺计算未闭合项 | 023 |
| 阅读导出 F12 | ViewNovelChapter、只读正文/Diff Blade、小说 Pages | 重做中文阅读、统一工作台；实现冻结版本清单 TXT 导出；旧导航候选合并 | 024/034/035 |
| 数据与调度 | Models、Migrations、GenerationRunLease、GenerationJobDispatcher、AutomaticGenerationRecoveryService | Task/Run/Issue 分离，规范 CAS、租约、待派发意图；不新建工作流框架 | 004/005 |
| 运维/测试 | SystemHealthService、routes/console.php、Horizon、tests | 核实实际服务与隔离环境，替换失效文档检查，保留业务风险测试 | 003/025/026/027/032 |

## 5 配置、账号与清理边界

1. users/连接/路由/价格/system_settings 属于运行配置，不能随旧小说清理。连接当前 `api_key` 使用 encrypted cast 和 hidden；本批没有读取、解密或复制其值。
2. 实际全局路由以数据库 ai_model_routes 为准，小说覆盖经 AiSettingsResolver；不是从 `.env` 推断模型。DB/Redis/Worker/日志策略仍由部署配置负责。
3. 现有 AIProviderConnection 加密入库与用户安全约束/PRD §2.5 待确认项冲突，XNR-002 单列决策；R00 不迁移凭据。新模型设置、capability 和价格各有可解释来源。
4. failed_jobs 仍有 47 条旧任务；jobs=0 不等于 Redis 为空，也不等于旧 Worker 已退出。R06 切换须重新核查，无授权执行旧失败任务的推论。
5. 所有旧兼容分支、测试或菜单仅列候选；R07 才依据完成后的代码和动态调用证据决定删除，不建立 legacy 副本。

## 6 实际验证及完成记录

- schema/query 只读核查、路由枚举、代码定位和成对日志解析已执行。
- 执行 `vendor/bin/pest tests/Unit/OutlineConfigurationDocumentationTest.php tests/Unit/ChapterCapacityDocumentationTest.php tests/Unit/ReviewPromptCapacityEstimatorTest.php --compact`：3 项，1 通过、2 失败、39 assertions、4 warnings。通过的是完整请求容量估计用例；失败是两个旧文档依赖用例。未修改测试以掩盖失败。
- 未运行全套应用测试、PostgreSQL 并发/故障注入、浏览器、构建或真实 Provider；R00 完成不代表这些通过。
- 交付验证包括 JSON 可解析、17 个唯一场景、来源类型、7 对请求关联、已知数值与证据匹配、凭据/绝对路径扫描和文档链接检查。最终执行结果见 R00 批次记录。
- 代码中文注释：本批不修改应用/测试 PHP；新增 JSON 用中文说明。数据库中文备注：不涉及结构或数据修改。
- Canonical/Story Event/事务：无业务写入；没有改变任何正式指针。
- 恢复：撤回本批新增文档/夹具及任务状态即可，不需要数据库回滚。
- 下一项：XNR-002 基于本报告确定合同，完成后仅将 XNR-003 标为 READY，不自动执行 R01。
