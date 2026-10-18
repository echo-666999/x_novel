# 新版小说生成数据合同

版本：1.2 · R00 合同 / R01 实施 · 2026-10-17。

依据：[PRD](../PRD.md)、[视觉规范](../../DESIGN.md)、[当前基线](../development/reports/XNR-001-baseline.md)。与 [执行流程合同](generation-workflow.md) 配套。本文定义目标合同。R01 已提交到工作树的迁移在隔离 xnovel_refactor_test 验证；运行库未迁移。实际字段/约束见 `2026_10_17_210532_add_refactor_generation_foundation.php` 及 [R01 报告](../development/reports/R01-implementation.md)，不能据此认为后续业务阶段已经完成。

## 1 决策与数据边界

1. PostgreSQL 唯一业务权威；Redis 只负责 Queue/缓存/辅助锁。旧小说不迁移，新版历史不可覆盖。
2. 仅新增两张业务表：`novel_generation_tasks` 和 `generation_issues`。请求账本扩展 `usage_records`，片段用 Artifact，不新增请求表、片段表、修复任务表或 Outbox 表。
3. 三层规划：全书蓝图与近期目标版本复用 `novel_outlines` 的轻量索引；正文/规划内容来源为不可变 Artifact；当前章节计划复用 `chapter_plans`。
4. `volumes` 保留为可选目录组织。旧 `novel_outline_volumes/arcs/beats/milestones`、`story_arcs`、`scenes` 列为最终删除候选，其仍有价值的目标/承诺/事件语义迁入新合同后才能删除；新生产链不依赖这些表。
5. 当前 36 张逻辑表中，按本设计口径 23 张为小说核心表，13 张为账号/运行配置/框架设施。核心表新增 2、最终移除上述 6 后预计 19 张，含可选 volumes；这是目标清单，不是本批删表结果，也不把约 18 张误当必须凑齐的数量。
6. 用户已确认凭据方案 A，见 §10；本合同不保留平行凭据实现。

目标核心清单（19）：novels、novel_bibles、volumes、novel_outlines、chapter_plans、chapters、characters、world_entities、foreshadowings、facts、story_events、story_state_versions、generation_runs、generation_artifacts、reviews、memories、usage_records，以及新增的 novel_generation_tasks、generation_issues。

独立保护的 13 张配置/设施表：users、ai_provider_connections、ai_model_routes、ai_model_prices、system_settings、cache、cache_locks、jobs、job_batches、failed_jobs、migrations、password_reset_tokens、sessions。分类用于说明职责，不授权清空这些表。

## 2 通用约束与权威来源

表名为逻辑名，实现沿用部署表前缀；DDL 通过实际连接前缀生成，不写死 `x_`。新时间字段采用带时区时间，应用按 UTC 存储、界面按配置时区呈现；记录耗时用整数毫秒。金额采用 `numeric(18,8)` 和币种，禁止浮点累计；tokens/字数非负 bigint。JSON 结构统一 JSONB、有 schema_version。

每个新增/修改表、字段必须有实际落库的中文备注，含含义、状态、单位、JSON 版本和归属。所有记录有主键；状态 CHECK、必填 NOT NULL、唯一约束与外键在 PostgreSQL 落实，不只由 UI 校验。

| 对象 | 唯一权威 | 派生或索引，不可反向覆盖 |
| --- | --- | --- |
| 原始意图 | intent Artifact 与任务引用 | 小说标题/摘要和工作台摘要 |
| 规划版本内容 | 规划 Artifact | novel_outlines 的版本/采用元数据 |
| 当前章计划 | 不可变 chapter_plans 字段及 Admission 快照 | plan Artifact 是该版本冻结副本，checksum 必须相同 |
| 候选正文 | chapter_draft/rewrite_draft Artifact | 片段组装清单、正文预览 |
| 正式正文 | chapters.canonical_artifact_id | 导出、记忆和索引 |
| 正式世界状态 | novels.canonical_state_version_id 指向的 State Version | 人物/世界实体投影；Vector Memory |
| 运行进度 | Task + 有效 Run + 当前 Issue + 持久待推进意图 | UI 进度/计数、Redis 临时信息 |
| 请求费用 | usage_records 的请求级账本 | Task/Chapter 汇总、预警计数 |

