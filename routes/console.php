<?php

use App\Models\SystemSetting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('generation:recover-automatic')->everyMinute()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping();

// 仅实际 Scheduler 执行才更新心跳，不能用配置文件存在冒充在线。
Schedule::call(function (): void {
    SystemSetting::updateOrCreate(['key' => 'generation_health_scheduler'], ['value' => ['observed_at' => now()->toISOString(), 'code_version' => hash_file('sha256', app_path('Services/BookLifecycle.php'))]]);
})->name('generation-health-heartbeat')->everyMinute()->withoutOverlapping();
