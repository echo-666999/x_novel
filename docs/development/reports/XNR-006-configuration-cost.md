# XNR-006 配置、容量、费用与凭据

日期：2026-10-17。基线提交：99588fb；工作树未提交。状态：DONE（R01 合同与收费边界）。依赖 XNR-005。对应 PRD A06/A10/A18/A21/A31–A34。

## 配置与凭据

用户选择 A 已同步 PRD 和架构文档。数据库保存 credential_ref，只有 config/ai.php 白名单 `openai` / `deepseek` 能解析到部署环境的 `XNOVEL_OPENAI_API_KEY` / `XNOVEL_DEEPSEEK_API_KEY`。设置页只选择引用；模型拒绝写入非空 api_key，旧密文不再解密、迁移或回退。旧字段保留到 R07，并已准备 nullable / 中文弃用备注的迁移。本次没有读取或复制旧密钥，没有修改 `.env`。

AiSettingsResolver 继续解析数据库全局路由及小说覆盖，不增加平行 Router。GenerationRequestContract 在建新 Run 前冻结实际 Provider/Model、连接身份与非秘密连接参数、Prompt 全文/版本/Checksum、Schema、能力、输入上界、可见输出/推理预留和价格依据。价格缺失与能力缺失分开：有已核实能力但价格为空可以冻结；容量未知或不足、能力不匹配不能发出请求。

输入上界覆盖完整 System/messages/Schema 的 UTF-8 字节数，另留 256 Token 协议余量；这是偏保守的 Admission，不声称是供应商精确 tokenizer。maxTokens 表示可见输出加推理预留总量，避免重复预留。模型/Prompt 变化需新尝试；JSONB 的对象顺序不导致合同漂移。凭据撤销、端点变化、任务暂停/控制版本与急停在发送前再次核实。

## 费用授权与请求账本

GenerationCostPolicy 为创建/编辑/运行时提供同一规范化入口。warning_amount 与四类金额上限独立；金额值不是启用开关。日、任务、章节、问题上限均默认关闭。已启用范围取交集，不自动提高额度、减少篇幅或跳审。创建入口保留 budget_limits，小说 settings 保存 budget_history；全局 system_settings.policy_history 保存操作人、时间、币种和前后值，并有实际中文字段备注。

RequestUsageLedger 是当前 TrackingAiProvider / TrackingEmbeddingProvider 的共同请求边界，诊断和无 Run 请求也生成独立 UUID。一次新链 Run 对应一个收费请求；再次发送需要新尝试，不能覆盖未知调用。

发送前按全局设置 → Novel → Task → Issue 的顺序加锁，以十进制数累加已结算金额及未决预留。prepare 后、send 前重新检查当时有效上限，避免队列期间改限额无效；同一预留不重复计算。币种改变不自动换汇。只有明确启用上限才要求可靠估价；关闭上限保留全部历史和未知金额。

| 请求状态 | 含义与处理 |
| --- | --- |
| prepared | 已建账，尚未标记发送；占用能被并发范围看到 |
| in_flight | 发送边界已越过，结果可能未知 |
| settled | 用量/费用已记录；estimated 与 actual 明确区分 |
| unknown | 传输中断、Worker 丢失或响应缺用量；保留占用与证据 |
| not_sent | 能证明未发送，可释放预留 |

响应即使有正文但没有 usage，也不能把 DTO 的兼容零值记作免费。适配器传递 usageAvailable；账本保留 null Token/金额与请求 ID。已返回用量按冻结价格结算，保留实际返回模型、推理、截断和非秘密发送参数。缺价格显示未知；微小金额不格式化成免费。

`reconcile()` 需操作 UUID、明确非负实际金额、依据和操作人；追加 reconciliation_history，重复相同操作幂等，不接受同 key 改口。核销只调整账，不推进故事。原始 Prompt/响应和历史价格快照不被核销覆盖。完整账单核销页面归 XNR-025。

旧 ContentSelfHealing 的默认金额停点已关闭；有限次数、执行时间及无进展限制仍保留。BudgetGuard 不再写旧 Run.reserved_cost；新收费预留只由 Usage 账本承担。旧 BudgetService 中尚未清理的方法不在实际收费装饰链调用，整体删除由 R07 审核引用后处理。

## 验证及边界

实际测试涵盖默认预警后继续、缺价格继续、缺用量保持未知、开启上限阻止、关闭上限不清账、发送前改限额、全局与小说保存、Embedding/无 Run 记账、核销幂等、冻结价格、Prompt 漂移、连接撤销、容量不足以及两个 PostgreSQL 会话竞争同一额度。适配器回归只使用 Http::fake；测试默认禁止外发。

最终联合回归 120 passed / 662 assertions，1 个 SQLite 专用测试在 PostgreSQL 跳过，见 [R01 汇总](R01-implementation.md)。没有真实收费调用；不宣称供应商账单已核对，也不宣称 R02 问题恢复界面、R03/R04 阶段和 R05 费用设置整页已完成。运行数据库未迁移，当前可运行完整小说仍须后续批次。