多来源 hash：对对象键排序，列表顺序保留，保留数值/布尔/null 类型；不直接以 PostgreSQL JSONB 输出键顺序做 PHP 严格数组比较。hash 输入含意图版本、真正使用的目标节点内容、状态版本、Prompt/Schema/算法版本、路由/容量/策略；不含无关远期节点或运行时间戳。

归属强制：为可被复合引用的表建立 `UNIQUE(novel_id,id)`，chapter_plans 等原先仅有间接归属的表补 novel_id 并用复合 FK 关联 Chapter；章节、Run、Issue、Artifact、规划与状态的引用通过复合外键证明同小说归属。JSON 中来源清单无法用 FK 约束的部分，在锁定小说的短事务中逐项验证存在、类型、归属和 checksum。不是“加一个普通 ID 外键就够”。正式历史与来源引用默认 RESTRICT 删除，取消任务不级联删账本/Artifact；最终旧表清理另行核实引用。

## 3 整书任务 novel_generation_tasks（新增）

| 字段组 | 类型 / 空值 / 默认 | 中文含义与规则 |
| --- | --- | --- |
| id、novel_id | bigint 主键 / 非空 FK | 整书任务与所属小说 |
| submission_key | uuid，非空，唯一 | 创建请求幂等身份；重复按钮返回同一小说/任务，不新建第二本 |
| status | varchar，非空，queued | queued/running/paused/needs_attention/completed/cancelled/failed |
| mode | varchar，非空，full_book | full_book/opening/manual_review |
| intent_artifact_id | bigint，非空 FK | 原始概述和显式约束的不可变来源 |
| target_words、opening_chapter_count | bigint>0；integer>0，模式需要时有值 | 目标篇幅；开篇暂停章数，默认 3 |
| policy_snapshot | jsonb，非空 | schema_version、篇幅/模式、授权模型范围、费用策略初始版本、修复上限、记忆策略 |
| policy_revision、control_version | integer，非空，1 | 用户授权/策略变更版本；暂停/恢复/取消等 CAS 版本 |
| current_phase、current_chapter_id | varchar 非空；可空 FK | 展示和恢复位置；不是正式章/状态权威 |
| pending_transition | jsonb，可空 | 持久下一动作：stage、scope、source_identity、idempotency_key、not_before、原因；没有待执行则 null |
| progress_snapshot、last_progress_at | jsonb 默认 {}；时间可空 | 可重算进度与最后有效业务进展，不是 Worker 心跳 |
| stop_reason、stop_detail | varchar/jsonb，可空 | 用户暂停、开篇目标、等待提交、阻塞、取消或终止原因 |
| replaces_task_id、created_by | 可空 FK | 替代关系和操作者；替代任务须同小说 |
| export_manifest_id | 可空 FK | 完整作品导出结果，completed 前必填并复验来源 |
| started_at、finished_at、created_at、updated_at | 时间，开始/结束可空 | 生命周期；没有 lease 字段 |

必需约束：同小说 `status IN (queued,running,paused,needs_attention)` 的部分唯一索引，暂停也占身份。failed 只用于不可继续的终止，不用于普通可恢复错误。终态不重新置 running；需要重建时事务内结束旧任务并创建替代任务。

初始化次序：同一事务创建 Novel、确定性 intake Run（此时尚无 Task）、intent Artifact，再创建引用该 Artifact 的 Task，回填 Run 的 task_id 与 Novel 的当前任务指针。intent 引用从 Task 插入起即非空；不新增延迟约束触发器。事务提交前验证主链 Run 均已关联 Task，失败整体回滚。重复 submission_key 冲突时回滚本次全部记录并返回已有任务。

模式改变或用户调整硬上限不原地改写旧快照：递增 policy_revision，保留策略变更 Artifact（操作者、原因、前后值）。新请求按有效授权与最新主动硬上限检查，历史 Run 快照不被覆盖。

## 4 generation_runs（重构）

保留现有小说/章节、stage/status/attempt、input_hash、Prompt、context_snapshot、时间、错误和租约字段；新增并收敛下列合同。

