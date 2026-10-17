# XNR-034 视觉基础与隔离预览

日期：2026-10-17。状态：IN_PROGRESS（实现及主要检查完成，浏览器缩放/减少动态效果实测待补）。依赖 XNR-006。对应 A35/A39 的基础部分，不能代替 XNR-035 全页验收。

## 已交付

复用 Filament 5.10.2 / Livewire 4.4.7 / Tailwind 4.3.3 / Vite 8.2.2 的既有主题入口。两主题 token、中文本机字体、间距、圆角、焦点、边界、响应式骨架和阅读排版已实现。Filament 默认深色，继续使用原有主题偏好机制；没有新增组件框架、远程字体服务或偏好表。

共用 Blade 呈现包括标题、状态、任务面板、费用摘要、问题摘要、证据 disclosure 和空/加载/失败反馈。GenerationPresentation 只翻译领域状态，未知状态显示“状态尚未确认”。金额区分实际/估计/待确认，预警文案明确继续，不把费用当完成进度。

四页预览为创建、工作台、阅读、异常；所有作品、进度、费用、时间均有示例标记。创作、暂停、AI 修复及人工提交按钮禁用并说明原因；有效交互仅有页间导航、主题、阅读偏好和 disclosure。偏好只保存在浏览器 xn-display-v1，不改变故事或费用策略。

`php artisan design:preview` 仅在 local/testing 构建静态 HTML 到 `storage/app/private/design-preview/`；无生产路由、无小说数据查询、无模型调用。预览不代表业务功能完成。使用仅绑定 127.0.0.1 的静态服务器检查，不通过现有小说页面验收。

## 实际证据

浏览器：Google Chrome 155.0.8059.80。已完成：

- 四页 × 深浅两主题 × 1440×900、1024×768、768×1024、390×844、320×800，共 40 组 CSS 视口检查；所有 document.scrollWidth 等于视口宽度，无全页横向溢出。[测量 JSON](assets/R01/responsive.json)。模拟视口不是真机验证。
- 1440 / 390 的两主题 16 张实际截图，见下表。
- 主题切换及重载后保留；阅读字体选择、18→22px、行高 1.9→2.1 修改后刷新仍保留，22px 实测行高 46.2px，无溢出；之后恢复默认显示值。
- Enter 展开阅读偏好；从文本框 Tab 到“目标篇幅”，实际焦点为 target，2px 实线外环、2px offset；深色焦点颜色 rgb(165,180,252)，[实际焦点截图](assets/R01/create-keyboard-focus.jpg)。
- 修复 Filament 高优先级按钮规则覆盖 token 的问题后，稳定主题下复测颜色（避免把 75ms 主题过渡中的临时颜色当最终值）。[对比度 JSON](assets/R01/contrast.json)。
- 不可信错误文本转义、未知状态说明与生产环境拒绝构建夹具有自动测试。

| 实际组合 | 浅色 | 深色 |
| --- | --- | --- |
| 正文 / 主面板 | 17.57:1 | 16.74:1 |
| 帮助文字 / 主面板 | 7.49:1 | 9.67:1 |
| 主按钮文字 / 背景 | 6.29:1 | 8.92:1 |
| 运行状态 | 8.01:1 | 8.46:1 |
| 预警文字 | 6.84:1 | 10.14:1 |
| 输入边界 / 输入背景 | 4.26:1 | 5.37:1 |
| 禁用按钮文字 | 6.56:1 | 8.47:1 |
| 禁用控件边界 | 3.73:1 | 4.71:1 |

装饰面板 hairline 不是控件边界，不作为 3:1 达标证据。上述是已测组合，不代表全部页面 hover/active/所有焦点相邻颜色均已验收。

| 页面 | 桌面浅 / 深 | 手机浅 / 深 |
| --- | --- | --- |
| 创建 | [浅](assets/R01/create-light-1440.jpg) / [深](assets/R01/create-dark-1440.jpg) | [浅](assets/R01/create-light-390.jpg) / [深](assets/R01/create-dark-390.jpg) |
| 工作台 | [浅](assets/R01/workbench-light-1440.jpg) / [深](assets/R01/workbench-dark-1440.jpg) | [浅](assets/R01/workbench-light-390.jpg) / [深](assets/R01/workbench-dark-390.jpg) |
| 阅读 | [浅](assets/R01/reader-light-1440.jpg) / [深](assets/R01/reader-dark-1440.jpg) | [浅](assets/R01/reader-light-390.jpg) / [深](assets/R01/reader-dark-390.jpg) |
| 异常 | [浅](assets/R01/issues-light-1440.jpg) / [深](assets/R01/issues-dark-1440.jpg) | [浅](assets/R01/issues-light-390.jpg) / [深](assets/R01/issues-dark-390.jpg) |

## 未完成检查与阻塞

浏览器原生控制在尝试检查页面缩放时超时，之后原标签调试连接失效。按工具文档重建静态夹具标签后，DOM/键盘焦点可重新检查，但缩放按键没有可确认的尺寸变化。不能把按键发送成功当作 200%/400% 实测通过。尚需补：实际页面/字体缩放、系统或浏览器 reduced-motion 模式下行为、必要焦点组合测量。CSS 已有 prefers-reduced-motion 规则，但静态规则不是实际启用后的证据。因此任务暂不标 DONE，R01 整体验收未关闭。

Safari/WebKit、Firefox、真机及全部业务操作未验收；全页交互矩阵归 XNR-035。既有小说列表的浏览器读取被自动审批拒绝，理由是可能包含私有小说数据；未绕过，旧界面基线仅依据源码，所有截图均来自静态夹具。

## 构建与恢复

`npm run build` 成功；已有 Vite 字体插件提示未安装可选 fontaine，未为消除提示新增依赖。主题构建和 `php artisan design:preview` 成功。所有新增/修改样式、Blade、JS、PHP 均有中文说明；无数据库结构变化。

复验步骤：先构建主题，再执行 design:preview；只对生成的静态目录启动本机服务器，用浏览器打开 create/workbench/reader/issues.html。完成上述缺项后更新报告和任务状态。回退仅涉及主题和共用组件源码，不改变小说数据、任务策略或正式状态。
