<div class="xn-stack">
    <x-filament::section :heading="'第 '.$chapter->sequence.' 章 · '.$chapter->title">
        <p class="xn-status" data-tone="warning">{{ $chapter->canonical_artifact_id ? '正式章节' : '草稿预览 · 尚未正式采用' }}</p>
        <p>版本 {{ $draft?->id ?? '待生成' }} · {{ $draft?->data['word_count'] ?? 0 }} 字 · {{ $review ? '独立审校：'.$review->decision->value : '等待当前稿独立审校' }}</p>
        @if($stale)<p role="alert">已有更新稿。当前展示的是打开页面时的版本；请先复制未保存内容，再刷新对照。</p>@endif
        <x-filament::link :href="\App\Filament\Pages\GenerationIssues::getUrl(['novel'=>$chapter->novel_id,'chapter'=>$chapter->id,'filter'=>'unresolved'])">查看本章异常与 AI 处理方案</x-filament::link>
    </x-filament::section>
    @if($draft)
        <article class="xn-book-reading" aria-label="章节草稿正文">
            @foreach($draft->data['paragraph_index'] as $paragraph)
                <p id="paragraph-{{ $paragraph['key'] }}" class="whitespace-pre-wrap">{{ mb_substr($draft->content,$paragraph['offset'],$paragraph['length']) }}</p>
            @endforeach
        </article>
        <details class="xn-book-details"><summary>版本与审校证据</summary>
            @if($review)
                <p>本结论仅适用于当前显示的稿件，保存新稿后必须复验。</p>
                @forelse($review->findings as $finding)
                    <section class="xn-book-finding"><strong>{{ $finding['severity'] ?? '待核对' }} · {{ $finding['message'] ?? $finding['code'] ?? '规则问题' }}</strong>
                        <p>{{ $finding['repair_instruction'] ?? '请核对事实约束，保存修正稿后重新验证。' }}</p>
                        @foreach(($finding['evidence'] ?? []) as $evidence)
                            @if(isset($evidence['paragraph_key']))<a href="#paragraph-{{ $evidence['paragraph_key'] }}">定位 {{ $evidence['paragraph_key'] }}</a> <q>{{ $evidence['quote'] }}</q>@endif
                        @endforeach
                    </section>
                @empty<p>当前审校未发现阻塞条目。</p>@endforelse
            @else<p>当前版本没有独立审校结论。</p>@endif
            @if($previous?->content)
                <div class="xn-book-comparison"><section><h3>修改前</h3><p class="whitespace-pre-wrap">{{ $previous->content }}</p></section><section><h3>当前新稿</h3><p class="whitespace-pre-wrap">{{ $draft->content }}</p></section></div>
            @else<p>这是首次完整草稿，没有前一稿可对照。</p>@endif
        </details>
        @unless($chapter->canonical_artifact_id)
            <x-filament::section heading="人工处理" description="修改当前完整草稿，保存后重新提取、校验和审校。不会直接覆盖正式故事。">
                <label for="book-draft-editor">新稿正文</label>
                <textarea id="book-draft-editor" wire:model="body" class="xn-book-editor" rows="16" aria-describedby="book-save-feedback"></textarea>
                @error('body')<p role="alert">{{ $message }}</p>@enderror
                <p id="book-save-feedback" role="status">{{ $this->feedback }}</p>
                <x-filament::button wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">保存新稿并复验</x-filament::button>
            </x-filament::section>
        @endunless
    @else<x-filament::section heading="还没有完整草稿"><p>当前章计划与已完成片段会保留。完整组装后可阅读，未来章节无需提前建立。</p><x-filament::link :href="\App\Filament\Resources\Novels\NovelResource::getUrl('planning',['record'=>$chapter->novel_id])">调整受影响目标并重建计划</x-filament::link>@foreach($fragments as $fragment)<details><summary>已保留片段 {{ $fragment->data['sequence'] }} · {{ $fragment->data['complete'] ? '片段完整' : '需要续写' }}</summary><p class="whitespace-pre-wrap">{{ $fragment->content }}</p></details>@endforeach</x-filament::section>@endif
</div>