| 字段 | 类型与规则 | 用途 |
| --- | --- | --- |
| generation_task_id | bigint FK，主链初始化事务提交前非空 | 整书归属；独立诊断/账户测试可无任务，但收费仍走 Usage |
| issue_id、retry_of_run_id | 可空 bigint FK，同小说 | 关联修复问题与前次尝试；不再依赖猜测 JSON 父级 |
| logical_step_key | varchar 非空 | 不含尝试号的步骤身份：novel_id+scope+stage+完整输入 hash |
| attempt、idempotency_key | 正整数；全局唯一字符串 | UNIQUE(logical_step_key,attempt)，幂等键包含尝试号 |
| pipeline_version、schema_version | varchar 非空 | 流程与输出合同版本 |
| model、connection_id | 模型可空；连接可空 FK | AI Run 必填且快照实际连接版本；确定性阶段无模型 |
| scope_key | varchar 非空 | book/chapter/fragment/memory/export 的稳定逻辑身份；移除多态 scope_id 作为归属权威 |
| source_manifest、execution_contract | jsonb 非空 | 多来源 Artifact ID/checksum、计划/状态、路由/能力、Prompt/Schema 哈希、请求参数/超时/修复限制；不含秘密 |
| result_artifact_id | 可空 FK | 该 Run 已采用输出指针；同小说、来源同本次 Run |
| next_attempt_at、dispatch_expires_at | 可空时间 | queued 的退避时间与待派发认领期限，不与 Worker 租约混同 |
| lease_token、lease_expires_at、heartbeat_at | 可空 uuid/时间 | 仅 running 的 Worker 所有权；开始/保存/续租均 CAS |
| checkpoint | 可空 JSONB | 真正 deferred 的可恢复断点；必须有有效产物/部分进度 |
| outcome_code、error_metadata | 可空 varchar/JSONB | 完成结论/结构化错误，区分 Review REWRITE 与 Provider 故障 |

Run 状态 CHECK：queued/running/succeeded/failed/cancelled/deferred。正常阶段完成用 succeeded；review 返回 REWRITE 也是成功执行的一次审校。重试创建新 Run，不覆盖前次响应；重复投递同一 Run 不增加 attempt 或请求数。

约束：同一 logical_step_key 最多一个 queued/running Run；同一小说可同时有合法非关键记忆派生任务，但主章节推进在 Task 锁下串行。一个 Run 最多一次实际 Provider 调用；需要格式修复/截断续写时另建有来源的新 Run。原 `reserved_cost` 不再是费用权威，迁往 Usage 账本后列为移除候选。

lease 失效后旧 Worker 可以关联保存隔离响应及 Usage 结果，但不能更新当前 result_artifact_id、任务进度或正式指针。晚到成功不能抹去替代尝试。

## 5 generation_issues（新增）

| 字段组 | 类型 / 约束 | 中文含义 |
| --- | --- | --- |
| id、novel_id、generation_task_id、chapter_id | PK、同小说 FK；章可空 | 问题所属范围 |
| fingerprint、source_identity | char(64) / JSONB，非空 | 规范规则+范围+来源+问题目标形成指纹；保留复验依据 |
| category、error_code、severity | varchar 非空 | 分类、稳定错误码、info/warning/error/critical |
| status | varchar，默认 open | open/diagnosing/repairing/awaiting_user/resolved，与 PRD §6.6 一致 |
| first_run_id、latest_run_id、resolved_by_run_id | 可空 FK | 首次、最近、解决尝试；技术诊断可能没有原 AI Run |
| user_message、evidence、allowed_actions | text / JSONB | 中文说明、脱敏证据、服务端白名单动作及影响 |
| attempt_count、repair_elapsed_ms | 非负 integer/bigint | 可从关联 Run 对账，不能通过新 Run 重置 |
| repair_limit_snapshot、deadline_at | JSONB / 可空时间 | 次数、累计执行耗时、无进展保护；deadline_at 是独立总截止时间，默认 null，不用它替代执行耗时；金额限额仅主动开启时启用 |
| verification、resolution_reason | 可空 JSONB/text | 复验版本、结果/人工解释，不能只有“AI说修好了” |
| first_seen_at、last_seen_at、resolved_at | 时间；解决时间可空 | 生命周期与界面排序 |

活跃 fingerprint 部分唯一（open/diagnosing/repairing/awaiting_user）；同一问题的新尝试关联原 Issue。改变来源后旧问题的关闭/取代必须复核，不能通过不断换指纹清零修复次数：章节/目标层级累计修复计数同样保留，修订记录关联旧 Issue。

非阻塞建议可由用户明确接受例外，以 resolved + resolution_reason=accepted_soft_exception 记录，并保留原 finding；硬事实、权限、失效来源、容量或提交一致性问题不可忽略。resolved 必须有复验证据或有效软例外授权。用户处置并发在 Task/Issue 锁下重新检查来源和允许动作；取消任务不把未解决问题伪装为 resolved。

