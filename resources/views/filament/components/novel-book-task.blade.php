@php
    $novel = $getRecord();
    $task = \App\Models\NovelGenerationTask::findOrFail($novel->current_generation_task_id);
    $intent = \App\Models\GenerationArtifact::findOrFail($task->intent_artifact_id);
    $candidate = \App\Models\GenerationArtifact::find(data_get($task->progress_snapshot, 'foundation_artifact_id'));
    // 旧输入候选保留在历史中，不能伪装成当前概述的解析结果。
    if ($candidate && data_get($candidate->generationRun->execution_contract, 'intent_artifact_id') !== $task->intent_artifact_id) { $candidate = null; }
    $run = \App\Models\GenerationRun::find(data_get($task->pending_transition, 'run_id'));
@endphp
<div class="xn-stack" wire:poll.10s>
    <x-filament::section heading="这本书正在进行到哪里">
        <p>{{ \App\Services\NovelIntentContract::MODES[$task->mode] }} · 目标 {{ number_format($task->target_words) }} 正文字数</p>
        <p><strong>{{ match ($task->status) { 'queued' => '已创建，等待开始', 'running' => $run?->status === \App\Enums\RunStatus::Running ? '阶段正在执行' : '等待下一阶段', 'paused' => '已暂停', 'needs_attention' => '需要处理', default => $task->status } }}</strong></p>
        <p>{{ data_get($task->stop_detail, 'message', '任务已保存。执行进度来自持久记录；没有执行记录时不会显示模型正在生成。') }}</p>
        @if($task->current_chapter_id)
        <x-filament::link :href="\App\Filament\Resources\Novels\NovelResource::getUrl('book-chapter',['record'=>$novel,'chapter'=>$task->current_chapter_id])">阅读当前章草稿与审校证据</x-filament::link>
        @endif
        <p>正式章节：{{ $novel->chapters()->where('status', 'canonical')->count() }} · 模式和费用策略在设置中可调整。</p>
    </x-filament::section>
    <x-filament::section heading="原始创作概述"><p class="whitespace-pre-wrap break-words">{{ $intent->data['premise'] }}</p></x-filament::section>
    @if ($candidate)
        <x-filament::section heading="基础设定候选" description="仅为候选；蓝图和近期目标通过后才自动初始化。">
            <dl class="xn-stack">
                @foreach (\App\Services\NovelFoundationContract::FIELDS as $name)
                    @php($field = $candidate->data['fields'][$name])
                    <div><dt>{{ ['title'=>'书名','genre'=>'题材','era'=>'时代','protagonist'=>'主角','world'=>'世界','conflict'=>'核心矛盾','plot'=>'主线方向','style'=>'文风','ending'=>'结局方向'][$name] }} · {{ $field['source'] === 'user' ? '用户明确提供' : 'AI 补充' }}</dt><dd>{{ $field['value'] }}</dd></div>
                @endforeach
            </dl>
        </x-filament::section>
    @endif
</div>
