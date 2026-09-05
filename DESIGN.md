---
version: "1.0"
name: XNovel Workbench Design System
description: "XNovel 的 Laravel + Filament v5 单用户 AI 长篇小说工作台规范：专业、克制、高信息密度、适合长时间使用，Dark Mode 优先并完整支持 Light Mode。"

colors:
  primary: "#6D73E6"
  primary-hover: "#7D83EE"
  primary-active: "#5D63D3"
  primary-subtle-dark: "#20213D"
  primary-subtle-light: "#EEEEFF"
  on-primary: "#FFFFFF"
  dark-canvas: "#0B0C0F"
  dark-sidebar: "#0E0F13"
  dark-surface-1: "#111318"
  dark-surface-2: "#171920"
  dark-surface-3: "#1D2028"
  dark-border: "#282B34"
  dark-border-strong: "#383C48"
  dark-text: "#F2F3F5"
  dark-text-muted: "#B7BBC5"
  dark-text-subtle: "#858A96"
  light-canvas: "#F6F7F9"
  light-sidebar: "#F1F2F5"
  light-surface-1: "#FFFFFF"
  light-surface-2: "#F8F9FB"
  light-surface-3: "#EEF0F4"
  light-border: "#E1E3E8"
  light-border-strong: "#C9CDD6"
  light-text: "#17191F"
  light-text-muted: "#4F5561"
  light-text-subtle: "#747B88"
  success: "#35A66F"
  info: "#4E83D1"
  warning: "#C58A2B"
  danger: "#D45A64"
  neutral: "#7B818D"

typography:
  ui-family: "Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
  reading-family: "ui-serif, Charter, 'Noto Serif SC', 'Songti SC', serif"
  mono-family: "'JetBrains Mono', 'SFMono-Regular', Consolas, monospace"
  page-title: "24px / 1.25 / 600 / -0.02em"
  section-title: "16px / 1.35 / 600 / -0.01em"
  card-title: "14px / 1.4 / 600 / 0"
  body: "14px / 1.55 / 400 / 0"
  body-small: "13px / 1.45 / 400 / 0"
  caption: "12px / 1.4 / 400 / 0.01em"
  control: "13px / 1.2 / 500 / 0"
  metric: "24px / 1.15 / 600 / -0.02em"
  prose: "16px / 1.85 / 400 / 0.01em"
  mono: "12px / 1.5 / 400 / 0"

spacing:
  unit: 4px
  xs: 4px
  sm: 8px
  md: 12px
  lg: 16px
  xl: 24px
  xxl: 32px

radius:
  xs: 4px
  sm: 6px
  md: 8px
  lg: 10px
  full: 9999px

density:
  sidebar-width: 248px
  topbar-height: 52px
  control-height-compact: 32px
  control-height-default: 36px
  row-height-compact: 36px
  row-height-default: 44px
  page-padding: "20px 24px"
  panel-padding: 16px
  inspector-width: "420px–560px"
---

# XNovel Design System

## 1. 设计定位

XNovel 是单用户 AI 长篇小说生产工作台，不是营销网站，也不是通用企业 CRUD 后台。界面应让用户快速判断：小说当前状态、生成流水线位置与阻塞原因、草稿与正式事实是否一致，以及下一步最安全的操作。

视觉语言应安静、精确、可信。内容、状态与证据是主角，品牌装饰退居其次。禁止复制 Linear 或其他第三方的品牌标识、专有字体、营销结构与文案。

## 2. 核心原则

### 2.1 产品界面优先

- 页面从上下文、标题、状态和操作开始，不从口号或大幅插画开始。
- 不使用 Marketing Hero、Pricing、Customer Logo、Testimonials、Marketing CTA 或 Marketing Footer。
- 工作台标题最大 24px；每个模块都必须支持判断、定位、阅读或操作。

### 2.2 高密度但不拥挤

- 采用 4px 基础网格，表格、筛选器和工具栏默认紧凑。
- 密度来自对齐、分组和稳定列宽，不来自牺牲可读性。
- 长文本、Diff 和 JSON 使用比表格更宽松的行高与间距。

### 2.3 长时间使用不疲劳