## 6 规划、正文、审校与正式状态

### 6.1 小说与规划

- `novels` 保留标题/题材/概述/篇幅/状态/正式章序/状态指针；新增 current_generation_task_id、current_blueprint_id、current_goal_window_id。原 current_outline_id 迁为有明确角色的两个引用，不并存三套权威。
- `novels.settings` 有 schema_version，保留创作偏好、小说级模型覆盖、费用偏好及 UI 不敏感设置；旧 auto_generate/auto_commit 不再独立决定行为，统一由 Task.mode 推导。
- `novel_bibles` 保留版本及采用指针，基础包采用时落库；已采用硬约束/结局修订创建新版本和人工操作记录，不原地改旧 Run 所依赖的内容。初始实体是设定而非已经发生的 Story Event；正文引入新实体时先留候选，随同源 Review 和 Commit 验证后正式化。
- `novel_outlines` 复用为版本索引：id、novel_id、kind（blueprint/goal_window）、version>0、source_artifact_id、based_on_outline_id、checksum、created_by、created_at、applied_at。UNIQUE(novel_id,kind,version)，immutable 内容在 Artifact；不重复维护第二份 JSON 内容。
- 蓝图 JSON：schema_version、premise_ref、main_goal、reader_promises（稳定 key）、character_arcs、turning_points、ending_contract、hard_constraints、optional_volume_groups。
- 近期目标 JSON：blueprint_ref、based_on_state_version、goals（稳定 key、objective、promise_refs、expected_change、chapter_range、required_facts、forbidden_conflicts、closure_refs）；每个目标独立 content_hash，不因补远期窗口让当前章失效。
- `chapter_plans` 保留 chapter_id/version、target_words、chapter_function、POV、tone/time_anchor、required/forbidden 等有效字段；新增 blueprint_id/goal_window_id、goal_refs（key+hash）、source_state_version_id、schema_version、admission_snapshot。移除旧 Arc/Beat/Milestone FK 与必填 scene_plans；可选 segments 仅在策略需要时出现。
- Admission 包含可执行性、冻结来源、全链容量、writer_strategy（whole/segments/scenes）、理由和本章出口目标；不是模型自评。UNIQUE(chapter_id,version)，采用后内容不可原地改写。

### 6.2 Artifact 与可选片段

`generation_artifacts` 新增 novel_id、schema_version、source_manifest；保留 run_id/type/version/content/data/checksum/input_hash。将来源和有效性定义集中，不在 content/data 同时保存两份正文；正文在 content，结构化伴随内容在 data。

产物类型目标集合：intent、foundation_blueprint、goal_window、chapter_plan、context、chapter_draft、chapter_fragment、rewrite_draft、event_candidate、state_patch、review_result、repair_proposal、summary、ending_audit、export_manifest、operation_record。Enum 与 DB CHECK 同步；旧 outline_* 类型在不迁移旧小说前提下退出。

正文 data 包含 paragraph_index（稳定段落 key、偏移、正文 hash）；引用文字必须绑定 Artifact checksum 和段落范围。换稿后重建索引并重新提取/审校，旧 PASS 不复用。

片段使用 chapter_fragment Artifact，data 保存 plan_ref、fragment_key、sequence、previous_fragment_id/hash、target_words、complete 标记。组装 Artifact 的 manifest 明列顺序与所有来源；不新建 Scene 行。未完成片段仅可作为续写输入，不能当完整章通过审校/提交。

validation_status 为 pending/validated/rejected；有效性再取决于来源和当前采用指针。历史内容不可覆盖；验证状态只允许 pending 到最终状态一次，失效通过撤销采用/来源变化表达，不改写历史 payload。晚到产物保留但不被当前指针采用。

### 6.3 Chapter、Review 与正式状态

`chapters`：UNIQUE(novel_id,sequence)，volume_id 可空；新增 current_plan_id/current_draft_artifact_id；canonical_artifact_id 唯一决定正式正文。状态 planned/generating/review/needs_attention/canonical/void 不取代 Task/Issue 的运行状态，当前阶段失败不意味着正式旧章失效。

`reviews` 保留 artifact_id/run_id/decision/findings，新增 novel_id、source_manifest、rules_version；decision 为 PASS/REWRITE/NEEDS_ATTENTION/BLOCK。每条 finding 含 rule_id、severity、scope、paragraph_refs、证据、是否可修复、是否需用户决定。分数可空，不强制模型重复生成汇总/Decision；程序依据硬门禁与实际语义 findings 得出最终决定。

