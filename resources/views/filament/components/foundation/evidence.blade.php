{{-- 原生 disclosure 可键盘展开；技术证据放到第二层，默认不干扰创作。 --}}
<details class="xn-panel xn-evidence"><summary>{{ $title ?? '查看来源与诊断' }}</summary>
    <dl class="xn-key-values">@foreach($evidence as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl>
</details>