- 深色画布避免纯黑，浅色画布避免纯白铺满全屏。
- 仅标题和关键值使用最强文本色；大面积不使用饱和色。
- 阴影仅用于菜单、Popover、Modal 与 SlideOver；静态内容依靠 surface 和 1px hairline 分层。
- 不使用装饰性渐变、玻璃拟态、光晕或大面积模糊。

### 2.4 状态必须明确

- Draft、Candidate、Canonical 必须显示文字标签，不能只靠颜色。
- Review 与 Canonical Commit 是不可跳过的视觉关口。
- 错误展示 `error_code`、阶段、是否可重试和推荐动作。
- 危险操作显示影响范围、前置条件，并要求必要的 reason。

## 3. 品牌、主题与语义

### 3.1 单一 Primary Accent

XNovel 使用靛蓝紫 `#6D73E6` 作为唯一 primary accent，仅用于主要操作、当前导航、选中项、焦点环、链接与当前 Pipeline 节点。Primary 不用于大面积卡片背景或装饰。

当前 Panel 若使用其他 primary 色，实现视觉基线任务时应统一迁移到本规范；本文档不修改 Provider。

### 3.2 Surface hierarchy

Dark Mode 是默认基准：Canvas → Surface 1 → Surface 2 → Surface 3。Light Mode 使用相同信息结构与对应 token，不是降级或简单反相版本。

| 层级 | Dark | Light | 用途 |
|---|---|---|---|
| Canvas | `dark-canvas` | `light-canvas` | 页面底色 |
| Surface 1 | `dark-surface-1` | `light-surface-1` | 主卡片、表格、正文 |
| Surface 2 | `dark-surface-2` | `light-surface-2` | 工具栏、嵌套区、Hover |
| Surface 3 | `dark-surface-3` | `light-surface-3` | 选中区、代码块、浮层内容 |
| Border | `dark-border` | `light-border` | 默认 hairline |
| Strong | `dark-border-strong` | `light-border-strong` | 聚焦与重要边界 |

### 3.3 语义颜色

| 语义 | 状态示例 |
|---|---|
| Neutral | planned、paused、void、candidate、pending |
| Info | generating、running、context、embedding |
| Warning | review、rewrite、needs_attention、due |
| Danger | blocked、failed、hard conflict、hard budget limit |
| Success | pass、canonical、completed、ready |

Badge 使用低饱和背景、语义色文字或细边框。颜色必须配合文字或图标，不能成为唯一信息来源。同一状态在所有页面使用相同名称与颜色。

## 4. 排版

- UI 使用系统无衬线字体，不依赖专有字体。
- 中文章节正文可使用 `reading-family`；导航、表格和表单始终使用 UI 字体。
- 页面标题 24px，Section 16px，正文 14px，辅助信息 12–13px。
- 章节正文默认 16px、1.85 行高，行宽约 68–82 个中文字符。
- Run ID、checksum、state version、token、费用和 JSON path 使用 Mono。
- 数字列使用 tabular numerals 并右对齐。
- 禁止营销式超大字号与夸张负字距。

## 5. 导航与布局

### 5.1 顶级导航

```text
Dashboard
Novels
Generation
Review
Memory
Settings
```

不为每张表建立一级 Resource。侧栏约 248px，可折叠；当前项使用 surface、primary 细边或小图标强调，避免整行高饱和填充。计数仅用于待处理 Review、Failure 或 Memory 异常。

### 5.2 页面骨架

```text
Breadcrumb / Context
Page title + compact metadata + primary action
Local tabs / filters（可选）
Main content
Inspector SlideOver（按需）
```

当前 Panel 的 Full Width 适合数据密集页面。Dashboard 可控制在舒适宽度；表格、Pipeline、Diff、Inspector 和长文本应充分利用宽屏。

### 5.3 Novel Workspace

Workspace 顶部持续显示小说标题、状态、当前章节、当前 State Version 与生成状态。局部导航建议：

```text
Overview · Bible · Characters · World · Planning · Chapters
Foreshadowing · Generation · Story State · Memory
```

全局 Review 与 Memory 保留跨小说入口；从 Workspace 进入时自动带当前 novel filter。

