# 《六环余光》大纲迁移 Dry Run

> 本报告只读取数据库并写入本地文件；没有修改 Outline、Chapter、Story Event、Story State、Memory 或其他业务数据，也没有调用 AI Provider。

## 冻结边界

- Novel：六环余光 (#2)
- Expected State Version：14
- Expected State Checksum：`e0367a6534f0650e15d4485f949d22c6163721e4e65f683d8f7f9f4e056e3b15`
- Current Outline ID：null
- Chapter 12 Artifact ID：241
- Chapter 12 Artifact Checksum：`316ae8597bfd2658adf34441a3b1023d76df242448184777e53b4037ba640862`
- Plan Hash：`47d78d86c3c0a0f0b10a8d9a8d8334f1c37d15e1d718e50af4a6618c0bb2c533`

## 历史节点 Evidence 候选

| 节点 | 候选状态 | Canonical Chapters | 逐字 Evidence | 不确定项 |
|---|---|---|---|---|
| 与苏璃结识 (`academy-meet-su-li`) | partial | #1, #2, #3 | Ch.1「苏璃抱着两本厚得能砸晕人的魔法教材，站在卖面饼的摊子旁。」<br>Ch.1「苏璃很快察觉到我的异样，把书放到旁边的木箱上，伸手抓住我的手腕。」<br>Ch.1「”<br><br>苏璃睁开眼：“为什么？」<br>Ch.2「”<br><br>苏璃的声音从门口传来。」<br>Ch.2「”<br><br>苏璃走到床边，放下记录板。」<br>Ch.2「苏璃皱着眉扶住我的手臂，却没有阻止。」<br>Ch.3「”苏璃按住我的肩膀。」<br>Ch.3「苏璃翻开记录册：“施法前，掌心裂纹是什么颜色？」 | 需要确认是否存在正式见面及明确的后续联系，不能因两人共同出现就判定完成。<br>命中词句只支持 partial 候选，不能自动证明全部验收条件已经满足。 |
| 教授指导 (`academy-professor-training`) | not_started | — | — | 需要确认教授正式登场、先考察、指出问题、带代价训练及可验证提升。<br>关键词检索未找到逐字 Evidence；这不能证明剧情从未发生，仍需人工阅读确认。 |
| 学院考核 (`academy-examination`) | partial | #1 | Ch.1「没有掌声，也没有谁宣布我通过了考核，只有掌心的伤提醒我，刚才确实做过一个选择。」 | 需要确认考核结果及其对学院成长阶段的推进。<br>命中词句只支持 partial 候选，不能自动证明全部验收条件已经满足。 |
| 购物获得修炼物品 (`academy-shopping-cultivation-item`) | not_started | — | — | 物品名称、能力、代价和限制尚未由用户确认。<br>关键词检索未找到逐字 Evidence；这不能证明剧情从未发生，仍需人工阅读确认。 |
| 接受学院任务 (`field-accept-academy-mission`) | not_started | — | — | 需要确认任务由学院正式发布且林墨明确接受。<br>关键词检索未找到逐字 Evidence；这不能证明剧情从未发生，仍需人工阅读确认。 |
| 途中拔刀相助 (`field-help-bullied-stranger`) | not_started | — | — | 需要确认发生在任务途中，且确有弱者受到欺凌。<br>关键词检索未找到逐字 Evidence；这不能证明剧情从未发生，仍需人工阅读确认。 |
| 与其他学院学员冲突 (`field-conflict-other-academy`) | not_started | — | — | 其他学院名称、涉事学员与冲突原因尚未确认。<br>关键词检索未找到逐字 Evidence；这不能证明剧情从未发生，仍需人工阅读确认。 |
| 双方互斗 (`field-fight-other-academy`) | not_started | — | — | 需要确认冲突确已升级为双方互斗，而不是普通争执。<br>关键词检索未找到逐字 Evidence；这不能证明剧情从未发生，仍需人工阅读确认。 |
| 经历磨难并完成任务 (`field-complete-first-mission`) | not_started | — | — | 需要确认磨难、任务目标和完成结果均有正式正文 Evidence。<br>关键词检索未找到逐字 Evidence；这不能证明剧情从未发生，仍需人工阅读确认。 |

候选状态不等于正式完成记录。任何 `completed` Baseline Completion 都必须由用户审核逐字 Evidence 后确认；本报告不会创建 Story Event。

## Summary 与 Current Beat

- 缺失 Canonical Summary 的章节：1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11
- 当前系统可解析 Beat：无；当前没有 Current Outline。
- 若用户确认 `academy-meet-su-li` 已完成，建议下一 Beat：`academy-professor-training`。
- 若不确认，下一 Beat 仍为：`academy-meet-su-li`。

## 第 12 章两种方案

### A. 保留第 12 章现有来源链，新 Outline 从第 13 章生效

- 第 12 章保持 review；不覆盖现有 Plan、Scene、Run、Artifact、Review 或 Usage。
- 先按现有来源链处理第 12 章，再让新 Outline 从第 13 章开始约束 Planner。
- 第 12 章不会自动补写新 Outline 的 Primary Beat 来源；需人工确认其内容与新大纲是否兼容。

### B. 废弃第 12 章当前 Draft，从 Canonical Chapter 11 和新 Outline 重建

- 采用新 Outline 后，将当前 Draft/Ready Plan 标记为 superseded，并清空 Scene current_artifact_id。
- 第 12 章进入 void 后从 Chapter Planning 重新开始；旧 Run、Artifact、Review 和 Usage 保留审计。
- 重新生成会产生新的 Provider 调用和费用；本次 Dry Run 不派发 Job。

## 候选教授与未来实体

- 教授 Candidate：`character-professor-mentor`，名称仍为“待用户命名的教授”；本次不会创建 Character。
- `item-cultivation-accelerator` (item)：外出购物时意外获得能够加快魔法修行的神奇物品。 本次不会创建 World Entity。
- `location-first-academy-mission` (location)：第一次学院任务的目标地点。 本次不会创建 World Entity。
- `organization-other-academy` (organization)：在任务地点与林墨一方发生矛盾的其他学院。 本次不会创建 World Entity。

## OUT-010 前仍需决定

- 确认 academy-meet-su-li 的历史 Baseline Completion 及逐字 Evidence。
- 选择第 12 章保留现有来源链，或废弃并按新 Outline 重建。
- 确认教授姓名、能力、动机限制及是否与现有角色重复。
- 确认未来物品、任务地点和其他学院的 Candidate 细节。
- 确认只先采用两个 Volume，或继续提供其余 Volume。
