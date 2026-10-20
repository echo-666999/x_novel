# XNR-027 真实验收环境准备与核验

日期：2026-10-19（Asia/Shanghai）。执行者：Codex。状态：**DONE**。范围仅 XNR-027；XNR-028–030 未执行，未创建三本真实小说或异常专项小说。

## 基线与授权

实施前已读取任务卡、OPERATIONS、R05 续办第 12 节及方案 A。代码基线为 `b0c21eb`，开始时工作区干净，前轮 R05 改动已由现有提交保留；本轮不重置、不提交代码。实际 CLI/新 Worker 使用 PHP 8.5.10（旧常驻 Worker 使用 php84），Laravel 13.37.0、Horizon 5.50.1、Filament 5.10.2。未修改 PHP 安装或切换 Herd PHP 版本。

方案 A 的密钥由用户在本机配置并更新：只使用 `config/ai.php` 的 openai/deepseek 白名单引用，数据库旧 api_key 未解密、未迁移。报告与 Git 不保存密钥、账号密码哈希或完整环境文件。

## 旧环境退出与隔离

原配置为数据库 `novel`、表前缀 `x_`、Redis 前缀 `ai-database-`、Horizon 前缀 `ai_horizon:`。本轮开始时小说、章节、Run、Artifact、Review、事件、状态、事实、记忆、Task、Issue、Usage 均为零；与 R05 历史样本不同，以当前核验为准。原库保留一个账号和运行配置。旧 generation/default 的 ready/delayed/reserved 均为零，见 [切换前记录](assets/R06/before.json)。没有在途生成需要取消或结算，也没有执行清库或共享 Redis 清理。

先进入维护模式、暂停旧命名空间的调度，随后 bootout 已核实专属 `com.xnovel.horizon` LaunchAgent；旧 master #95055/supervisor #95066/workers #98363、#98376 已退出。原库保持不变作为回退来源，不向新库复制业务历史、旧 payload、旧会话或旧观测。当前用户无 crontab，检查未发现另一份本项目 Scheduler；未停止共享 PostgreSQL/Redis 或其他项目。

私有目录 `storage/app/private/xnr027/`（目录 0700）保存环境备份（0600）、配置导出（0600）与一次性执行脚本。导出中的连接 api_key 强制 null，last_verified_at 清空，避免沿用旧验证状态。完整 `.env` 备份仅在忽略目录中，不进入本报告证据目录。备份时的凭据后来曾被用户更新，回退不能盲目覆盖当前有效密钥。

## 新环境与可复现步骤

| 项目 | 最终状态 |
| --- | --- |
| 业务库 | `xnovel_r06`，表前缀 `x_`，pgvector 已安装，全部迁移成功、待迁移 0 |
| 配置保留 | 1 个原账号、2 个连接、6 个价格/能力档案、11 条路由；系统费用策略原样保留 |
| 密钥 | 两个白名单引用均已解析；新库数据库 api_key 非空记录为 0 |
| Redis | `xnovel-r06:`；仅消费该前缀下 generation/default |
| Horizon | `xnovel-r06:horizon:`；复用项目 LaunchAgent，凭据更新后再次 terminate 并由管理器拉起 |
| Cache / Session | `xnovel-r06-cache-` / `xnovel_r06_session`，原 Cookie 不沿用 |
| Scheduler | 新增唯一 `com.xnovel.scheduler` LaunchAgent，每 60 秒执行 `schedule:run`；非持续进程，间隔中显示 not running 属正常状态 |
| 时限 | HTTP 最高 300 ≤ Job 330 ≤ Horizon 350 < lease 390 < retry_after 480 秒 |
| HTTP 入口 | 维护模式解除，`http://xnovel.test/x/login` 实际返回 200 |

初始化实际采用：确认原业务数据为零 → 私有备份 `.env` 和配置 allowlist → 创建固定名称新数据库（已存在则拒绝覆盖）并安装 vector → 停旧入口/进程 → 修改部署环境数据库和命名空间 → `config:clear` → `migrate --force --no-interaction` → 仅导入 users、ai_provider_connections、ai_model_prices、ai_model_routes、system_settings → 修正序列 → 重启项目 Horizon/配置唯一 Scheduler → 验证 → 恢复入口。未使用 `migrate:fresh`、`FLUSHDB`、`FLUSHALL` 或 `queue:clear`。新库初始化以现有迁移加私有配置 allowlist 可重建；脚本有原库/目标库及空库校验，重复执行拒绝覆盖，不能在后续已有作品时直接重跑。

[最终状态](assets/R06/final-state.json) 覆盖正文、实体、规划、正式状态及记忆等主要业务表，均为零；generation/default ready/delayed/reserved 均为零。旧 payload 隔离依据是原队列为空、旧进程退出、新 Redis 前缀不同，未把旧任务复制入新命名空间。此结论不表示旧 Job 类已经删除；代码删除属于 R07。

[进程记录](assets/R06/runtime-processes.txt) 中新 master #6867、supervisor #6878、generation/default workers #6892/#6893。实际通过新 generation 队列消费无模型部署诊断闭包，记录 worker PID #6892、数据库、Redis 前缀、350/480 时限和已加载的 `book-workflow-r05-v1`。该记录明确标识 `xnr027_deployment_probe_not_generation`，不是小说生成成功的证据。Scheduler 由 LaunchAgent 实际执行并更新持久心跳，多次观测时间前进；不是只依据 schedule:list 判定运行。

## 真实模型与费用核验