### 5.4 响应式

- `>= 1280px`：完整侧栏；主区 + 可选 Inspector 双栏。
- `1024–1279px`：侧栏可收起；Inspector 使用 SlideOver。
- `< 1024px`：指标两列；复杂表格横向滚动并冻结关键列。
- `< 768px`：单列；次要列折入详情；触控目标至少 44px。
- 桌面生产力优先，不为移动端牺牲桌面信息密度。

## 6. Surface、圆角与阴影

- 默认容器使用 Surface 1 + 1px hairline；工具栏、筛选区、代码块与选中行使用 Surface 2/3。
- 卡片圆角 8–10px，输入与按钮 6–8px，Badge 可用 full radius。
- 避免“每段内容一张悬浮卡片”；连续数据优先统一容器和分隔线。
- 不使用装饰性渐变、玻璃拟态、发光边缘或大面积阴影。

## 7. 通用组件

### 7.1 Buttons 与 Actions

- Primary：每区最多一个，用于当前最重要且安全的下一步。
- Secondary：查看、重试、导出、打开 Inspector。
- Tertiary：低频辅助操作。
- Danger：Reject、Rollback、Emergency Stop 等高风险动作。
- 默认高度 36px，紧凑工具栏 32px；使用稳定动词：Generate、Retry、Rewrite、Commit、Pause、Resume。
- 未实现功能显示 disabled 与原因，不显示可点击假按钮。

### 7.2 Tables

- 默认行高 36–44px，表头可 sticky。
- 左侧放对象识别信息，右侧放状态、时间、数字和动作。
- 只展示影响判断的列，其他字段进入 SlideOver。
- 默认排序：异常优先、待处理优先、最近更新优先。
- 行点击用于查看，显式 Action 用于改变状态。
- 长 ID 截断并可复制；数字和成本右对齐。

### 7.3 Status Badge

- 高 20–22px，12px 字号，内边距 4px 8px。
- 始终显示文字，可辅以状态点或图标。
- Canonical 与 Draft 标签必须持续可见，不能依赖所在 Tab 推断。

### 7.4 Forms

- 标签在字段上方；帮助与错误信息就地展示。
- 按业务语义分 Section，不按数据库列机械排列。
- JSONB 优先结构化表单，原始 JSON 仅作高级检查视图。
- 长文本编辑区提供专注模式、字数、保存状态和版本信息。
- Locked Fact 禁止普通 inline edit，必须通过 Lock/Unlock/Supersede Action。

### 7.5 SlideOver / Modal / Full Page

- SlideOver：Generation Run、Review Finding、Story Event、Artifact、Memory、Context Snapshot。
- Modal：短确认、单一表单、危险操作原因。
- Full Page：Chapter Detail、Planning Preview、正文阅读、复杂 Diff、Story State 与 Memory 深度检查。
- SlideOver 默认宽 420–560px；复杂 JSON/Diff 可切全页。

### 7.6 Empty、Loading 与 Error

- Empty State 说明为何为空，并提供唯一推荐动作，不使用大插画。
- Loading 优先 skeleton；运行阶段同时显示状态与已耗时。
- Error 显示错误码、失败阶段、时间、可重试性与推荐动作。
- 权威数据加载失败时不得以空列表伪装成功。

### 7.7 Long Text、Diff 与 Evidence

- 正文阅读区限制行宽并增加行高，与管理 chrome 分离。
- Diff 支持双栏或 unified，可按段落/Scene 折叠，增删色低饱和。
- Evidence 包含来源章节、Scene 或 Fact，并支持跳转。
- Findings 与正文可联动高亮，但不永久改变正文底色。

## 8. 重点页面

### 8.1 Dashboard

目标：10 秒内决定今天先处理什么。

首屏顺序：

1. Current Novel / Chapter / Volume 与 Generation Status；
2. Needs Attention、Last Failure、Critical Foreshadowing、Closure Debt；
3. Total Words、Today Tokens/Cost、Review Pass Rate、Rewrite Rate；
4. Recent Generation 与 Queue/Horizon Status。

指标卡紧凑；异常列表优先于趋势图，不放无行动价值的装饰图表。

