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
        <p><strong>{{ match ($task->status) { 'queued' => '已创建，等待开始', 'running' => $run?->status === \App\Enums\RunStatus::Running ? '阶段正在执行' : '等待下一阶段', 'paused' => '已暂停', 'needs_attention' => '需要处理', 'completed' => '正文与导出已完成', 'cancelled' => '已取消', 'failed' => '失败待核对', default => $task->status } }}</strong></p>
        <p>{{ data_get($task->stop_detail, 'message', '任务已保存。执行进度来自持久记录；没有执行记录时不会显示模型正在生成。') }}</p>
        @if($task->current_chapter_id)
        <x-filament::link :href="\App\Filament\Resources\Novels\NovelResource::getUrl('book-chapter',['record'=>$novel,'chapter'=>$task->current_chapter_id])">阅读当前章与审校证据</x-filament::link>
        @endif
        <p>正式章节：{{ $novel->chapters()->where('status', 'canonical')->count() }} · 模式和费用策略在设置中可调整。</p>
    </x-filament::section>
    <x-filament::section heading="进度与费用">
        <p>当前阶段：{{ \App\Enums\GenerationStage::tryFrom($task->current_phase ?? '')?->getLabel() ?? '等待开始' }} · 最后进展：{{ $task->last_progress_at?->format('Y-m-d H:i:s') ?? '尚无完成记录' }}</p>
        <p>正式正文 {{ number_format($novel->chapters()->where('status','canonical')->sum('word_count')) }} 字。费用用于统计优化；仅主动启用的硬上限会阻止请求。</p>
        @php($costSummary = $this->bookCostSummary())
        <p>当前整书任务累计；已对账的实际费用优先，其余使用估计费用，各币种分别统计。</p>
        @forelse($costSummary['rows'] as $cost)
            <p>{{ $cost->currency }} · {{ $cost->total === null ? '尚无已知金额' : '已知累计 '.number_format((float) $cost->total, 4) }} · 未确认费用 {{ $cost->unknown_count }} 笔</p>
        @empty
            <p>尚无模型请求费用。</p>
        @endforelse
        @if($costSummary['threshold'] !== null)
            <p>费用预警：{{ $costSummary['currency'] }} {{ number_format((float) $costSummary['threshold'], 4) }}（{{ $costSummary['source'] }}，按当前整书任务累计）。未确认费用不按零计算，也不折算其他币种。</p>
            @if($costSummary['reached'])
                <p role="status"><strong>已达费用预警</strong> · 此提醒不暂停生成；原任务状态和已启用的硬上限继续生效。</p>
            @endif
        @endif
        @if($task->export_manifest_id)<x-filament::link :href="route('book-exports.download',$task->export_manifest_id)">下载完结正文 TXT</x-filament::link>@elseif($novel->status->value==='completed')<p role="status">正文已通过完结核验，整书任务等待导出完成。</p>@endif
        <details><summary>最近执行活动</summary><ol>@foreach(\App\Models\GenerationRun::where('generation_task_id',$task->id)->latest('id')->limit(5)->get(['id','stage','status','finished_at','error_message']) as $activity)<li>{{ $activity->stage->getLabel() }} · {{ $activity->status->getLabel() }} · {{ $activity->finished_at?->format('H:i:s') ?? '尚未完成' }}@if($activity->error_message)<p>{{ app(\App\Support\GenerationDiagnosticRedactor::class)->clean($activity->error_message) }}</p>@endif</li>@endforeach</ol></details>
    </x-filament::section>
    <x-filament::section heading="原始创作概述"><p class="whitespace-pre-wrap break-words">{{ $intent->data['premise'] }}</p></x-filament::section>
    @if ($candidate)
        <x-filament::section :heading="$novel->currentBible ? '已采用的基础设定' : '基础设定候选'" :description="$novel->currentBible ? '用户要求与 AI 补充分别标注；修改未来方向请进入规划。' : '蓝图和近期目标通过后自动初始化。'">
            <dl class="xn-stack">
                @foreach (\App\Services\NovelFoundationContract::FIELDS as $name)
                    @php($field = $candidate->data['fields'][$name])
                    <div><dt>{{ ['title'=>'书名','genre'=>'题材','era'=>'时代','protagonist'=>'主角','world'=>'世界','conflict'=>'核心矛盾','plot'=>'主线方向','style'=>'文风','ending'=>'结局方向'][$name] }} · {{ $field['source'] === 'user' ? '用户明确提供' : 'AI 补充' }}</dt><dd>{{ $field['value'] }}</dd></div>
                @endforeach
            </dl>
        </x-filament::section>
    @endif
</div>
