@php
    $novel = $novel->fresh();
    $task = \App\Models\NovelGenerationTask::findOrFail($novel->current_generation_task_id);
    $blueprint = \App\Models\NovelOutline::find($novel->current_blueprint_id);
    $window = \App\Models\NovelOutline::find($novel->current_goal_window_id);
    $chapter = \App\Models\Chapter::find($task->current_chapter_id);
    $plan = \App\Models\ChapterPlan::find($chapter?->current_plan_id);
    $foundation = \App\Models\GenerationArtifact::find(data_get($task->progress_snapshot, 'foundation_artifact_id'));
@endphp
<div class="xn-stack">
    <nav aria-label="设定与计划层级" class="flex flex-wrap gap-4"><a href="#book-settings">故事设定</a><a href="#book-blueprint">全书蓝图</a><a href="#book-window">近期目标</a><a href="#book-plan">当前章计划</a></nav>
    <p>全书方向 → 近期目标 → 当前章计划。远期章节保持未细化，系统随正式进度滚动规划。</p>
    <p role="status">{{ $this->feedback }}</p>
    <x-filament::section id="book-blueprint" heading="全书蓝图" :description="$blueprint ? '版本 '.$blueprint->version : '尚未生成'">
        @if($blueprint)
            <p>{{ $blueprint->sourceArtifact->data['mainline'] }}</p>
            <x-filament::button color="gray" wire:click="editDirection">调整未来方向</x-filament::button>
            @if($this->editingDirection)
                <p>当前已采用方向：{{ $blueprint->sourceArtifact->data['mainline'] }}</p>
                <label for="future-direction">修改后的未来主线方向</label><textarea id="future-direction" wire:model.live.debounce.500ms="futureDirection" rows="5" class="xn-book-editor"></textarea>
                <p>修改后：{{ $this->futureDirection }}</p><p>影响：当前章继续，章节边界重建未执行目标。原始硬要求、正式历史与人物事实不变。</p>
                <x-filament::button wire:click="saveDirection" wire:loading.attr="disabled">保存未来方向修订</x-filament::button>
                <x-filament::button color="gray" wire:click="refreshDirectionSource" wire:loading.attr="disabled">保留输入并读取最新方向</x-filament::button>
                <x-filament::button color="gray" wire:click="$set('editingDirection', false)" wire:confirm="确定放弃尚未保存的方向输入？">放弃方向输入</x-filament::button>
            @endif
            @if(data_get($task->progress_snapshot,'pending_blueprint_id'))<p role="status">未来方向候选已保存，等待章节边界采用。</p>@endif
            <p>结局方向：{{ $blueprint->sourceArtifact->data['ending'] }}</p>
            <ol>@foreach($blueprint->sourceArtifact->data['allocation'] as $turn)<li>{{ $turn['objective'] }} · 约 {{ number_format($turn['target_words']) }} 字</li>@endforeach</ol>
            <details><summary>承诺、人物发展与伏笔</summary>
                @foreach($blueprint->sourceArtifact->data['reader_promises'] as $promise)<p>{{ $promise['description'] }}</p>@endforeach
                @foreach(['character_arcs'=>'人物发展','foreshadowing_goals'=>'伏笔目标','optional_volume_groups'=>'可选分卷'] as $field=>$label)<p>{{ $label }}：{{ implode('；',$blueprint->sourceArtifact->data[$field]) ?: '未设置，不影响开篇' }}</p>@endforeach
            </details>
        @else<p>基础设定通过后自动规划，不需要手填分卷或故事线。</p>@endif
    </x-filament::section>
    <x-filament::section id="book-window" heading="近期章节目标" :description="$window ? '已采用版本 '.$window->version : '等待首个目标窗口'">
        @if($window)
            @foreach($window->sourceArtifact->data['goals'] as $goal)
                <section class="xn-book-finding"><h3>第 {{ $goal['sequence'] }} 章 · {{ $goal['target_words'] }} 字目标</h3><p>{{ $goal['objective'] }}</p><p>预期变化：{{ $goal['expected_change'] }}</p>
                    <x-filament::button color="gray" wire:click="editGoal('{{ $goal['key'] }}')">修订此目标</x-filament::button>
                </section>
            @endforeach
            <p>窗口以外：尚未细化，生成至章节边界时自动补齐。</p>
            @if(data_get($task->progress_snapshot,'pending_window_id'))<p role="status">已有未来目标修订候选，当前稿仍按原来源完成。</p>@endif
        @endif
        @if($this->goalKey)
            <label for="goal-editor">目标内容</label><textarea id="goal-editor" wire:model="goalText" rows="5" class="xn-book-editor"></textarea>
            <label><input type="checkbox" wire:model="replanCurrent"> 若影响当前章，重建当前计划并保留旧稿历史</label>
            <x-filament::button wire:click="saveGoal" wire:loading.attr="disabled">保存目标修订</x-filament::button>
            <x-filament::button color="gray" wire:click="refreshGoalSource" wire:loading.attr="disabled">保留输入并读取最新目标</x-filament::button>
            <x-filament::button color="gray" wire:click="$set('goalKey', '')" wire:confirm="确定放弃尚未保存的目标输入？">放弃目标输入</x-filament::button>
        @endif
    </x-filament::section>
    <x-filament::section id="book-plan" heading="当前章计划">
        @if($plan)
            <h3>{{ $plan->chapter_title }}</h3><p>{{ $plan->chapter_function }}</p>
            @foreach(['character_change'=>'人物变化','pov'=>'视角','time_anchor'=>'时间','ending_point'=>'章末落点'] as $field=>$label)<p>{{ $label }}：{{ $plan->admission_snapshot['plan'][$field] }}</p>@endforeach
            <p>必须揭示：{{ implode('；',$plan->must_reveal) }}</p><p>禁止揭示：{{ implode('；',$plan->must_not_reveal) ?: '无新增限制' }}</p>
            <p>必需事实：{{ implode('；',$plan->required_facts) }}</p><p>禁止冲突：{{ implode('；',$plan->forbidden_conflicts) }}</p>
            <details><summary>来源与容量策略</summary><p>正式状态 {{ $plan->source_state_version_id }} · 目标版本 {{ $plan->goal_window_id }}</p><p>{{ $plan->admission_snapshot['reason'] }}</p><p>片段数 {{ $plan->admission_snapshot['fragment_count'] }} · 写作输出预留 {{ $plan->admission_snapshot['writer_output_tokens'] }} tokens</p>
                @foreach($plan->admission_snapshot['routes'] as $phase=>$route)<p>{{ ['write'=>'写作','extract'=>'提取','review'=>'审校','rewrite'=>'修复'][$phase] }}：{{ $route['provider'] }} / {{ $route['model'] }}</p>@endforeach
            </details>
        @else<p>当前章尚未通过规划与容量预检，不展示虚构计划。</p>@endif
    </x-filament::section>
    @if($foundation)
    <x-filament::section id="book-settings" heading="创作要求与设定来源">
        @foreach($foundation->data['fields'] as $field)<p>{{ $field['source']==='user' ? '用户明确提供' : '系统补充' }} · {{ $field['value'] }}</p>@endforeach
        @foreach($novel->facts()->where('locked',true)->get() as $fact)<p>人工锁定 · {{ $fact->subjectLabel() }} · {{ $fact->predicate }} · {{ $fact->valueSummary() }}</p>@endforeach
    </x-filament::section>
    @endif
</div>