### 8.2 Novel Workspace

目标：成为单部小说的主要操作台，减少跨 Resource 跳转。

- Header 持续显示 Novel 状态、进度、当前指针、State Version。
- Overview 使用“当前状态 / 风险 / 下一步动作”结构。
- Bible、Characters、World、Foreshadowing 是上下文资产区。
- Planning、Chapters、Generation 是生产区；Story State、Memory 是事实与诊断区。
- Generate Next Chapter、Auto Generate、Pause、Resume 按状态互斥出现。

### 8.3 Planning

目标：生成前快速确认“这一章为什么存在”。

推荐三栏：左侧 Volume / Arc / Chapter 层级与进度；中间 Chapter Function、Arc Contribution、Reader Promise、POV、Tone、Time Anchor；右侧 Due Foreshadowings、Required Facts、Forbidden Conflicts、Validation Findings。

Scene Plan 纵向展示 `goal → conflict → turn → outcome`。Blocked Finding 固定在操作区附近；Plan 未通过确定性校验时禁止进入 Generation。

### 8.4 Chapters

列表核心列：Sequence、Title、Status、Words、Review、Cost、State Version、Updated。冻结 Sequence/Title，状态和数值列保持窄且稳定。

Chapter Detail Tabs：

```text
Overview · Plan · Scenes · Draft · Events · Review · State Changes · Runs
```

Draft 与 Canonical 正文使用明确标签和不同元数据条；Artifact 不覆盖，只按版本切换。

### 8.5 Generation Pipeline

标准阶段：

```text
Plan → Context → Scenes → Assembly → Events → Review
     → Rewrite（必要时）→ Commit → Memory
```

- 桌面端使用紧凑水平 Timeline；Scenes 节点展开纵向子步骤。
- 每个 Stage 显示 status、duration、model、tokens、cost、prompt version、state version。
- 当前节点 primary，完成 success，等待 neutral，异常 warning/danger。
- 点击 Stage 打开 Run/Artifact SlideOver，不离开 Chapter 上下文。
- Pause 时说明：可完成已发请求，但禁止新 Stage 与 Commit。

### 8.6 Review Inbox

Review 是 Inbox，不是通用 CRUD。

- 默认只显示 `NEEDS_ATTENTION` 与 `BLOCK`，可切换全部。
- 行信息：Novel、Chapter、Decision、Top Finding、Score、Age。
- 详情先显示 Hard Conflict、Evidence、相关 Fact/State、7 维评分与 Draft Diff。
- 操作：Rewrite Scene、Rewrite Chapter、Manual Edit、Override、Reject。
- Commit 仅在 PASS 且前置条件满足时显示；Override 必须填写 reason。

### 8.7 Story State Inspector

固定领域：Characters、Relationships、Locations、Items、Timeline、World、Open Threads、Foreshadowings、Reader Promises、Facts。

- 顶部显示 Current State Version、来源 Canonical Chapter、checksum、created_at。
- 左侧领域导航，中间可读结构，右侧 Evidence/Source Inspector。
- 支持 `vN → vN+1` 查看 changed paths、before、after。
- Canonical 默认只读；修正通过 Manual Correction 创建 correction event 与新版本。
- Locked Fact 同时使用锁图标、标签和强边框。
- 不默认使用关系图；优先表格、时间线和结构化详情。

### 8.8 Memory Inspector

目标是调试检索选择，不是把 Memory 当普通内容库。

查询头显示 Query、Novel、entity/type/status filters、candidate_k、final_k、token budget。结果列：Selected、Memory、Similarity、Salience、Final Score、Source Chapter、Source Event、Reason。

- Selected / Rejected 可分区或过滤；Rejected 必须说明原因。
- Invalid 默认排除，但可显式查看。
- 详情显示来源链与 embedding model/status。
- Context Inspector 按 L0–L4 显示 Token Allocation、Truncated Sections、Selected Memory。
- Retry Embedding、Disable Memory 是显式 Action；Draft Memory 不得混入正式检索。

### 8.9 Settings

Sections：

```text
AI · Generation · Review · Memory · Budget
```