正式事实表沿用 story_events/story_state_versions/facts/characters/world_entities/foreshadowings：事件只在统一 Commit 事务正式写入；State Version 每次递增，旧版不可覆盖；facts 只放必要硬事实；人物等 current_state 是可重建投影。事件增加来源 Artifact/段落引用，scene_id 及旧规划节点 FK 退出，使用真实 goal_key/blueprint 版本溯源，不创建未来事件。

正式提交唯一效果由章节指针+状态版本+事务锁保证；UNIQUE(novel_id,version) 保留，提交产生的每条事件有稳定 event_key 并 UNIQUE(chapter_id,event_key)。event_key 由提交操作身份与候选事件 key 派生；提交操作身份包含正文、前置状态及最近回滚操作版本，重复投递不变，受控回滚后再次提交则不同。Review、EventCandidate、StatePatch 必须同一当前正文 checksum、计划及前置状态；仅 Review PASS 不足以跳过其他门禁。

最新章回滚复用现有受控机制：恢复上一正式指针、失效该章派生记忆、修正投影/伏笔并留原因与操作记录；不能静默删除已被后续章依赖的历史。只允许最近正式章，不支持任意分支；回滚后的同章再次采用沿操作来源创建新的正式效果记录，不覆盖原 Artifact/Review 历史。

## 7 usage_records 请求账本（扩展，不新增表）

当前只在返回后写 Usage 且未知价格记 0，不能满足未知响应与缺 Run 的金额预留。新版在请求前创建请求级记录，返回后结算同一行，历史金额修订保留依据。

| 新增/调整字段 | 类型 / 约束 | 含义 |
| --- | --- | --- |
| request_key | uuid，非空 UNIQUE | 应用侧实际请求身份，发出前持久化，不依赖供应商 ID |
| generation_task_id、issue_id | 可空 FK，同小说 | 汇总与问题成本归属；既有 generation_run_id 保持可空但生产 Run 有值，不另加 run_id 同义列 |
| status | prepared/in_flight/settled/unknown/not_sent | prepared 尚未提交传输，in_flight 发出状态，unknown 结果不确定，not_sent 已证实未发 |
| cost_status | unknown/estimated/actual | 不把估价装成供应商结算 |
| currency、price_snapshot | varchar/JSONB，币种非空 | 当次计价币种、价格版本与依据；未知价格快照说明原因 |
| estimated_cost、actual_cost、reserved_amount | 可空 numeric(18,8)，非负 | 未知为 null；已启用上限时 reserved 必须有可靠上界才可发送 |
| tokens/latency | 可空非负数 | 响应前未知为 null；零仅表示确认零，不默认全填零 |
| sent_at、settled_at、reconciled_at | 可空时间 | 实际发送/结算/对账时间 |
| cap_snapshot、request_metadata | JSONB | 当次有效启用范围、策略版本和无敏感参数，不保存 API Key |
| reconciliation_history | JSONB 数组 | 补价/核销/费用修订的时间、依据、前后值；禁止覆盖证据 |

每个 AI Run 至多一条 request_key（对非空 generation_run_id 唯一）；无 Run 的连接测试仍先建 Usage。保留供应商 request_id 的可空去重约束，但不能把供应商不保证唯一的 ID 当唯一应用身份；若适配器不能保证该唯一性则改为连接/供应商/模型的经验证范围并用 request_key 对账，R01 用实际适配器确认。

金额原子预留在短事务中锁定现有 generation 设置行，再按固定顺序锁 Novel/Task/Issue，计算所有已启用范围“已记费用+未结清占用+本次上界”。不同币种不得直接相加；本版统计币种 USD，其他币种以独立策略/人工确认转换依据处理，不能默默汇率换算。

默认关闭硬上限时，缺价格只记 unknown，正常继续；未知费用不记零。开启硬上限且无法可靠估上界时不发送新请求，创建可操作 Issue。请求发出后超时/进程退出保留占用，不能 finally 清零。确认未发可释放，确认账单/用量后结算；用户主动关闭限额允许后续请求，但不删除未知记录。

运行中开启或收紧硬上限时，同一锁内把已有在途/未知请求纳入核算；不能只约束新建账本行。已有未知项不能可靠估价时暂停后续收费请求并展示核销选择，已经发送的请求允许结算，不能宣称可撤销供应商费用。

