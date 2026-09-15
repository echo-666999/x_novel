# 《六环余光》伏笔历史修复 Dry-run 报告

> 任务：FSO-011  
> 生成时间：2026-09-15  
> 冻结计划：`foreshadowing-history:edb77716e0e986a10c175002887f592df4afd338d3847884161d837e697f13ab`  
> 数据状态：已于 2026-09-15 显式执行；Canonical State 已从 v13 更新到 v14  
> 完整机器快照：[`foreshadowing-repair-plans/six-ring-afterglow-v13-dry-run.json`](foreshadowing-repair-plans/six-ring-afterglow-v13-dry-run.json)
> 执行结果：[`foreshadowing-repair-plans/six-ring-afterglow-v14-execution.json`](foreshadowing-repair-plans/six-ring-afterglow-v14-execution.json)

## 1. 执行前门禁

Dry-run 已确认：

- 小说为《六环余光》#2。
- 当前 Canonical Story State 为 v13，checksum 为 `563dd01246898bd36a8fa0e233869a798a8c45b54aa611059691e6dacc406cfd`。
- State Version v1 是最新的完整无章节基线；从 v1 重放全部 Active Story Events 可以逐字节重建当前 v13 checksum。
- 四条待处理事件都仍为 Active，类型与冻结计划一致。
- 四条事件的全部 evidence 都逐字存在于各自章节当前 Canonical Artifact，且 artifact ID 一致。
- 四条待处理 Event 没有被任何 Fact 引用；若执行前新增此类引用，计划会停止。
- 当前仍有 23 条 Active 伏笔事件和 23 条 Active 伏笔 Memory；dry-run 后数量与 Canonical 指针未变化。
- 世界实体 #4“魔法代价”已经包含“代价不可避免”“代价大小与魔法强度相关”规则，不重复创建世界规则或无证据 Locked Fact。

只要小说的 Canonical version/checksum、事件状态、事件类型或 Canonical evidence 任一变化，冻结计划就会拒绝执行，必须重新 dry-run。

## 2. 冻结事件修复

| Event | 当前类型 | 计划动作 | 证据判断 | Memory 处理 |
|---|---|---|---|---|
| #15，第 1 章，伏笔 #1 | `foreshadowing_reinforced` | 替换为 `foreshadowing_planted` | 正文首次建立魔法代价及后续影响；原事件违反 `idea → planted → reinforced` | 原 Memory 失效；新 Event 复用同一正文证据和 Memory 内容/Embedding |
| #24，第 2 章，伏笔 #2 | `foreshadowing_reinforced` | 替换为 `foreshadowing_planted` | 正文首次出现未知黑影和被注视感；提前铺设不违反兑现窗口 | 原 Memory 失效；新 Event 复用同一正文证据和 Memory 内容/Embedding |
| #41，第 4 章，伏笔 #3 | `foreshadowing_reinforced` | 失效，不替换 | 证据只说明一般魔法代价和停止施法，没有出现“为他人牺牲”，属于错误绑定 | 原 Memory 失效，不创建替代 Memory |
| #52，第 5 章，伏笔 #3 | `foreshadowing_reinforced` | 替换为 `foreshadowing_planted` | 正文首次明确写出林墨先推开苏璃并承担自身损耗 | 原 Memory 失效；新 Event 复用同一正文证据和 Memory 内容/Embedding |

原 Event 不会删除或改写正文、payload、evidence；只追加 invalidation metadata。每个替换或失效都会追加 `EventCorrected` / `EventInvalidated` 审计事件，记录冻结计划、原因、操作者、时间、原 Event 和替代 Event。

## 3. Canonical State 前后差异

执行只会生成新的 v14，不覆盖历史 State Version：

| 路径 | v13 | 计划 v14 | 原因 |
|---|---:|---:|---|
| `foreshadowings.1.status` | `reinforced` | `abandoned` | D-05 已确认魔法代价属于世界硬规则；现有开放式承诺没有可验证的完成条件，不能伪装成 paid off |
| `foreshadowings.1.reinforce_count` | 11 | 10 | Event #15 从首次强化改为首次铺设 |
| `foreshadowings.2.reinforce_count` | 7 | 6 | Event #24 从首次强化改为首次铺设 |
| `foreshadowings.3.reinforce_count` | 5 | 3 | Event #41 失效，Event #52 从强化改为铺设 |

