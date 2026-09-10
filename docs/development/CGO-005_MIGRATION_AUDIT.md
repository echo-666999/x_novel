# CGO-005 现有小说迁移审计

> 审计日期：2026-09-10  
> 数据源：本地 PostgreSQL，迁移前后查询及一次授权写入  
> 目标小说：《六环余光》（Novel ID 2）  
> 当前结论：用户已确认保留 v3 文风并只修改基调；v4 已创建并通过验收。

## 1. 已确认的迁移前状态

当前共有 3 个 Bible Version，且只有 1 个 `current`：

| Version | ID | 状态 | 创建时间 | 基调 | 视角 | 时态 | Style Profile | 内容 SHA-256 |
|---|---:|---|---|---|---|---|---|---|
| v1 | 1 | superseded | 2026-09-08 17:59:18 +08:00 | 严肃且充满希望 | 第三人称有限视角 | 过去时 | `null` | `4f15cfc85d5e13ff9292c98ddc83c4e40688ca82d3e0050002140df5b8f2fcda` |
| v2 | 2 | superseded | 2026-09-10 16:55:25 +08:00 | 严肃且充满希望 | 第三人称有限视角 | 过去时 | 完整 | `ea896e66e555cb77c4f21ac9c24933499d90f2214a3489af823b17a6458fe6d0` |
| v3 | 3 | current | 2026-09-10 17:39:34 +08:00 | 严肃且充满希望 | 第一人称 | 过去时 | 完整且通过当前结构校验 | `b0c59e1ed3856e79c04ba743807ac377091b408907dfa76d63622a2f940cb4db` |

内容哈希覆盖 `logline`、`themes`、`tone`、`pov`、`tense`、`taboos`、`hard_constraints`、`ending_contract` 和 `style_profile`，不包含状态与时间戳。

当前 v3 的 `style_profile`：

```json
{
  "subgenre": "剑与魔法",
  "target_platform": "fanqie",
  "primary_style": "accessible_brisk",
  "secondary_styles": ["light_humorous"],
  "language_era": "modern_spoken",
  "pacing": "balanced",
  "parameters": {
    "ornateness": 2,
    "dialogue_ratio": 3,
    "description_density": 2,
    "psychology_density": 2,
    "humor_level": 2,
    "literary_level": 1
  }
}
```

## 2. 旧 Editorial 原值

`novels.settings.editorial` 的 SHA-256 为：

```text
4c9f779fe383e69cdcb57b5838210b4685b3ac7952e2ed63b1924e5ca2010715
```

原值为：

```json
{
  "subgenre": null,
  "target_platform": "general",
  "story_tone": "passionate",
  "primary_style": "light_humorous",
  "secondary_styles": ["accessible_brisk"],
  "language_era": "modern_spoken",
  "pacing": "balanced",
  "narrative_pov": "first_person",
  "style_parameters": []
}
```

当前 `NarrativeStyleProfile` 对空 `style_parameters` 的确定性运行时展开结果来自 `light_humorous` Preset：

```json
{
  "ornateness": 2,
  "dialogue_ratio": 4,
  "description_density": 2,
  "psychology_density": 2,
  "humor_level": 4,
  "literary_level": 2
}
```

这组数值是现有运行时代码的解析结果，不是数据库中已显式保存的 Editorial 原值。

## 3. 与 CGO-005 目标的差异

| 字段 | 已确认目标或旧 Editorial | Current Bible v3 | 结论 |
|---|---|---|---|
| 基调 | 热血 | 严肃且充满希望 | 不一致 |
| 视角 | 第一人称 | 第一人称 | 一致 |
| 时态 | 过去时 | 过去时 | 一致 |
| 子题材 | `null` | 剑与魔法 | 不一致 |
| 目标平台 | `general` | `fanqie` | 不一致 |
| 主文风 | `light_humorous` | `accessible_brisk` | 不一致 |
| 辅助文风 | `accessible_brisk` | `light_humorous` | 不一致 |
| 语言时代感 | `modern_spoken` | `modern_spoken` | 一致 |
| 节奏 | `balanced` | `balanced` | 一致 |
| 高级参数 | Editorial 未显式设置 | v3 显式保存 `2/3/2/2/2/1` | 来源不同 |

