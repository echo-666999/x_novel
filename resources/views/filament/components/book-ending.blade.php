@php($task=\App\Models\NovelGenerationTask::findOrFail($novel->current_generation_task_id))
<div class="xn-stack">
    <x-filament::section heading="这本书是否已经完成">
        <p>正文：{{ $novel->status->value==='completed' ? '已通过完结核验' : '尚未通过完结核验' }} · 整书任务：{{ $task->status==='completed' ? '已完成并导出' : '尚未完成' }}</p>
        <p>正式字数 {{ number_format($novel->chapters()->where('status','canonical')->sum('word_count')) }} / 目标 {{ number_format($task->target_words) }}。达到字数不等于主线、承诺和伏笔已兑现。</p>
        <p>已安排补章 {{ data_get($task->progress_snapshot,'closing_extensions',0) }} / {{ data_get($task->progress_snapshot,'closing_extension_limit',config('generation.book.max_closing_extensions',3)) }}。</p>
    </x-filament::section>
    @if($audit)
    <x-filament::section heading="逐项完结证据">
        <p>审计结果：{{ $audit->data['decision']==='PASS' ? '通过' : '仍有未闭合项' }} · 来源状态 #{{ $audit->data['state_version_id'] }}</p>
        @foreach($audit->data['checks'] as $check)
        <section class="xn-book-finding"><h3>{{ collect(\App::make(\App\Services\BookEnding::class)->requirements($novel))->firstWhere('key',$check['key'])['description'] ?? $check['key'] }} · {{ $check['fulfilled'] ? '已兑现' : '待解决' }}</h3><p>{{ $check['reason'] }}</p>
        @foreach($check['evidence'] as $e)<a href="{{ \App\Filament\Resources\Novels\NovelResource::getUrl('book-chapter',['record'=>$novel,'chapter'=>$e['chapter_id']]) }}#paragraph-{{ $e['paragraph_key'] }}"><q>{{ $e['quote'] }}</q></a>@endforeach</section>
        @endforeach
        @foreach($audit->data['deterministic_debts'] as $debt)<p>{{ $debt['description'] ?? $debt['label'] ?? $debt['type'] ?? '正式状态存在未结清债务' }}</p>@endforeach
    </x-filament::section>
    @else<x-filament::section heading="等待正式进展"><p>达到收束条件后自动核验。正式章节和必需摘要不足时不会假称完成。</p></x-filament::section>@endif
</div>