- 全局技术配置注明来自 `.env` / `config`，敏感值不回显完整内容。
- Novel 级策略显示继承与覆盖来源。
- Auto Commit、Hard Cost Limit、Rewrite Attempts 显示影响说明。
- Test Connection 显示 provider、model、latency 与结果，不展示 API Key。
- 页面底部只显示保存状态、最后更新时间与必要操作，不放营销 CTA。

## 9. 可访问性与交互

- 正文对比度至少 4.5:1；大字和非文本控件至少 3:1。
- 所有交互提供清晰 `focus-visible`，使用 primary ring。
- 支持键盘操作；图标按钮必须有 tooltip 与可访问名称。
- 状态变化使用 Filament Notification，同时在页面真实状态中持久体现。
- 动效 120–180ms，仅用于 Hover、展开与 SlideOver，并遵循 reduced motion。
- 时间、token、cost、版本号使用全局一致格式。

## 10. 图标、图表与装饰

- 使用 Filament/Heroicons 体系，不混合图标风格，不创建仿第三方 Logo。
- 图表仅用于趋势、分布、阶段耗时等短表格无法更清楚表达的信息。
- Pipeline 使用阶段序列，不使用装饰插画。
- 不使用照片、3D 资产、噪点背景、光晕、彩色网格或无意义插图。

## 11. Filament v5 实施映射

- 优先复用 Filament 的 Color、Table、Form、Section、Tabs、Badge、Action、Notification、SlideOver 和 Widget。
- 通过 Panel theme 与语义 token 统一 Dark/Light；不要在 Resource 内散落十六进制颜色。
- Status 映射集中维护，保证 Chapter、Run、Review、Memory 一致。
- 表格 eager load，避免为视觉丰富制造 N+1。
- Page/Resource 只负责展示、表单和触发 Action，不直接修改 Canonical Story State。
- Commit、Rollback、Manual Correction 调用领域 Service/Action，并展示前置校验。
- 二级检查优先 SlideOver，避免为每个 Artifact 或 Run 建独立页面。

## 12. Do / Don't

### Do

- 用单一 primary 表达选择、焦点和首要操作。
- 用 surface ladder 与 hairline 建立层级。
- 让异常、证据、版本与下一步动作靠近出现。
- 为表格、Inspector、Pipeline、Review、长文本分别选择合适密度。
- 在 Dark 和 Light 两种主题下逐页验证。
- 明确显示 Draft / Candidate / Canonical 与 State Version。

### Don't

- 不复制 Linear 或其他第三方的品牌、Logo、专有字体或营销结构。
- 不使用 Hero、客户 Logo 墙、Testimonials、Pricing、营销 CTA、营销 Footer。
- 不使用多套强调色、彩虹图表或高饱和大底色。
- 不靠渐变、阴影和超大圆角制造“高级感”。
- 不为每张表创建顶级导航，不把 Review 做成普通 CRUD。
- 不允许 Draft 看起来像 Canonical，不隐藏 Commit 安全前置条件。

## 13. 设计验收清单

- [ ] 页面对应明确的 PRD 工作流，而非通用后台模板。
- [ ] Dark Mode 与 Light Mode 均通过视觉检查。
- [ ] 首屏能识别对象、状态、风险和主要操作。
- [ ] 每区最多一个 Primary Action。
- [ ] Surface 层级连续，静态卡片无装饰阴影。
- [ ] 表格列、排序与 Filter 支持真实决策。
- [ ] 状态不只依赖颜色，Draft/Canonical 清晰区分。
- [ ] Empty、Loading、Error、Disabled 状态完整。
- [ ] 键盘焦点、对比度、Tooltip 与触控目标合格。
- [ ] SlideOver 与 Full Page 的选择符合内容复杂度。
- [ ] 危险操作显示影响并要求必要原因。
- [ ] 页面未绕过 Review、Canonical Commit 或领域 Service。

## 14. 明确排除的营销模式

```text
Marketing Hero
Pricing Table
Customer Logo Wall
Testimonials
Promotional CTA Banner
Marketing Footer
Product Screenshot Showcase
```

XNovel 的视觉价值来自可信的状态、清楚的证据、稳定的密度与可恢复的工作流，而不是营销陈列。
