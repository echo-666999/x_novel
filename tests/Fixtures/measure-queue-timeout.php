<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$jobTimeoutArgument = $argv[1] ?? 'null';
$jobTimeout = $jobTimeoutArgument === 'null' ? null : (int) $jobTimeoutArgument;
$workerTimeout = (int) ($argv[2] ?? 0);

if ($workerTimeout < 1 || ($jobTimeout !== null && $jobTimeout < 1)) {
    fwrite(STDERR, "Timeout values must be positive.\n");
    exit(2);
}

$job = new class($app, $jobTimeout) extends Job
{
    public function __construct($container, private readonly ?int $configuredTimeout)
    {
        $this->container = $container;
        $this->connectionName = 'sync';
        $this->queue = 'timeout-test';
    }

    public function getJobId(): string
    {
        return 'timeout-probe';
    }

    public function getRawBody(): string
    {
        return json_encode([
            'uuid' => 'timeout-probe',
            'displayName' => 'timeout-probe',
            'job' => 'timeout-probe',
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => $this->configuredTimeout,
            'retryUntil' => null,
            'data' => [],
        ], JSON_THROW_ON_ERROR);
    }

    public function attempts(): int
    {
        return 1;
    }
};

$worker = new class($app->make('queue'), $app->make('events'), $app->make(ExceptionHandler::class), static fn (): bool => false) extends Worker
{
    public function armTimeout(Job $job, WorkerOptions $options): void
    {
        // 直接复用框架真实的 SIGALRM 注册逻辑，避免用测试替身重复实现超时行为。
        $this->registerTimeoutHandler($job, $options);
    }
};

// Worker daemon 启动时会开启异步信号；探针必须保持同一前置条件才能测量真实终止时间。
pcntl_async_signals(true);
$worker->armTimeout($job, new WorkerOptions(timeout: $workerTimeout, maxTries: 0));

// 若真实 Worker 计时器没有终止进程，这个保护上限会让测试以明确错误结束。
sleep(max($jobTimeout ?? $workerTimeout, $workerTimeout) + 3);
fwrite(STDERR, "Queue worker timeout was not enforced.\n");
exit(3);
