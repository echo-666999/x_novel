<?php

namespace App\Services;

use App\Data\SystemHealthCheck;
use App\Enums\AiStage;
use App\Models\AIModelRoute;
use App\Models\AIProviderConnection;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\SystemSetting;
use App\Models\UsageRecord;
use App\Support\GenerationDiagnosticRedactor;
use Illuminate\Support\Carbon;
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
        return collect(['database', 'redis', 'queue', 'horizon', 'scheduler', 'worker', 'provider', 'backlog', 'logs', 'configuration', 'costLimit', 'emergencyStop'])
            ->map(function (string $method): SystemHealthCheck {
                try {
                    $check = $this->$method();

                    // 未曾执行的心跳/请求保留空观测时间，打开设置页不能伪造一次在线观测。
                    $observedAt = $check->observedAt ?? (in_array($check->key, ['worker', 'scheduler', 'provider'], true) ? null : now()->toISOString());

                    return new SystemHealthCheck($check->key, $check->label, $check->status, $check->detail, $observedAt);
                } catch (Throwable $exception) {
                    return new SystemHealthCheck($method, $method, 'warning', app(GenerationDiagnosticRedactor::class)->clean($exception->getMessage()), now()->toISOString());
                }
            });
    }

    private function database(): SystemHealthCheck
    {
        try {
            DB::select('select 1');

            return new SystemHealthCheck('database', '数据库连接', 'healthy', DB::getDriverName().' 连接正常。');
        } catch (Throwable $exception) {
            return new SystemHealthCheck('database', '数据库连接', 'warning', '数据库连接失败，请核对服务与连接配置；详细诊断已脱敏。');
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
            $connection === 'redis' ? 'manual' : 'warning',
            $connection === 'redis' ? '队列配置使用 Redis，实际消费情况以 Worker 心跳为准。' : "当前 Queue 连接为 {$connection}，生产环境应使用 Redis。",
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

    /** 注册调度不是运行证据；只读取实际执行后的持久心跳。 */
    private function scheduler(): SystemHealthCheck
    {
        return $this->heartbeat('scheduler', 'Scheduler', 180);
    }

    private function worker(): SystemHealthCheck
    {
        return $this->heartbeat('worker', '生成 Worker', 420);
    }

    private function heartbeat(string $name, string $label, int $seconds): SystemHealthCheck
    {
        $value = SystemSetting::find('generation_health_'.$name)?->value;
        $at = $value['observed_at'] ?? null;
        $fresh = $at && Carbon::parse($at)->gt(now()->subSeconds($seconds));

        return new SystemHealthCheck($name, $label, $fresh ? 'healthy' : ($at ? 'stale' : 'manual'),
            $at ? '最近观测 '.$at.'；执行实现标识 '.($value['code_version'] ?? '未知').'。空闲无新心跳不等于服务离线。' : '尚无实际执行心跳，不能确认正在运行。', $at);
    }

    private function provider(): SystemHealthCheck
    {
        // 预留和发送前失败不算实际请求；账本禁用 updated_at，按发送时间选择最近请求。
        $usage = UsageRecord::whereNotNull('sent_at')->whereIn('status', ['in_flight', 'unknown', 'settled'])->latest('sent_at')->latest('id')->first();
        if (! $usage) {
            return new SystemHealthCheck('provider', 'Provider 最近请求', 'manual', '尚无实际请求观测；配置存在不代表连接可用。');
        }
        // 已结算请求使用结算观测，否则保留发送时间；访问诊断页不能刷新观测有效期。
        $observedAt = $usage->settled_at ?? $usage->sent_at;
        $fresh = $observedAt->gt(now()->subMinutes(15));

        return new SystemHealthCheck('provider', 'Provider 最近请求', ! $fresh ? 'stale' : ($usage->status === 'settled' ? 'healthy' : 'warning'),
            $usage->provider.' / '.$usage->model.'：'.$usage->status.'。仅代表最近这条请求，其他连接未据此验证。', $observedAt->toISOString());
    }

    private function backlog(): SystemHealthCheck
    {
        $summary = Chapter::where('status', 'canonical')->whereNull('summary_artifact_id')->whereHas('novel', fn ($q) => $q->whereNotNull('current_generation_task_id'))->count();
        $queued = GenerationRun::where('pipeline_version', 'pipeline-v1')->where('status', 'queued')->count();
        $expired = GenerationRun::whereIn('pipeline_version', ['pipeline-v1', 'book-derived-v1'])->where('status', 'running')->where('lease_expires_at', '<=', now())->count();

        return new SystemHealthCheck('backlog', '持久待办', $expired ? 'warning' : 'manual', "待执行 {$queued}；缺正式摘要 {$summary}；过期租约 {$expired}。使用 generation:recover-automatic 对账，不清空队列。");
    }

    private function logs(): SystemHealthCheck
    {
        $path = storage_path('logs');
        $writable = is_dir($path) && is_writable($path);

        return new SystemHealthCheck('logs', '日志', $writable ? 'healthy' : 'warning', $writable ? 'storage/logs 可写。' : 'storage/logs 不可写。');
    }

    private function configuration(): SystemHealthCheck
    {
        // 新整书链只检查实际必需路由；可降级的向量和旧规划角色不阻断预检。
        $required = [AiStage::OutlineFoundation, AiStage::Planner, AiStage::Writer, AiStage::Extractor, AiStage::Reviewer, AiStage::Rewrite, AiStage::Summary];
        $routeCount = AIModelRoute::query()->whereIn('role', array_map(fn (AiStage $stage) => $stage->value, $required))->distinct()->count('role');
        $valid = config('app.locale') === 'zh_CN'
            && filled(config('app.key'))
            && AIProviderConnection::query()->where('is_enabled', true)->exists()
            && $routeCount === count($required);

        return new SystemHealthCheck(
            'config',
            '关键配置',
            $valid ? 'healthy' : 'warning',
            $valid
                ? '应用密钥、中文语言、服务商连接与整书必需路由已配置；不代表供应商当前可用。'
                : '请检查 APP_KEY、APP_LOCALE，以及启用的服务商连接和设定、规划、写作、提取、审校、改写、摘要路由。',
        );
    }

    private function rebuildCommands(): SystemHealthCheck
    {
        $commands = Artisan::all();
        $valid = isset($commands['story:rebuild-state'], $commands['memory:rebuild']);

        return new SystemHealthCheck('rebuild', 'State / Memory 重建', $valid ? 'healthy' : 'warning', $valid ? '已注册 Story State 校验与 Memory 重建命令。' : '缺少 Story State 或 Memory 重建命令。');
    }

    /** 金额上限是用户选项；默认统计模式不是运行故障。 */
    private function costLimit(): SystemHealthCheck
    {
        return new SystemHealthCheck('cost_limit', '费用策略', 'healthy', '费用始终统计；预警不暂停。只有用户主动启用的金额硬上限参与请求门禁，未填写金额不影响生成。');
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
