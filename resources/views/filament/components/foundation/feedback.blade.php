{{-- 空、加载与失败分别表达，失败时仍保留调用方已有内容。 --}}
<div class="xn-feedback {{ $kind === 'failed' ? 'xn-tone-danger' : 'xn-tone-neutral' }}" role="{{ $kind === 'failed' ? 'alert' : 'status' }}">
    <h2>{{ $title }}</h2><p>{{ $message }}</p>
</div>
