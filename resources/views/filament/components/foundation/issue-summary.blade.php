{{-- 同一问题的两条处理路径，动作能力由领域合同提供。 --}}
<section class="xn-panel xn-stack" aria-label="当前问题">
    <div class="xn-heading-row"><h2>{{ $title }}</h2>@include('filament.components.foundation.status', ['status' => $status])</div>
    <p>{{ $message }}</p><p class="xn-muted">{{ $impact }}</p>
    <div class="xn-notice xn-tone-warning">{{ $suggestion }}</div>
</section>
