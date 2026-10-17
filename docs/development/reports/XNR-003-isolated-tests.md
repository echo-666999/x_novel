# XNR-003 隔离验证环境

2026-10-17；执行者 Codex。R00 用户已选择凭据 A，合同已同步。本任务只创建 `xnovel_refactor_test`，沿现有部署连接访问本机 PostgreSQL；不更改 .env、不清空运行库。

入口：`php artisan testing:prepare --no-interaction`（幂等创建固定名称库及 pgvector，不自动迁移）。测试：`vendor/bin/pest -c phpunit.pgsql.xml tests/Feature/IsolatedTestEnvironmentTest.php --compact`。后续批次测试放 tests/Feature/Refactor，可按文件执行。

数据库只允许该固定名称或 SQLite :memory:。TestCase 在 RefreshDatabase 之前检查解析后的连接，拒绝 DB_URL；HTTP 默认禁止真实请求，业务默认绑定既有 Fake Provider。真实 Redis 使用 `xnovel-refactor-test:` 前缀与专属队列名，禁止 flushdb/flushall；测试只清理自己建立的键。测试启动不得使用开发环境配置缓存。

实测 PostgreSQL 18.6、pgvector 0.8.1。首次沙箱连接被本机网络限制拒绝；批准本机访问后，同一套 3 项测试全部通过、9 assertions、无跳过：拒绝错误目标、双连接唯一键竞争/回滚/JSONB、Redis 精确键读写。真实 Worker 的业务重复投递与租约恢复由 XNR-005 验证，当前不以 Redis ping 替代它。

新增代码均有中文职责/保护说明；未新增业务表，本次临时探针表执行后删除，不涉及业务中文备注迁移。恢复可保留专属测试库，或确认无测试运行后单独移除该库；不能运行全仓清理或共享 Redis 清空。下一项 XNR-004。
