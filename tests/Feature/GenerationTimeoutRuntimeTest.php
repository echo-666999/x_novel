<?php

use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\OpenAiProvider;
use App\Jobs\GenerateNovelBeatDetailJob;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

test('openai http client stops a genuinely slow response at its configured timeout', function () {
    $port = availableTimeoutTestPort();
    $server = new Process(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('tests/Fixtures/slow-openai-server.php')],
        base_path(),
        ['SLOW_OPENAI_DELAY_MICROSECONDS' => '3000000'],
    );
    $server->disableOutput();
    $server->start();

    try {
        awaitTimeoutTestServer($server, $port);

        config()->set('ai.providers.openai.api_key', 'test-key');
        config()->set('ai.providers.openai.base_url', "http://127.0.0.1:{$port}");
        config()->set('ai.providers.openai.connect_timeout', 1);
        config()->set('ai.providers.openai.timeout', 1);

        $startedAt = hrtime(true);

        try {
            app(OpenAiProvider::class)->generate(new AiRequest(model: 'test-model', prompt: 'Ping'));
            $this->fail('Expected the real HTTP request to time out.');
        } catch (AiProviderException $exception) {
            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

            expect($exception->errorCode)->toBe('provider_timeout')
                ->and($exception->retryable)->toBeTrue()
                // 墙钟区间证明 PendingRequest 的 timeout 确实传入了真实 cURL 请求。
                ->and($elapsedSeconds)->toBeGreaterThanOrEqual(0.8)
                ->and($elapsedSeconds)->toBeLessThan(2.5);
        }
    } finally {
        $server->stop(0);
    }
});

test('job timeout wins over the horizon supplied worker timeout in real elapsed time', function () {
    $result = runQueueTimeoutProbe(jobTimeout: 1, workerTimeout: 3);

    expect($result['successful'])->toBeFalse()
        ->and($result['termination_signal'])->toBe(SIGKILL)
        // Job 自带 timeout 时，Laravel Worker 不会再叠加 Horizon 的默认 timeout。
        ->and($result['elapsed_seconds'])->toBeGreaterThanOrEqual(0.8)
        ->and($result['elapsed_seconds'])->toBeLessThan(2.5);
});

test('horizon supplied worker timeout is used when the job has no timeout', function () {
    $result = runQueueTimeoutProbe(jobTimeout: null, workerTimeout: 1);

    expect($result['successful'])->toBeFalse()
        ->and($result['termination_signal'])->toBe(SIGKILL)
        ->and($result['elapsed_seconds'])->toBeGreaterThanOrEqual(0.8)
        ->and($result['elapsed_seconds'])->toBeLessThan(2.5);
});

test('outline job and horizon timeout configuration matches the deployed contract', function () {
    expect((new GenerateNovelBeatDetailJob(1, 'beat-01'))->timeout)->toBe(330)
        ->and(config('horizon.defaults.supervisor-1.timeout'))->toBe(360)
        ->and(config('queue.connections.redis.retry_after'))->toBe(420);
});

function availableTimeoutTestPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if ($socket === false) {
        throw new RuntimeException("Unable to reserve local test port: {$errorCode} {$errorMessage}");
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    if (! is_string($address) || preg_match('/:(\\d+)$/', $address, $matches) !== 1) {
        throw new RuntimeException('Unable to determine the reserved local test port.');
    }

    return (int) $matches[1];
}

function awaitTimeoutTestServer(Process $server, int $port): void
{
    $deadline = microtime(true) + 3;

    do {
        if (! $server->isRunning()) {
            throw new RuntimeException('The local slow HTTP server exited before becoming ready.');
        }

        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 0.2);
        if ($socket !== false) {
            fwrite($socket, "GET /ready HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
            $response = stream_get_contents($socket);
            fclose($socket);

            if (is_string($response) && str_contains($response, 'ready')) {
                return;
            }
        }

        usleep(20000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Timed out while starting the local slow HTTP server.');
}

/** @return array{successful: bool, termination_signal: int|null, elapsed_seconds: float, output: string} */
function runQueueTimeoutProbe(?int $jobTimeout, int $workerTimeout): array
{
    $process = new Process([
        PHP_BINARY,
        base_path('tests/Fixtures/measure-queue-timeout.php'),
        $jobTimeout === null ? 'null' : (string) $jobTimeout,
        (string) $workerTimeout,
    ], base_path());
    $process->setTimeout(8);

    $startedAt = hrtime(true);
    try {
        $process->run();
    } catch (ProcessSignaledException) {
        // Laravel Worker 超时会用 SIGKILL 终止自身，Symfony Process 会把该结果报告为异常。
    }

    return [
        'successful' => $process->isSuccessful(),
        'termination_signal' => $process->getTermSignal(),
        'elapsed_seconds' => (hrtime(true) - $startedAt) / 1_000_000_000,
        'output' => $process->getOutput().$process->getErrorOutput(),
    ];
}
