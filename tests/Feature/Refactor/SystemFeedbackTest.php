<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// 通过真实异常响应验证中文反馈、返回路径、状态码及脱敏，避免只断言模板源码。
test('system error pages retain status and explain the recovery path', function (int $status, string $title) {
    Route::get('/r05-error', fn () => abort($status, 'internal-fixture-secret'));

    $response = $this->get('/r05-error');

    $response->assertStatus($status)->assertSee($title)->assertSee('返回小说列表')->assertDontSee('internal-fixture-secret');
    if (in_array($status, [403, 419], true)) {
        $response->assertSee('重新登录');
    }
})->with([[403, '暂时无法访问'], [404, '没有找到这个页面'], [419, '登录会话已过期'], [503, '系统暂时不可用']]);

// 日志编号与用户可见编号必须相同；技术异常和敏感内容不能进入 HTML。
test('server failure exposes a traceable identifier without exception details', function () {
    Log::spy();
    config(['app.debug' => true]);
    Route::get('/r05-failure', fn () => throw new RuntimeException('internal-fixture-secret'));

    $response = $this->get('/r05-failure');

    $response->assertInternalServerError()->assertSee('页面暂时无法加载')->assertSee('重新加载')->assertDontSee('internal-fixture-secret');
    Log::shouldHaveReceived('error')->with('系统页面请求失败', Mockery::on(fn (array $context): bool => $context['status'] === 500
        && $context['exception_type'] === RuntimeException::class && str_contains($response->getContent(), $context['error_id'])))->once();
});

// API 的 JSON 合同不能被网页重设计改写成 HTML。
test('JSON errors keep their original response contract', function () {
    Route::get('/api/r05-error', fn () => abort(403, 'JSON forbidden'));

    $this->getJson('/api/r05-error')->assertForbidden()->assertJsonPath('message', 'JSON forbidden')->assertDontSee('恢复操作');
});
