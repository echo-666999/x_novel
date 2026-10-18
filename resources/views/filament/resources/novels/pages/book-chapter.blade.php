{{-- Schema 更新会替换操作节点；反馈渲染后恢复触发按钮焦点，不滚动或清空输入。 --}}
<div class="xn-stack" @book-edit-feedback.window="$nextTick(() => document.getElementById($event.detail.target)?.focus({preventScroll: true}))" x-data="{
    size: 19, leading: 1.95, width: 42, focus: false, dirty: false, readingKey: null,
    init() { try { const p=JSON.parse(localStorage.getItem('xn-reader') || '{}'); this.size=p.size || 19; this.leading=p.leading || 1.95; this.width=p.width || 42; this.readingKey='xn-reading-'+this.$el.dataset.chapter; const y=Number(localStorage.getItem(this.readingKey) || 0); this.$nextTick(() => window.scrollTo(0,y)); } catch(e) {} },
    save() { try { localStorage.setItem('xn-reader',JSON.stringify({size:this.size,leading:this.leading,width:this.width})); } catch(e) {} }
}" data-chapter="{{ $chapter->id }}" @keydown.escape.window="focus=false" @book-draft-saved.window="dirty=false" @scroll.window.throttle.600ms="if(readingKey &amp;&amp; !focus) { try { localStorage.setItem(readingKey,String(window.scrollY)); } catch(e) {} }" :class="{ 'xn-reader-focus': focus }" @beforeunload.window="if(dirty) { $event.preventDefault(); $event.returnValue=''; }">
    <div class="xn-reader-toolbar">
        <label>字号 <input type="range" min="16" max="28" step="1" x-model="size" @change="save()" aria-label="正文字号"></label>
        <label>行距 <input type="range" min="1.5" max="2.5" step="0.1" x-model="leading" @change="save()" aria-label="正文行距"></label>
        <label>栏宽 <input type="range" min="28" max="56" step="2" x-model="width" @change="save()" aria-label="正文栏宽"></label>
        <button type="button" class="xn-reader-control" @click="focus=!focus" :aria-pressed="focus" x-text="focus ? '退出专注阅读' : '专注阅读'"></button>
    </div>
    {{-- 保留用户展开状态；只忽略 details 自身属性，目录内容仍由服务端分页更新。 --}}
    <details class="xn-book-details" wire:ignore.self><summary>章节目录 · 每页 25 章</summary>
        <label for="chapter-search">搜索章节标题</label><input id="chapter-search" type="search" wire:model.live.debounce.400ms="directorySearch" class="xn-reader-control">
        <nav aria-label="章节目录" class="xn-stack">
        @foreach($directory as $item)
            <a class="xn-reader-control" href="{{ \App\Filament\Resources\Novels\NovelResource::getUrl('book-chapter',['record'=>$chapter->novel_id,'chapter'=>$item->id]) }}" @click="if(dirty &amp;&amp; !confirm('有未保存的修改，确定离开？')) $event.preventDefault()">第 {{ $item->sequence }} 章 {{ $item->title }} · {{ $item->canonical_artifact_id ? '正式' : '草稿' }}</a>
        @endforeach
        </nav>
        <p>第 {{ $directory->currentPage() }} / {{ $directory->lastPage() }} 页</p>
        <x-filament::button color="gray" wire:click="$set('directoryPage', {{ max(1,$directory->currentPage()-1) }})" :disabled="$directory->onFirstPage()">上一页目录</x-filament::button>
        <x-filament::button color="gray" wire:click="$set('directoryPage', {{ $directory->currentPage()+1 }})" :disabled="!$directory->hasMorePages()">下一页目录</x-filament::button>
    </details>
    <x-filament::section :heading="'第 '.$chapter->sequence.' 章 · '.$chapter->title">
        <p class="xn-status" data-tone="warning">{{ $draft && $chapter->canonical_artifact_id === $draft->id ? '正式章节' : '草稿预览 · 尚未正式采用' }}</p>
        <p>版本 {{ $draft?->id ?? '待生成' }} · {{ $draft?->data['word_count'] ?? 0 }} 字 · {{ $review ? '独立审校：'.$review->decision->value : '等待当前稿独立审校' }}</p>
        @if($stale)<p role="alert">已有更新稿。当前展示的是打开页面时的版本；请在人工编辑区选择“保留输入并读取最新稿”后对照。</p>@endif
        <x-filament::link :href="\App\Filament\Pages\GenerationIssues::getUrl(['novel'=>$chapter->novel_id,'chapter'=>$chapter->id,'filter'=>'unresolved'])">查看本章异常与 AI 处理方案</x-filament::link>
    </x-filament::section>
    @if($draft)
        @if($chapter->canonical_artifact_id)
            <div class="xn-reader-toolbar"><x-filament::button color="gray" wire:click="$set('version','canonical')">阅读正式版</x-filament::button><x-filament::button color="gray" wire:click="$set('version','draft')">对照生成稿</x-filament::button></div>
        @endif
        {{-- 阅读偏好以默认 16px 为基准，同时尊重浏览器的纯字体放大设置。 --}}
        <article class="xn-book-reading" :style="{fontSize:(size/16)+'rem',lineHeight:leading,maxWidth:width+'rem'}" aria-label="章节正文">
            @foreach($draft->data['paragraph_index'] as $paragraph)
                <p id="paragraph-{{ $paragraph['key'] }}" class="whitespace-pre-wrap">{{ mb_substr($draft->content,$paragraph['offset'],$paragraph['length']) }}</p>
            @endforeach
        </article>
        <details class="xn-book-details" wire:ignore.self><summary>版本与审校证据</summary>
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
            <details class="xn-book-details" wire:ignore.self><summary>人工编辑当前草稿</summary><x-filament::section heading="人工处理" description="修改当前完整草稿，保存后重新提取、校验和审校。不会直接覆盖正式故事。">
                <label for="book-draft-editor">新稿正文</label>
                <textarea id="book-draft-editor" wire:model="body" @input="dirty=true" class="xn-book-editor" rows="16" aria-describedby="book-save-feedback"></textarea>
                @error('body')<p role="alert">{{ $message }}</p>@enderror
                <p id="book-save-feedback" role="status">{{ $this->feedback }}</p>
                <x-filament::button id="book-save-draft" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">保存新稿并复验</x-filament::button>
                {{-- 显式刷新编辑来源，避免冲突后只能整页刷新而丢失输入。 --}}
                <x-filament::button id="book-refresh-source" color="gray" wire:click="refreshEditingSource" wire:loading.attr="disabled">保留输入并读取最新稿</x-filament::button>
            </x-filament::section></details>
        @endunless
        <nav class="xn-reader-toolbar" aria-label="相邻章节">@foreach($nearby as $item)<a class="xn-reader-control" href="{{ \App\Filament\Resources\Novels\NovelResource::getUrl('book-chapter',['record'=>$chapter->novel_id,'chapter'=>$item->id]) }}">{{ $item->sequence < $chapter->sequence ? '上一章' : '下一章' }} · {{ $item->title }}</a>@endforeach</nav>
    @else<x-filament::section heading="还没有完整草稿"><p>当前章计划与已完成片段会保留。完整组装后可阅读，未来章节无需提前建立。</p><x-filament::link :href="\App\Filament\Resources\Novels\NovelResource::getUrl('planning',['record'=>$chapter->novel_id])">调整受影响目标并重建计划</x-filament::link>@foreach($fragments as $fragment)<details><summary>已保留片段 {{ $fragment->data['sequence'] }} · {{ $fragment->data['complete'] ? '片段完整' : '需要续写' }}</summary><p class="whitespace-pre-wrap">{{ $fragment->content }}</p></details>@endforeach</x-filament::section>@endif
</div>
