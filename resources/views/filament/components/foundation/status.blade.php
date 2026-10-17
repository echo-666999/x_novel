{{-- 标签同时使用文字和图标，不只靠颜色传达状态。 --}}
@php($presentation = \App\Filament\Support\GenerationPresentation::status($status))
<span class="xn-status xn-tone-{{ $presentation['tone'] }}">
    <x-dynamic-component :component="$presentation['icon']" aria-hidden="true" />
    {{ $presentation['label'] }}
</span>