因此，v3 虽然是完整合法的 Current Bible，但不能按现有任务文本认定为已经完成 CGO-005 迁移。

## 4. 执行前发现的冲突

1. v3 晚于旧 Editorial 数据，并包含与旧 Editorial 明显不同的完整文风选择；现有证据无法确认这些值是用户有意更新，还是此前操作产生的临时版本。
2. 按 CGO-005 原文复制旧 Editorial 会覆盖 v3 的“剑与魔法 / 番茄小说 / 通俗爽快主文风”等较新值。
3. `style_parameters` 在旧 Editorial 中为空。若迁移为完整 Bible，需要决定是采用现有运行时的 Preset 展开值，还是保留 v3 已显式保存的参数。
4. CGO-004 迁移助手会把完整合法的 v3 视为已经迁移并幂等返回，不会创建 v4。直接调用该入口不能实现当前任务文本要求。

## 5. 用户确认的处理方案

用户于 2026-09-10 确认：把较新的 v3 视为当前作品定位与文风选择，创建 v4 时只将基调改为“热血”，并保留 v3 的第一人称、过去时和完整 `style_profile`。

因此，本次执行不按旧 Editorial 覆盖 v3 的子题材、平台、主辅文风和高级参数。这是针对执行前实际数据状态作出的明确决策，取代原任务中“Editorial 独有字段按原值复制”的要求。

## 6. 执行结果

迁移前快照记录于 2026-09-10 18:05:37 +08:00：

- Current Bible：v3（ID 3），状态 `current`。
- v3 内容 SHA-256：`b0c59e1ed3856e79c04ba743807ac377091b408907dfa76d63622a2f940cb4db`。
- Bible 数量：3；`current` 状态数量：1。
- 旧 Editorial 原值与第 2 节一致。

通过现有 `CreateBibleVersionAction` 创建了 v4（ID 4）。该入口在事务中锁定 Novel、将 v3 降级为 `superseded`，并创建唯一的 `current` v4。

迁移后核对记录于 2026-09-10 18:06:08 +08:00：

| 检查项 | 结果 |
|---|---|
| v4 基线 | 热血 / 第一人称 / 过去时 |
| v4 状态 | `current` |
| v3 状态 | `superseded` |
| v4 与 v3 内容对比 | 除 `tone` 外完全一致 |
| v4 内容 SHA-256 | `2058e33c67896000dd048e63165ca5d05dfd3cfd81765d267b237fe6eebc4fa8` |
| v3 内容 SHA-256 | `b0c59e1ed3856e79c04ba743807ac377091b408907dfa76d63622a2f940cb4db`，未变化 |
| v1 内容 SHA-256 | `4f15cfc85d5e13ff9292c98ddc83c4e40688ca82d3e0050002140df5b8f2fcda`，未变化 |
| 旧 Editorial | 未修改、未清理 |
| Current Bible 数量 | 1 |

再次调用 `MigrateEditorialToBibleAction` 前后，Bible 数量均为 4；`canMigrate()` 返回 `false`，`execute()` 幂等返回 v4（ID 4），没有创建 v5。

## 7. 范围边界核对

迁移前后的关联数据计数一致：

```text
Canonical Chapters: 4
Story Events: 41
Story State Versions: 7
Memories: 41
```

未调用 AI，未修改 Canonical Chapter、Story Event、Story State Version 或 Memory。

未执行数据库备份，因此不声称存在本次操作的独立备份文件。

## 8. 测试结果

实际运行：

```text
php artisan test tests/Feature/NovelBibleTest.php tests/Feature/MigrateEditorialToBibleActionTest.php tests/Feature/Filament/NovelBiblePageTest.php
```

结果：40 个测试，39 个通过，238 个断言，1 个 PostgreSQL 专属用例在 SQLite 测试环境跳过。
