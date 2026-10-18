<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 管理后台也是私有正文下载的登录入口，避免默认 login 路由不存在导致 500。
        $middleware->redirectGuestsTo(fn () => route('filament.x.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // 系统页保留真实 HTTP 状态，不把会话过期或服务故障伪装成保存成功。
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            $status = $response->getStatusCode();
            if (! in_array($status, [403, 404, 419, 500, 503], true)
                || $request->is('api/*') || $request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return $response;
            }
            $errorId = null;
            if ($status >= 500) {
                // 页面只展示随机编号；日志关联异常类别，不把凭据、SQL 或原始异常输出给用户。
                $errorId = (string) Str::uuid();
                Log::error('系统页面请求失败', ['error_id' => $errorId, 'status' => $status, 'exception_type' => $exception::class]);
            }
            $response->setContent(view('errors.feedback', compact('status', 'errorId'))->render());
            $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

            return $response;
        });
    })->create();
