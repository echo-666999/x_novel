<?php

namespace App\Console\Commands;

use App\Support\IsolatedTestEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** 只创建固定名称的隔离库，不执行清空、迁移或修改开发环境。 */
class PrepareIsolatedTests extends Command
{
    protected $signature = 'testing:prepare';

    protected $description = '创建 XNovel 专用 PostgreSQL 测试库并核实 pgvector';

    public function handle(): int
    {
        $settings = config('database.connections.pgsql');
        // 管理连接仅借用部署连接参数，不输出密码；目标名称不接受用户插值。
        $settings['database'] = 'postgres';
        $settings['url'] = null;
        $settings['prefix'] = '';
        config(['database.connections.test_admin' => $settings]);
        $admin = DB::connection('test_admin');
        $exists = $admin->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [IsolatedTestEnvironment::DATABASE]);
        if (! $exists) {
            $admin->statement('CREATE DATABASE "xnovel_refactor_test"');
        }
        $settings['database'] = IsolatedTestEnvironment::DATABASE;
        config(['database.connections.test_probe' => $settings]);
        $probe = DB::connection('test_probe');
        $probe->statement('CREATE EXTENSION IF NOT EXISTS vector');
        $version = $probe->scalar("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
        $this->info('隔离库已就绪：xnovel_refactor_test；pgvector '.$version.'。未迁移或清空现有运行库。');

        return self::SUCCESS;
    }
}
