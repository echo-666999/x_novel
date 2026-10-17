<?php

namespace App\Support;

/** 在任何测试迁移之前拒绝开发数据库和共享队列，避免误清正式数据。 */
final class IsolatedTestEnvironment
{
    public const DATABASE = 'xnovel_refactor_test';

    public const REDIS_PREFIX = 'xnovel-refactor-test:';

    /** 校验解析后的配置，不能仅相信 APP_ENV 或命令行声明。 */
    public function assertSafe(array $database, string $environment, string $redisPrefix, string $queue): void
    {
        if ($environment !== 'testing' || ! empty($database['url'])) {
            throw new \LogicException('测试必须使用 testing 环境，且不得以 DB_URL 覆盖隔离连接。');
        }

        $memory = ($database['driver'] ?? '') === 'sqlite' && ($database['database'] ?? '') === ':memory:';
        $postgres = ($database['driver'] ?? '') === 'pgsql' && ($database['database'] ?? '') === self::DATABASE;
        if (! $memory && ! $postgres) {
            throw new \LogicException('拒绝非隔离测试数据库；只允许内存 SQLite 或 xnovel_refactor_test。');
        }

        if ($queue !== 'sync' && ($queue !== 'redis' || $redisPrefix !== self::REDIS_PREFIX)) {
            throw new \LogicException('真实队列测试必须使用项目专属 Redis 前缀。');
        }
    }
}