同一个请求不能既计入已记费用又保留全额占用；settle 原子转账本状态。每日作用域以冻结的计价时区及 sent_at 日期归属，跨午夜的在途请求仍属于原发送日；当前日不会遗忘仍需约束的任务/章节/问题范围。

## 8 记忆、收束与导出

memories 沿用现有表与 pgvector(1536) 基线，不因重构换模型维度。新增/补齐 source_artifact_id、source_state_version_id、derivation_version、validity_reason；只接受 Canonical 来源，唯一派生身份避免重复。更换维度另行明确决策，不能静默混存不同向量空间。

正式摘要作为 summary Artifact，chapters.summary 仅为当前有效摘要的展示缓存，新增 summary_artifact_id 指向来源；下一章验证摘要对应 canonical_artifact_id。Embedding 可降级，但必需摘要缺失不能跳过。

ending_audit Artifact 包含蓝图/当前状态来源、promise/goal/foreshadowing/character 的闭合证据与剩余 critical 项。导出使用 export_manifest Artifact，记录有序章节 ID+canonical Artifact checksum、格式版本、文件位置/hash/字数和创建时间；任务引用该 manifest，不新增导出业务表。存储文件由应用保护访问，生成失败仅补导出。

## 9 索引、初始化和迁移验收

重点索引：Task(novel_id,status)、Run(status,next_attempt_at/lease_expires_at)、Run(task,stage)、Issue(task,status,severity,last_seen_at)、Usage(task/chapter/issue,sent_at,status)、规划(novel,kind,version)、Chapter(novel,sequence)、Memory(novel,status,type) 及现有向量索引。只有实际查询需要才加组合索引。

R01 在隔离 PostgreSQL 建空库验证中文备注、所有 CHECK/FK/部分唯一、复合归属、反向引用创建事务、失败回滚和重复键冲突。旧小说无需迁移，但账号/连接/路由/价格/部署配置有独立清单；本批不提供可误打当前库的清空命令。

R07 最终删除候选涉及外键时先迁移新来源合同和所有调用；检查空库安装、约束名称、动态注册、备份/恢复文档。SQLite 不能证明 PostgreSQL 的实际约束和 JSONB/向量行为。

## 10 凭据存储决策（已确认 A）

2026-10-17 用户明确选择 A：数据库保存 provider、连接地址、超时、启用状态与 credential_ref；密钥只存 .env，并经 config/ai.php 白名单解析。引用默认为 openai/deepseek，对应拟实现的 XNOVEL_OPENAI_API_KEY / XNOVEL_DEEPSEEK_API_KEY。未知引用拒绝，不能动态读取任意环境变量。

后台只显示引用及是否配置，禁止写密钥或编辑环境文件；环境配置缓存/进程重载由部署负责。连接禁用立即影响后续请求，密钥不得进入 Prompt、Artifact、日志或测试夹具。现有数据库 api_key 不作为新版回退来源；不自动解密或迁移现存凭据。隔离新库落实引用字段，旧字段在已核实切换和 R07 清理时移除。

R01 实现本合同；当前运行库及 .env 本批不修改。运行环境中尚无对应密钥时如实显示未配置，不偷偷读取旧密文继续收费。

## 11 R01 已实现的基础映射

新增两张业务表及同小说复合引用；实际迁移保留旧层级到 R07。Run 增加 execution_contract、source_manifest、logical_step_key、retry_of_run_id、result_artifact_id、next_attempt_at、dispatch_expires_at、checkpoint；Task.pending_transition 为持久派发意图。Lease 只属于 Run。价格快照/能力/Prompt 由 GenerationRequestContract 冻结，JSONB 对象比较使用规范化哈希。

Usage 新增 request_key、status、cost_status、price_snapshot、reserved_amount、actual_cost、cap_snapshot、reconciliation_history 与生命周期时间。缺价格/缺用量保持 null；原始请求和核销历史不覆盖。system_settings.policy_history 保存全局金额授权修订，小说 settings.budget_history 保存小说级修改；没有新增审计表。新字段含实际中文备注。

新 timestamptz 写入明确时区偏移，数据库保存绝对时刻；旧 timestamp 字段未以迁移方式重解释历史。运行库仍保持原 Schema，不能在部署前直接消费新版任务。


