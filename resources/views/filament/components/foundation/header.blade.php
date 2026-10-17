{{-- 标题允许长中文自然换行，诊断信息不挤占作品标题。 --}}
<header class="xn-page-heading">
    <p class="xn-muted">{{ $context }}</p>
    <div class="xn-heading-row"><h1>{{ $title }}</h1>
        @isset($status) @include('filament.components.foundation.status', ['status' => $status]) @endisset
    </div>
    @isset($description)<p class="xn-muted">{{ $description }}</p>@endisset
</header>
