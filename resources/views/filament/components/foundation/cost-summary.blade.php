{{-- 未知金额单独呈现；开启状态来自持久策略，不根据金额推断。 --}}
<section class="xn-panel xn-stack" aria-label="费用摘要">
    <h2>费用记录</h2>
    <dl class="xn-key-values">
        <div><dt>已确认金额</dt><dd>{{ \App\Filament\Support\GenerationPresentation::amount($actual, $currency) }}</dd></div>
        <div><dt>估计金额</dt><dd>{{ \App\Filament\Support\GenerationPresentation::amount($estimated, $currency) }}</dd></div>
        <div><dt>待确认请求</dt><dd>{{ $unknown }} 次</dd></div>
    </dl>
    <p class="xn-muted">已记录金额不包含待确认费用。</p>
    <p>{{ $hardCapEnabled ? '已主动启用金额硬上限，后续请求按当前授权检查。' : '以完成小说为目标，费用用于统计与优化；未启用金额硬上限。' }}</p>
    @if($warning)<p class="xn-notice xn-tone-warning">已达费用预警，生成继续，可查看明细。</p>@endif
</section>
