{{-- 小说顶部引用统一 Issue，不复制异常或在组件中改变状态。 --}}
@if(\Illuminate\Support\Facades\Schema::hasTable('generation_issues'))
    @php($currentIssues = \App\Models\GenerationIssue::where('novel_id', $getRecord()->id)->where('status', '!=', 'resolved')->latest('last_seen_at')->limit(3)->get())
    @if($currentIssues->isNotEmpty())
        <div class="xn-stack">
            @foreach($currentIssues as $currentIssue)
                @include('filament.components.foundation.issue-summary', ['title' => '问题 #'.$currentIssue->id, 'status' => $currentIssue->status,
                    'message' => $currentIssue->user_message, 'impact' => $currentIssue->severity === 'warning' ? '提醒：不阻止默认生成。' : '当前断点需要处理，已有内容已保留。',
                    'suggestion' => data_get($currentIssue->evidence, 'policy.suggestion', '查看详情核对原因。')])
                <x-filament::button color="gray" tag="a" :href="\App\Filament\Pages\GenerationIssues::getUrl(['novel' => $getRecord()->id, 'issue' => $currentIssue->id])">查看问题与处理方案</x-filament::button>
            @endforeach
        </div>
    @endif
@endif
