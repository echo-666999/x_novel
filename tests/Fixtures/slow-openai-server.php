<?php

// 健康检查必须立即返回，避免测试在确认本地服务就绪时提前消耗慢请求时间。
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/ready') {
    header('Content-Type: text/plain');
    echo 'ready';

    return;
}

$delayMicroseconds = max(0, (int) getenv('SLOW_OPENAI_DELAY_MICROSECONDS'));
usleep($delayMicroseconds);

header('Content-Type: application/json');
echo json_encode([
    'id' => 'slow-local-response',
    'model' => 'test-model',
    'choices' => [[
        'finish_reason' => 'stop',
        'message' => ['content' => 'OK', 'refusal' => null],
    ]],
    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
], JSON_THROW_ON_ERROR);
