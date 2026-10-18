@php
    $tasks=\App\Models\NovelGenerationTask::query();
    $recent=(clone $tasks)->with('novel')->latest('updated_at')->limit(6)->get();
@endphp
<div class="xn-stack">
    <x-filament::section heading="把一个想法写成一本书" description="输入概述后自动规划、写作、审校和收束。只有真实进度会显示在这里。">
        <dl class="xn-issue-metrics">
            <div><dt>正在生成</dt><dd>{{ (clone $tasks)->whereIn('status',['queued','running'])->count() }}</dd></div>
            <div><dt>等待处理</dt><dd>{{ (clone $tasks)->where('status','needs_attention')->count() }}</dd></div>
            <div><dt>已暂停</dt><dd>{{ (clone $tasks)->where('status','paused')->count() }}</dd></div>
            <div><dt>整书已完成</dt><dd>{{ (clone $tasks)->where('status','completed')->count() }}</dd></div>
        </dl>
    </x-filament::section>
    <x-filament::section heading="最近创作">
        @forelse($recent as $task)
        <section class="xn-book-finding">
            <h2><a class="xn-reader-control" href="{{ \App\Filament\Resources\Novels\NovelResource::getUrl('view',['record'=>$task->novel_id]) }}">{{ $task->novel->title }}</a></h2>
            <p>{{ ['queued'=>'等待开始','running'=>'生成中','paused'=>'已暂停','needs_attention'=>'需要处理','completed'=>'整书已完成','cancelled'=>'已取消','failed'=>'失败'][$task->status] ?? $task->status }} · {{ \App\Enums\GenerationStage::tryFrom($task->current_phase ?? '')?->getLabel() ?? '等待开始' }} · 最近进展 {{ $task->last_progress_at?->format('m-d H:i') ?? '尚无记录' }}</p>
        </section>
        @empty<p>还没有新小说。点击“创建小说”，写下背景、人物或剧情概述即可开始。</p>@endforelse
    </x-filament::section>
    <x-filament::section heading="需要你的判断">
        @forelse(\App\Models\GenerationIssue::where('status','!=','resolved')->whereIn('severity',['error','critical'])->with('novel')->latest('last_seen_at')->limit(5)->get() as $issue)
            <p><a class="xn-reader-control" href="{{ \App\Filament\Pages\GenerationIssues::getUrl(['novel'=>$issue->novel_id]) }}">{{ $issue->novel->title }} · {{ $issue->user_message }}</a></p>
        @empty<p>当前没有阻塞问题。正常生成不需要逐步批准。</p>@endforelse
    </x-filament::section>
</div>
