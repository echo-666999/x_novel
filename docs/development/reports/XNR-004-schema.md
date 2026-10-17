# XNR-004 数据基础

2026-10-17：新增 Task/Issue、扩展 Run/Artifact/Usage 及三层规划引用；保留旧表到 R07 清理，不建设旧小说迁移路径。只在 xnovel_refactor_test 执行迁移。运行库和凭据未改动。

新增字段及两张表均有中文备注。PostgreSQL 复合外键校验小说归属，部分唯一索引保留 paused/needs_attention 的主任务身份。旧分卷本已可空；scene_plans/arc_contribution 改为可空，片段使用 Artifact。每种规划独立版本与当前引用。Usage 已准备请求账本字段，发送/结算逻辑由 XNR-006 接入。

原子创建入口 CreateNovelGenerationTaskAction 保存概述、Run、Artifact、Task 和当前指针，不收费、不创建旧层级或正式事件。同一提交 key 不重复建书，换概述不能复用该 key。InitializeNovelSystemAction 幂等初始化用户，不提供默认密码、不覆盖现有密码、不创建小说或密钥。

命令：`vendor/bin/pest -c phpunit.pgsql.xml tests/Feature/Refactor/GenerationIdentityConcurrencyTest.php tests/Feature/Refactor/GenerationFoundationTest.php tests/Feature/IsolatedTestEnvironmentTest.php --compact`。结果 10 tests / 145 assertions 全部通过、无跳过；含实际双连接主任务竞争、原子回滚、跨小说外键和实际中文备注。

迁移的 down 明确拒绝原地删除新历史；本批恢复方式是在核实隔离目标后 migrate:fresh 重建，并恢复相应代码。原运行库未迁移，无需回滚。后续阶段实现仍未完成，Schema 通过不代表整书可运行。
