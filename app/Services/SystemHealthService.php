<?php

namespace App\Services;

use App\Data\SystemHealthCheck;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

class SystemHealthService
{
    /** @return Collection<int, SystemHealthCheck> */
    public function checks(): Collection
    {
        return collect([
            $this->database(),
            $this->redis(),
            $this->queue(),
            $this->horizon(),
            $this->scheduler(),
            $this->logs(),
            $this->configuration(),
            $this->rebuildCommands(),
            $this->costLimit(),
            $this->emergencyStop(),
            $this->documentation(),
            new SystemHealthCheck('database_backup', '数据库备份', 'manual', '发布前执行 pg_dump，并记录备份文件与时间。'),
            new SystemHealthCheck('restore_test', '备份恢复演练', 'manual', '在隔离数据库执行 pg_restore 并核对核心表。'),
            new SystemHealthCheck('queue_restart', 'Queue 重启', 'manual', '发布后执行 horizon:terminate，由进程管理器重启 Worker。'),
        ]);
    }

    private function database(): SystemHealthCheck
    {
        try {
            DB::select('select 1');

            return new SystemHealthCheck('database', '数据库连接', 'healthy', DB::getDriverName().' 连接正常。');
        } catch (Throwable $exception) {
            return new SystemHealthCheck('database', '数据库连接', 'warning', '连接失败：'.$exception->getMessage());
        }
    }

    private function redis(): SystemHealthCheck
    {
        try {
            Redis::connection()->ping();

            return new SystemHealthCheck('redis', 'Redis', 'healthy', 'Redis 连接正常。');
        } catch (Throwable) {
            return new SystemHealthCheck('redis', 'Redis', 'warning', '无法连接 Redis，Queue、Horizon 与锁可能不可用。');
        }
    }

    private function queue(): SystemHealthCheck
    {
        $connection = (string) config('queue.default');

        return new SystemHealthCheck(
            'queue',
            'Queue',
            $connection === 'redis' ? 'healthy' : 'warning',
            $connection === 'redis' ? '默认 Queue 使用 Redis。' : "当前 Queue 连接为 {$connection}，生产环境应使用 Redis。",
        );
    }

    private function horizon(): SystemHealthCheck
    {
        $queues = collect(config('horizon.defaults.supervisor-1.queue', []));
        $configured = collect(['generation', 'default'])->diff($queues)->isEmpty();

        try {
            $running = app(MasterSupervisorRepository::class)->all() !== [];
        } catch (Throwable) {
            $running = false;
        }

        return new SystemHealthCheck(
            'horizon',
            'Horizon',
            $configured && $running ? 'healthy' : 'warning',
            ($configured ? '已监听 generation / default。' : '未同时监听 generation / default。')
                .' '.($running ? 'Horizon 正在运行。' : '未检测到 Horizon Master。'),
        );
    }

    private function scheduler(): SystemHealthCheck
    {
        $scheduleFile = file_get_contents(base_path('routes/console.php')) ?: '';
        $hasRecovery = str_contains($scheduleFile, "Schedule::command('generation:mark-stalled')");
        $hasSnapshot = str_contains($scheduleFile, "Schedule::command('horizon:snapshot')");

        return new SystemHealthCheck(
            'scheduler',
            'Scheduler',
            $hasRecovery && $hasSnapshot ? 'healthy' : 'warning',
            $hasRecovery && $hasSnapshot ? '已注册停滞 Run 检测与 Horizon 指标快照。' : '缺少停滞 Run 检测或 Horizon 指标快照调度。',
        );
    }

    private function logs(): SystemHealthCheck
    {
        $path = storage_path('logs');
        $writable = is_dir($path) && is_writable($path);

        return new SystemHealthCheck('logs', '日志', $writable ? 'healthy' : 'warning', $writable ? 'storage/logs 可写。' : 'storage/logs 不可写。');
    }

    private function configuration(): SystemHealthCheck
    {
        $valid = config('app.locale') === 'zh_CN'
            && filled(config('app.key'))
            && filled(config('ai.provider'))
            && filled(config('ai.model'));

        return new SystemHealthCheck('config', '关键配置', $valid ? 'healthy' : 'warning', $valid ? '应用密钥、中文语言与 AI 默认值已配置。' : '请检查 APP_KEY、APP_LOCALE、AI_PROVIDER 与 AI_MODEL。');
    }

    private function rebuildCommands(): SystemHealthCheck
    {
        $commands = Artisan::all();
        $valid = isset($commands['story:rebuild-state'], $commands['memory:rebuild']);

        return new SystemHealthCheck('rebuild', 'State / Memory 重建', $valid ? 'healthy' : 'warning', $valid ? '已注册 Story State 校验与 Memory 重建命令。' : '缺少 Story State 或 Memory 重建命令。');
    }

    private function costLimit(): SystemHealthCheck
    {
        $configured = collect(config('ai.budget', []))->contains(fn ($value): bool => is_numeric($value));

        return new SystemHealthCheck('cost_limit', '成本硬限额', $configured ? 'healthy' : 'warning', $configured ? '至少配置了一项 Provider 请求硬限额。' : '未配置 Daily、Novel 或 Chapter 成本硬限额。');
    }

    private function emergencyStop(): SystemHealthCheck
    {
        try {
            $exists = SystemSetting::query()->whereKey(EmergencyStopService::SETTING_KEY)->exists();
        } catch (Throwable) {
            $exists = false;
        }

        return new SystemHealthCheck('emergency_stop', '紧急停止', $exists ? 'healthy' : 'warning', $exists ? '紧急停止开关已持久化。' : '紧急停止系统设置缺失。');
    }

    private function documentation(): SystemHealthCheck
    {
        $exists = is_file(base_path('docs/development/MVP_RELEASE_CHECKLIST.md'));

        return new SystemHealthCheck('docs', '发布文档', $exists ? 'healthy' : 'warning', $exists ? 'MVP 发布与恢复手册已存在。' : '缺少 MVP 发布与恢复手册。');
    }
}