首次用户配置可被解析，但两个 `/models` 和三个模型请求均 HTTP 401；保留 [首次失败](assets/R06/probe-auth-failed.json)。用户更新凭据后重载配置和 Worker，两个连接测试通过：OpenAI 返回 133 个模型、DeepSeek 返回 2 个模型。

使用无小说关联的固定 JSON 诊断文本，每个不同模型/推理组合各请求一次；生成输出上限 1024。先执行现有冻结合同的容量/结构化/推理能力校验，再经正常 Tracking Provider 与 Usage Ledger 发真实请求。没有 Fake Provider、小说/任务/Run，也没有调用整书创建入口。

| 实际路由覆盖 | 实际模型 | 结果 |
| --- | --- | --- |
| 基础设定及既有大纲路由、Planner、Reviewer | openai / gpt-5.6-terra，high | 返回预期 JSON，输入 63 / 输出 14 Token |
| Writer、Rewrite | deepseek / deepseek-v4-pro | 返回预期 JSON，输入 169 / 输出 51 Token |
| Extractor、Summary | deepseek / deepseek-flash | 返回预期 JSON，输入 119 / 输出 27 Token |
| Embedding | openai / text-embedding-3-small | 6 输入 Token，实际返回 1536 维，与数据库配置一致 |

见 [生成诊断](assets/R06/probe-result.json)、[Embedding](assets/R06/embedding-probe.json)。这证明当前账号的模型访问、当前参数组合与小型结构化响应可用；不证明模型最大上下文/输出标称值经实测达到，也不证明完整创作 Schema、文学质量、长篇稳定性或供应商未来可用性。能力档案沿用已有配置，未擅改路由或宣称压力测试完成。

最终保留 7 条诊断 Usage：首次 3 条认证失败为 unknown；3 条成功生成有真实 Token 用量、按现存价格估计合计 USD 0.00078714（非供应商实际账单）；1 条成功 Embedding 有用量、价格未知。未知未记成零或删除。除这些诊断账本及健康观测外，没有新增业务产物。

费用硬上限 daily/novel/chapter/issue 均未启用、金额空，预警金额原值为空；未为了测试启用用户未要求的收费上限。Embedding 缺价格时实际成功并保留未知费用，验证默认无金额不阻断。主动硬上限门禁由下述既有隔离回归验证，不在真实账号上制造超额消费。

急停在真实新库临时开启：现有服务的 Provider 请求门禁与 Canonical Commit 门禁均实际抛出拒绝，随后 finally 恢复原 false；见 [急停结果](assets/R06/emergency-check.json)。未在急停检查中发送外部请求或提交章节。

## 回归与边界

实际运行 `php artisan test --configuration=phpunit.pgsql.xml`，指定 ProviderCredentialReferenceTest、GenerationCostPolicyTest、GenerationRequestContractTest、GenerationQueueWorkerTest、IsolatedTestEnvironmentTest：**19 passed / 80 assertions，14118 ms，无跳过**，见 [结果](assets/R06/environment-regression.json)。仅使用 `xnovel_refactor_test`，不清理 R06 库。Pest result=passed；Artisan 包装退出码仍为 1，与 R05 的既有差异一致。未宣称全仓库测试通过，未重新跑 R05 全组或旧 Canonical 失败基线。

本轮无应用 PHP/前端代码变更，不新增镜像测试，也未重复前端构建；使用 R05 已构建资产。文档更新后执行 `git diff --check`。原用户工作区未重置。密钥和运行配置修改仅在忽略的 `.env` / 私有目录，LaunchAgent 只影响本项目。

## 为下一批准备的概述与比较方案（未提交）

以下为准备好的验收输入，尚未创建数据库记录，不表示用户已授权开始 XNR-028。

1. 幻想，约三万字：失去读心能力的年轻守灯人，在每晚会移动的群岛上寻找失踪导师。她只能通过潮汐图和证词推理，不能恢复读心术或突然获得新能力。导师失踪与灯塔能源骗局相连，结局公开真相并解决岛民迁居，章节采用描述性标题，主线与承诺必须收束。
2. 都市现实，约三万字：县城旧书店店员与失业的父亲合作追回一批被错送的家族书信，通过走访修补邻里关系和父女误解。无超自然、无一夜暴富；父亲不识字这一事实影响取证和沟通，不被后续章节遗忘。结局书信归还、书店经营选择明确，人物关系有可信变化。
3. 悬疑，约三万字：不会游泳的邮差收到署名为自己、日期在七天后的信，在暴雨封港前沿岸查明假身份与旧案。线索必须预先出现；主角不能下水脱险，未来来信最终有现实解释，真凶动机、信件来源与失踪者下落均明确交代。

异常专项作品留到 XNR-028 单独创建，与正常三本统计隔离。比较方案准备为三题材各选三个同源章计划，每计划整章/顺序拆分各重复两次；同模型、目标篇幅和前置正式状态，匿名评阅，并把失败与全部修复成本纳入分母。质量先过 PRD 各维均值 3.5 与硬事实冲突零，再比较效率；建议等效容差 0.25/5，最终样本和容差在 XNR-029 开跑前锁定，本轮不实施或宣称策略优劣。

## 交接

**XNR-027 DONE；XNR-028–030 继续 TODO。** 环境已恢复在线，Horizon/Scheduler 保持运行，但没有可推进的小说任务。后续真实作品必须经用户指令另行启动；首次 401 和未知费用历史保留。回退时先停本项目进程并检查新业务是否仍为空，保留新账本与当前有效凭据，再显式评估切回原库，不能靠覆盖备份或删除新库抹掉现场。