title、importance、due_from、due_to 及所有非伏笔 Canonical State 路径保持不变。伏笔 #2、#3 的内容状态继续为 `reinforced`。

## 4. 领域投影变化

伏笔投影计划结果：

| 伏笔 | 当前表投影 | 执行后投影 |
|---|---|---|
| #1 魔法代价的痛苦 | `idea / 0 / setup=null / payoff=null` | `abandoned / 10 / setup=第1章(ID 1) / payoff=null` |
| #2 反派的阴影 | `idea / 0 / setup=null / payoff=null` | `reinforced / 6 / setup=第2章(ID 2) / payoff=null` |
| #3 牺牲的代价 | `idea / 0 / setup=null / payoff=null` | `reinforced / 3 / setup=第5章(ID 5) / payoff=null` |

当前统一投影检查还发现人物 #1、#2 与世界实体 #2、#4 的表投影落后于 v13。显式执行会调用统一 `ProjectionRebuilder`，因此这四条投影也会更新为当前 Canonical State；完整前后 JSON 已保存在机器快照。它们不会改变 Canonical State。

## 5. 明确保留的未决项

以下内容无法从现有结构化数据和短 evidence 确定，冻结计划没有把推断写成事实：

1. 伏笔 #1 后续需要另建具有具体结果、兑现窗口和逐字验收条件的新伏笔；现有材料不足以确定具体承诺。
2. 伏笔 #1 第 2～11 章分别存在不同章节证据，无法确定性证明某条只是重复提取，因此全部保留。
3. 伏笔 #2 第 3～9 章事件有不同正文证据，提前强化本身合法；是否文学上重复仍无法确定，因此全部保留。
4. 伏笔 #3 第 7、10、11 章涉及代价与选择，但是否达到独立强化强度属于文学判断，因此全部保留。

## 6. 实际执行结果、幂等与恢复

用户明确批准后，已使用用户 #1 作为操作者执行：

```bash
php artisan foreshadowing:repair-history docs/development/foreshadowing-repair-plans/six-ring-afterglow-v13.json --execute --actor=1
```

执行在单一数据库事务内完成：锁定 Novel、再次运行同一 dry-run 门禁、失效/替换 Event、处理来源 Memory、追加审计事件、从 v1 重放 Active Events、创建 v14、移动 Canonical 指针并重建投影。

已确认的执行结果：

- Canonical State 已从 v13 更新为 v14，checksum 为 `e0367a6534f0650e15d4485f949d22c6163721e4e65f683d8f7f9f4e056e3b15`。
- Event #15、#24、#41、#52 已标记为 `invalidated`；新增替代 Event #99、#101、#104，类型均为 `foreshadowing_planted`。
- 新增审计 Event #100、#102、#103、#105、#106，分别记录三次 correction、一次 invalidation 和一次 Manual Correction；均带冻结计划 hash、操作者 #1 和结果版本 v14。
- 四条旧来源 Memory 已变为 `invalid`；三条替代 Event 各有一条新的 Active Memory。当前共有 22 条 Active 伏笔 Memory。数据库中共有 5 条 Invalid 伏笔 Memory，其中 4 条来自本次修复，另 1 条早于本次修复，不能归因于本计划。
- 伏笔表投影为：#1 `abandoned / 10 / setup=1 / payoff=null`，#2 `reinforced / 6 / setup=2 / payoff=null`，#3 `reinforced / 3 / setup=5 / payoff=null`。
- 人物、世界实体和伏笔投影均无漂移；从完整基线 v1 重放 93 条 Active Event 得到的 checksum 与 v14 完全一致；四个原 Event 没有 Fact 引用。

已重复执行同一命令验证幂等：命令返回“已经执行到 Canonical State v14，未产生重复写入”。复跑前后均为 15 个 State Version、106 个 Story Event、101 条 Memory，Canonical version 均为 v14。

执行后仍保留完整旧 Event 和旧 State Version 进行审计；业务回退不能直接恢复旧指针，应生成反向 correction 计划并再次走 dry-run 和显式执行。第 5 节四项未决语义判断没有在本次修复中自动补写。
