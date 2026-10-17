{{-- 只展示已确认进展；未知活动时间不能伪装持续运行。 --}}
<section class="xn-panel xn-stack" aria-label="当前任务">
    <div class="xn-heading-row"><h2>当前任务</h2>@include('filament.components.foundation.status', ['status' => $status])</div>
    <p class="xn-stage-title">{{ $phase }}</p>
    <p>{{ $detail }}</p>
    <dl class="xn-key-values"><div><dt>正式章节</dt><dd>{{ $chapters }} 章</dd></div><div><dt>正式字数 / 目标</dt><dd>{{ $words }} / {{ $target }}</dd></div></dl>
    <p class="xn-muted">最近确认：<time datetime="{{ $observedAt }}">{{ $observedLabel }}</time></p>
</section>
