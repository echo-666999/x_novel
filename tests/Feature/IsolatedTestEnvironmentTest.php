<?php

use App\Support\IsolatedTestEnvironment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

// 安全规则先在无业务数据的条件下验证，错误目标不得连接或迁移。
test('isolation rejects development databases and shared queues', function () {
    $guard = new IsolatedTestEnvironment;
    expect(fn () => $guard->assertSafe(['driver' => 'pgsql', 'database' => 'novel'], 'testing', '', 'sync'))
        ->toThrow(LogicException::class);
    expect(fn () => $guard->assertSafe(['driver' => 'pgsql', 'database' => IsolatedTestEnvironment::DATABASE], 'testing', 'shared:', 'redis'))
        ->toThrow(LogicException::class);
    expect(fn () => $guard->assertSafe(['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'pgsql://unsafe'], 'testing', '', 'sync'))
        ->toThrow(LogicException::class);
});

test('isolated postgres preserves jsonb and enforces atomic uniqueness across connections', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('此用例必须通过 phpunit.pgsql.xml 在隔离 PostgreSQL 执行。');
    }
    expect(DB::scalar('SELECT current_database()'))->toBe(IsolatedTestEnvironment::DATABASE);
    config(['database.connections.contender' => config('database.connections.pgsql')]);
    $first = DB::connection();
    $second = DB::connection('contender');
    // 固定专用表只存在测试库；清理精确对象，绝不清空共享 Schema。
    $first->statement('CREATE TABLE IF NOT EXISTS r01_isolation_probe (id integer PRIMARY KEY, payload jsonb NOT NULL)');
    $first->statement('TRUNCATE r01_isolation_probe');
    try {
        $first->beginTransaction();
        $first->insert('INSERT INTO r01_isolation_probe VALUES (1, ?::jsonb)', ['{"z":1,"a":[2,1]}']);
        $second->statement("SET lock_timeout = '100ms'");
        expect(fn () => $second->insert('INSERT INTO r01_isolation_probe VALUES (1, ?::jsonb)', ['{}']))
            ->toThrow(QueryException::class);
        $first->rollBack();
        $second->insert('INSERT INTO r01_isolation_probe VALUES (1, ?::jsonb)', ['{"z":1,"a":[2,1]}']);
        expect(json_decode($first->scalar('SELECT payload FROM r01_isolation_probe WHERE id=1'), true))
            ->toEqual(['a' => [2, 1], 'z' => 1]);
        expect(fn () => $first->insert('INSERT INTO r01_isolation_probe VALUES (1, ?::jsonb)', ['{}']))
            ->toThrow(QueryException::class);
    } finally {
        if ($first->transactionLevel() > 0) {
            $first->rollBack();
        }
        $first->statement('DROP TABLE r01_isolation_probe');
        DB::purge('contender');
    }
});

test('isolated redis round trip uses only the project namespace', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('真实 Redis 验证仅由隔离配置启用。');
    }
    expect(config('database.redis.options.prefix'))->toBe(IsolatedTestEnvironment::REDIS_PREFIX);
    $key = 'probe:'.Str::uuid();
    try {
        Redis::setex($key, 30, 'isolated');
        expect(Redis::get($key))->toBe('isolated');
    } finally {
        Redis::del($key);
    }
});