## 12 R03 数据使用与版本边界

R03 不新增表或列，不执行运行库迁移；沿用 R01 已有约束及中文数据库备注。`NovelOutline` 开放已有 `kind` 字段的显式赋值，不能因此认为旧大纲关系结构已经删除。

Intent 的 `data.schema_version=2` 保存 premise、requirements、explicit、target_words、target_source、premise_targets、target_conflict、mode、opening_chapters、budget；人工选择追加 clarifications，新意图来源指向旧 Artifact。Task 保留 policy_revision/control_version 与历史、当前 intent_artifact_id。累计 Usage/Issue 尝试不随修订清零。

Foundation 的不可变数据包含 fields（value/source/evidence）、length、requirements、promises、characters、world_entities、conflicts。失败响应的 validation_status=rejected，不可初始化。普通补充与用户原文分开标注；引用有效不等于语义忠实度已获真实模型验收。

初始化凭证使用 succeeded Intake Run + OperationRecord，source_manifest 包含 Intent/Foundation/Blueprint/GoalWindow；Task.progress_snapshot.initialization 只是快捷引用。Bible.hard_constraints 保存原始要求及选择，ending_contract 保存结局方向与读者承诺，style_profile 使用 book-v1 自然语言描述。初始 State 仅创建一次；失败时这些记录和指针同时回滚，错误追踪在回滚后独立保存。实际验证见 [R03 报告](../development/reports/R03-implementation.md)。

## R04 Schema 与来源增量（2026-10-18）

不新增表。新增两份迁移只在隔离库验证，运行库未执行：

- `2026_10_18_115324_allow_unscored_narrative_reviews.php`：Review 总分及七维分数允许 NULL，含中文字段/表备注；新版按条目和规则判断，不伪造分数。有任意未评分记录时拒绝恢复旧 NOT NULL。
- `2026_10_18_115535_allow_three_layer_chapter_plan_sources.php`：四个旧 Outline/Arc/Beat/Milestone 引用可空，含中文备注；`book-plan-v1` 强制 novel、blueprint、goal_window、source_state_version 及非空目标引用数组。有新版记录时拒绝有损降级。

Blueprint/Window 内容只在不可变 Artifact，NovelOutline 作为版本索引；`goal_refs` 保存 key/content_hash，未变目标不因窗口版本号变化而失效。规划修订保存操作者与旧版本来源。

ChapterPlan 的 `admission_snapshot.plan` 保存完整 POV/人物变化等新计划语义，不伪造旧 Scene 或 Arc。上下文和四阶段容量合同冻结于 Admission，并另存 Context Artifact。重新准入生成新 Plan 版本。

Writer 的原始响应与段落信息分层保存：片段正文为 ChapterFragment，确定性组装为 ChapterDraft；局部替换响应及新的完整 RewriteDraft 均追加版本，不覆盖旧稿。段落 key/Unicode offset/length/hash 是当前完整正文的派生索引。

Review 的 `source_manifest` 同时绑定当前完整草稿、事件候选与状态补丁；`rules_version=book-review-v1`。新稿不继承旧 PASS。提取与补丁均为候选，R04 不写入正式 Story Event/Fact/Memory，也不推进 Canonical Pointer。

## R05 正式闭环的数据使用（2026-10-18）

本批不新增表/字段/迁移，复用既有 PostgreSQL 合同。正式提交写 Chapter canonical 指针、StoryEvent、Fact、StoryStateVersion、Novel 正式指针及提交 OperationRecord；同一事务记录 Task 的必需摘要待办。Chapter `canonical_metadata.book_sources` 固定任务、计划、审校、事件、补丁和提交前状态身份。摘要绑定 canonical Artifact 并回填 `summary_artifact_id`。

派生 Run 使用 `book-derived-v1`，仍归属原 Task/Novel 与 Usage；不占用当前章主链待办。Memory 仅引用有效正式章/事件。完结 Artifact 记录逐项证据、确定性债务、蓝图和正式状态；Task.progress_snapshot 保存审计指针、累计补章次数及人工有限补章授权历史。ExportManifest 冻结正式章序/标题/正文 hash、文件位置与文件 hash。

最近章撤回追加完整恢复 StateVersion 和 OperationRecord，失效对应事件/记忆并恢复被取代事实；旧状态和不可变产物不覆盖。系统心跳复用 SystemSetting 保存执行时间与实现标识，属于运行观测，不是故事事实。
