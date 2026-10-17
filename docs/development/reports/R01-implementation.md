# R01 实施与验收记录

2026-10-17；基线提交 `99588fb`，本次工作尚未提交。用户选择凭据方案 **A**，已解除 R00 合同阻塞。仅执行 R01，没有进入 R02–R07。

**当前结论：XNR-003–006 已完成；XNR-034 视觉代码已实现，仍有浏览器实测缺项，因此 R01 整体验收尚未关闭。**

| 任务 | 状态 | 证据 |
| --- | --- | --- |
| XNR-003 隔离 PostgreSQL / Redis | DONE | [环境报告](XNR-003-isolated-tests.md) |
| XNR-004 Schema / 任务身份 | DONE | [数据报告](XNR-004-schema.md) |
| XNR-005 执行认领 / 恢复 | DONE | [执行报告](XNR-005-execution.md) |
| XNR-006 配置 / 容量 / 费用 / 凭据 | DONE | [费用报告](XNR-006-configuration-cost.md) |
| XNR-034 共用视觉基础 | IN_PROGRESS | [截图、测量与缺项](XNR-034-visual-foundation.md) |

## 实现和数据边界

- 新增 Task/Issue 两张业务表；扩展 Run/Artifact/Usage 和规划/正文归属、引用及费用策略审计。迁移有中文备注、CHECK、唯一约束和同小说复合外键。
- 原子创建、幂等提交、串行执行、租约与版本检查、暂停恢复和队列补偿已具备基础入口。新 Job 只接入执行身份预检；完整创作流程属于后续批次。
- 费用默认观测，主动硬上限才停止；实际发送前建账、原子预留和再次复核；未知用量/费用不归零。凭据只解析 env/config 白名单，数据库只保存引用。
- 共用主题、组件及四页静态示例已经实现。预览没有生产路由，不操作小说或调用模型。
- 只对 `xnovel_refactor_test` 迁移/清理测试数据，Redis 使用 `xnovel-refactor-test:` 前缀和专属测试队列。运行库 `novel`、现有小说、`.env`、运行 Worker 均未迁移、清理或重启。
- 当前代码是阶段实施成果，不能直接把未迁移的运行数据库当作新版部署环境。R01 通过也不代表整书生成已经可用。

## 最终回归

```bash
vendor/bin/pest -c phpunit.pgsql.xml tests/Feature/Refactor tests/Feature/IsolatedTestEnvironmentTest.php tests/Feature/GenerationSettingsServiceTest.php tests/Feature/AiSettingsResolverTest.php tests/Feature/Filament/NovelAiSettingsTest.php tests/Feature/Filament/BudgetUiTest.php tests/Feature/Filament/AiDebugTestPageTest.php tests/Feature/AiProviderTest.php tests/Feature/DeepSeekProviderTest.php --compact
```

**121 项：120 passed，1 skipped，662 assertions，约 38 秒。** 跳过项是专门验证 SQLite 无 pgvector 列的用例，不能在 PostgreSQL 冒充同一环境。未运行整个项目测试库，不声明全量旧测试通过。Provider 适配器回归显式恢复实际装饰链，同时保持 Http::preventStrayRequests，全部使用 Http::fake，没有真实收费调用。

专项证据含：双连接唯一身份/锁竞争/金额预留；实际 Redis Worker 重复投递；独立 PHP Worker 在认领后及保存结果后退出；暂停 4 日；失效来源；价格冻结/缺失/用量缺失；发送前改上限/撤销连接；核销幂等；创建/编辑配置保存；预览生产保护和错误文本转义。

测试发现并修复了应用时区与 timestamptz 的 8 小时偏移、JSONB 对象顺序误判、未回传用量冒充免费、Filament 按钮颜色覆盖等问题。旧测试关于“有金额即启用”“密钥写数据库”“按 JSON 对象键顺序判断相等”的断言同步到已确认合同；没有删除这些行为的测试覆盖。

`vendor/bin/pint --dirty`、`git diff --check`、`npm run build` 已执行成功。Vite 的可选 fontaine 提示保留，未额外安装依赖。四页两主题 40 组视口测量、16 张截图与实际色值对比度见视觉报告。

## 余项及下一步

当前仅补验 XNR-034：浏览器真实缩放、减少动态效果及完整基础焦点组合。原生控制超时并断开标签，已尝试重新建立隔离标签；键盘焦点可复验，但缩放尚无确认结果，不以模拟视口替代。修复工具连接或由真实浏览器完成这些检查后，才能把 XNR-034 和 R01 标记完成。

未执行三本真实小说测试；该验收仍属于 R06。未进行 R07 无用代码删除。旧调度/预算方法保留到其引用审计和新入口全部接通后清理；新执行身份不进入旧恢复扫描。未发送消息、提交代码或部署。

补充凭据管理回归：`vendor/bin/pest -c phpunit.pgsql.xml tests/Feature/Filament/AIProviderManagementTest.php --compact`，13 passed / 145 assertions。连接诊断和列表也改为引用/env解析；不再读取旧 api_key，列表显示真实配置状态，不以固定圆点伪装已配置。
