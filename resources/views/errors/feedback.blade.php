@php
    // 系统错误页不查询业务数据，不依赖 Vite 清单，数据库或构建故障时仍能提供返回路径。
    [$title, $message] = match ($status) {
        403 => ['暂时无法访问', '当前账号无权执行此操作。请返回小说列表，或重新登录后核对账号。'],
        404 => ['没有找到这个页面', '记录可能不存在或地址已失效。请返回小说列表重新选择。'],
        419 => ['登录会话已过期', '本次提交未确认成功。请先保留尚未保存的文字，重新登录后核对记录，再决定是否重新提交。'],
        503 => ['系统暂时不可用', '系统正在维护或暂时无法处理请求。已有任务状态以恢复后的记录为准，请稍后重试。'],
        default => ['页面暂时无法加载', '本次操作结果尚未确认。请重新加载并核对任务记录；如果仍然失败，可凭下方错误编号查看日志。'],
    };
@endphp
<!doctype html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · XNovel</title>
    <script>
        // 与 Filament 共用显示偏好；本页不修改登录、生成模式或费用策略。
        try {
            const theme = localStorage.getItem('filament:theme:mode') || 'dark';
            document.documentElement.classList.toggle('dark', theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches));
        } catch (error) {}
    </script>
    <style>
        :root { color-scheme:light; --canvas:#f6f7f9; --surface:#fff; --text:#17191f; --muted:#4f5561; --border:#747b88; --primary:#4f46e5; --on-primary:#fff; }
        .dark { color-scheme:dark; --canvas:#0b0c0f; --surface:#111318; --text:#f2f3f5; --muted:#b7bbc5; --border:#858a96; --primary:#a5b4fc; --on-primary:#141820; }
        * { box-sizing:border-box; } body { margin:0; background:var(--canvas); color:var(--text); font-family:"PingFang SC","Microsoft YaHei",system-ui,sans-serif; line-height:1.7; }
        main { max-width:42rem; margin:10vh auto; padding:clamp(1rem,4vw,2rem); } section { padding:clamp(1rem,4vw,2rem); background:var(--surface); border:1px solid var(--border); border-radius:.5rem; }
        h1 { font-size:1.6rem; line-height:1.4; } p { overflow-wrap:anywhere; } .meta { color:var(--muted); } nav { display:flex; flex-wrap:wrap; gap:.75rem; margin-top:1.5rem; }
        a, button { display:inline-flex; align-items:center; min-height:2.75rem; padding:.5rem 1rem; border:1px solid var(--border); border-radius:.375rem; color:var(--text); background:var(--surface); font:inherit; text-decoration:none; cursor:pointer; }
        a:first-child { background:var(--primary); color:var(--on-primary); border-color:var(--primary); } a:hover, button:hover { text-decoration:underline; } :focus-visible { outline:2px solid var(--primary); outline-offset:3px; }
    </style>
</head>
<body>
<main>
    <p class="meta">XNovel · {{ $status }}</p>
    <section aria-labelledby="error-title">
        <h1 id="error-title">{{ $title }}</h1>
        <p role="alert">{{ $message }}</p>
        @if($errorId)<p class="meta">错误编号：<code>{{ $errorId }}</code></p>@endif
        <nav aria-label="恢复操作">
            @if(in_array($status, [403, 419], true))<a href="{{ route('filament.x.auth.login') }}">重新登录</a>@endif
            <a href="{{ route('filament.x.resources.novels.index') }}">返回小说列表</a>
            @if($status >= 500)<button type="button" onclick="window.location.reload()">重新加载</button>@endif
        </nav>
    </section>
</main>
</body>
</html>
