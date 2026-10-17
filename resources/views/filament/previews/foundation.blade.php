{{-- R01 静态夹具：所有小说、费用、时间和异常均为示例，无业务请求或写入入口。 --}}
@php
    $pages = ['create' => ['创建小说', 'heroicon-o-pencil-square'], 'workbench' => ['小说工作台', 'heroicon-o-book-open'], 'reader' => ['正文阅读', 'heroicon-o-document-text'], 'issues' => ['异常与审校', 'heroicon-o-exclamation-triangle']];
@endphp
<!doctype html>
<html lang="zh-CN" class="dark">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $pages[$page][0] }} · R01 隔离示例</title>
<style>{!! $css !!}</style></head>
<body class="fi-body xn-foundation">
<a href="#main" class="xn-skip">跳到主要内容</a>
<div class="xn-shell">
    <aside class="xn-sidebar"><div class="xn-brand">XNovel · 创作</div><nav aria-label="示例页面">
        @foreach($pages as $key => [$name, $icon])<a href="{{ $key }}.html" @if($page === $key) aria-current="page" @endif aria-label="{{ $name }}"><x-dynamic-component :component="$icon" aria-hidden="true"/><span>{{ $name }}</span></a>@endforeach
    </nav></aside>
    <div>
        <div class="xn-preview-banner"><p><strong>隔离视觉示例</strong> <span class="xn-muted">虚构数据 · 不会生成小说或产生费用</span></p>
            <label class="xn-toolbar">外观 <select aria-label="外观" class="xn-control" data-display-preference="theme"><option value="dark">深色</option><option value="light">浅色</option><option value="system">跟随系统</option></select></label>
        </div>
        <main id="main" class="xn-content" tabindex="-1">
            <details class="xn-mobile-nav"><summary>浏览示例页面</summary><nav aria-label="手机示例导航">@foreach($pages as $key => [$name, $icon])<a href="{{ $key }}.html" @if($page === $key) aria-current="page" @endif>{{ $name }}</a>@endforeach</nav></details>
            @if($page === 'create')
            <div class="xn-create">
                @include('filament.components.foundation.header', ['context' => '小说 / 创建', 'title' => '描述你想写的故事', 'description' => '背景、人物、冲突或结局，都可以直接写在这里。'])
                <section class="xn-panel xn-stack" aria-label="创作概述示例">
                    <div class="xn-field"><label for="premise">故事概述</label><textarea id="premise" class="xn-control" aria-describedby="premise-help" placeholder="例如：在一座潮汐决定时间的海港，一位修钟师收到一封来自明天的信……"></textarea><p id="premise-help" class="xn-muted">保留你最在意的设定与剧情。其余内容由系统逐步补齐。</p></div>
                    <div class="xn-toolbar"><div class="xn-field"><label for="target">目标篇幅</label><select id="target" class="xn-control"><option>10 万字（默认）</option><option>30 万字</option></select></div><div class="xn-field"><label for="mode">生成模式</label><select id="mode" class="xn-control"><option>自动完成整本</option><option>先看开篇 3 章</option><option>逐章人工提交</option></select></div></div>
                    <p class="xn-notice xn-tone-info">完成小说是默认目标。费用用于统计与优化，未启用金额硬上限。</p>
                    <details class="xn-evidence"><summary>更多设置</summary><p class="xn-muted">模型继承全局配置。金额硬上限仅在主动开启后生效；完整设置入口在后续批次接入。</p></details>
                    <x-filament::button class="xn-primary" disabled aria-describedby="create-disabled">开始创作</x-filament::button><p id="create-disabled" class="xn-muted">示例不连接业务，此按钮不可提交。表单样式及输入仅用于视觉验证。</p>
                </section>
                <div style="margin-top:24px">@include('filament.components.foundation.feedback', ['kind' => 'empty', 'title' => '从一个想法开始', 'message' => '还没有创作概述时，系统不会擅自建立小说。'])</div>
            </div>
            @elseif($page === 'workbench')
                @include('filament.components.foundation.header', ['context' => '小说 / 工作台 · 示例', 'title' => '潮汐尽头的来信', 'status' => 'running', 'description' => '奇幻悬疑 · 自动完成整本 · 目标 10 万字'])
                <div class="xn-workbench">
                    <div class="xn-stack">
                        @include('filament.components.foundation.task-panel', ['status' => 'running', 'phase' => '第 9 章 · 审校正文', 'detail' => '检查人物行动与已知事实。通过后正式保存，并继续下一章。', 'chapters' => 8, 'words' => '24,160', 'target' => '100,000', 'observedAt' => '2026-10-17T14:32:00+08:00', 'observedLabel' => '示例时间 2026-10-17 14:32（上海）'])
                        <section class="xn-panel xn-stack"><h2>继续阅读</h2><p class="xn-stage-title">第八章　灯塔里没有寄信人</p><p class="xn-muted">正式正文已保存。第 9 章仍是草稿，不计入已完成章节。</p><x-filament::button tag="a" href="reader.html" class="xn-primary">阅读示例正文</x-filament::button></section>
                        @include('filament.components.foundation.feedback', ['kind' => 'loading', 'title' => '正在获取最新进展', 'message' => '下方保留最近确认的记录；尚未取得新的活动证据。刷新失败不会暂停后台任务。'])
                        @include('filament.components.foundation.evidence', ['evidence' => ['示例任务' => 'TASK-DEMO-01', '当前阶段' => '独立审校', '最近有效结果' => '第 8 章已正式保存', '暂停说明' => '已发请求可保存结果，暂停后不推进或正式提交']])
                    </div>
                    <div class="xn-stack">
                        @include('filament.components.foundation.cost-summary', ['actual' => null, 'estimated' => '1.2842', 'unknown' => 1, 'currency' => 'USD', 'hardCapEnabled' => false, 'warning' => true])
                        <section class="xn-panel xn-stack"><h2>任务控制</h2><x-filament::button class="xn-secondary" disabled aria-describedby="pause-disabled">暂停生成</x-filament::button><p id="pause-disabled" class="xn-muted">这是展示状态，暂停操作未连接真实任务。</p></section>
                    </div>
                </div>
            @elseif($page === 'reader')
                @include('filament.components.foundation.header', ['context' => '潮汐尽头的来信 / 正文 · 示例', 'title' => '第八章　灯塔里没有寄信人', 'status' => 'canonical'])
                <section class="xn-panel xn-stack">
                    <details class="xn-evidence"><summary>章节目录 · 示例选段</summary><nav class="xn-chapters" aria-label="示例章节目录"><span class="xn-muted">前七章未包含在静态夹具中。</span><a href="#chapter-eight" aria-current="page">第八章　灯塔里没有寄信人</a></nav></details>
                    <details class="xn-evidence"><summary>阅读偏好</summary><div class="xn-toolbar">
                        <label class="xn-field">字体<select class="xn-control" data-display-preference="font"><option value="serif">宋体回退</option><option value="sans">无衬线</option></select></label>
                        <label class="xn-field">字号<select class="xn-control" data-display-preference="size">@foreach([16,18,20,22] as $value)<option value="{{ $value }}">{{ $value }}px</option>@endforeach</select></label>
                        <label class="xn-field">行距<select class="xn-control" data-display-preference="leading">@foreach(['1.7','1.9','2.1'] as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></label>
                        <label class="xn-field">行宽<select class="xn-control" data-display-preference="width">@foreach([32,40,48] as $value)<option value="{{ $value }}">{{ $value }} 字</option>@endforeach</select></label>
                    </div><p class="xn-muted">偏好只改变显示，正文与导出内容不变。</p></details>
                    <article id="chapter-eight" class="xn-reading" aria-label="示例小说正文"><h2>第八章　灯塔里没有寄信人</h2>
                        <p>潮水退去的时候，城里的钟都慢了一拍。</p>
                        <p>林澈沿着石阶走向灯塔。海风把衣角吹得贴在腿上，他用手按住口袋，确认那封信还在。纸已经干了，折痕里却留着细细的盐。</p>
                        <p>门没有锁。铜把手上缠着一根蓝线，和信封里掉出来的那根一模一样。</p>
                        <p>“有人吗？”他问。</p>
                        <p>回答他的只有齿轮声。那声音并不连续，每隔七下就停一次，像一个谨慎的人，走到门口又收回了脚。</p>
                        <p>灯塔中央摆着一张桌子。桌面擦得很干净，空信封朝向窗户，旁边放着一只没有指针的怀表。林澈认得那只表——三年前，是他亲手把它交给父亲的。</p>
                        <p>他没有立刻碰它。窗外，最后一道潮线正离开礁石。落日把水面切成一片片暗金色，却照不进桌子下方。</p>
                        <p>第八声齿轮响起时，桌上的信封轻轻动了。</p>
                    </article>
                    <p class="xn-muted">以上为原创视觉夹具选段，不是系统生成结果。仅此章节可供预览。</p>
                </section>
            @else
                @include('filament.components.foundation.header', ['context' => '潮汐尽头的来信 / 异常 · 示例', 'title' => '先解决这一处，再继续写作', 'description' => '当前问题与历史失败分开呈现，已保存正文不受影响。'])
                <div class="xn-workbench">
                    <div class="xn-stack">
                        @include('filament.components.foundation.issue-summary', ['title' => '第 9 章的线索与已知事实冲突', 'status' => 'needs_attention', 'message' => '草稿中林澈直接说出了寄信人的名字，但此前正式章节尚未让他得知这一信息。', 'impact' => '影响：第 9 章暂不正式保存；前 8 章仍可阅读。', 'suggestion' => '建议保留既有知识边界，修正本章相关段落后重新审校。'])
                        <section class="xn-panel xn-stack"><h2>AI 处理</h2><p>根据冲突证据局部修正，再重新审校。不会自动覆盖锁定事实。</p><x-filament::button class="xn-primary" disabled aria-describedby="ai-disabled">让 AI 修复并复验</x-filament::button><p id="ai-disabled" class="xn-muted">此为视觉示例，不会发起模型请求。真实入口将在异常处理批次接入。</p></section>
                        <section class="xn-panel xn-stack"><h2>人工处理</h2><p>选择明确的处理方式，修改后仍需复验。</p><fieldset class="xn-stack"><legend class="xn-muted">示例建议</legend><label class="xn-toolbar"><input type="radio" name="manual" checked> 保留既有事实，编辑冲突段落（推荐）</label><label class="xn-toolbar"><input type="radio" name="manual"> 先查看前文证据，再决定</label><label class="xn-toolbar"><input type="radio" name="manual"> 保持暂停，稍后处理</label></fieldset><x-filament::button class="xn-secondary" disabled>打开人工编辑</x-filament::button><p class="xn-muted">预览不可提交修改；不提供跳过锁定事实的按钮。</p></section>
                    </div>
                    <div class="xn-stack">
                        @include('filament.components.foundation.evidence', ['evidence' => ['问题编号' => 'ISSUE-DEMO-01', '冲突规则' => '人物知识边界', '来源章节' => '第 8 章正式正文', '受影响版本' => '第 9 章草稿 v2', '已尝试修复' => '1 次（示例）', '验证结果' => '尚未通过，不标记已解决']])
                        @include('filament.components.foundation.feedback', ['kind' => 'failed', 'title' => '进展更新失败 · 示例', 'message' => '暂时无法取得最新观测。当前仍显示上一份已确认结果，请稍后重试，不要重复提交。'])
                        <section class="xn-panel xn-stack"><h2>历史处理记录</h2>@include('filament.components.foundation.status', ['status' => 'resolved'])<p class="xn-muted">第 6 章结构格式已修复并复验，不计入当前待处理问题。</p></section>
                    </div>
                </div>
            @endif
        </main>
    </div>
</div>
<script>{!! $script !!}</script>
</body></html>
