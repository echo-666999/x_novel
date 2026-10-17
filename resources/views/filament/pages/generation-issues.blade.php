<x-filament-panels::page>
    {{-- 问题和双处理路径来自持久事实，刷新不会丢失历史；所有模型文本按普通文本转义。 --}}
    <div class="xn-issue-center xn-stack">
        <div class="xn-heading-row">
            <p class="xn-muted">先看影响，再选择处理方式。已有正文与失败证据始终保留。</p>
            <x-filament::button color="gray" tag="a" :href="\App\Filament\Pages\Review::getUrl()">章节审校记录</x-filament::button>
        </div>
        @if (! $this->isReady())
            <div class="xn-notice xn-tone-warning">新版问题表尚未部署。请先完成 R01 数据库迁移；当前没有查询或修改旧小说。</div>
        @else
            <div class="xn-heading-row">
                <label class="xn-field">查看范围
                    <select wire:model.live="filter" class="xn-control">
                        <option value="awaiting_user">待你处理</option><option value="automatic">自动处理中与提醒</option>
                        <option value="unresolved">全部未解决</option><option value="resolved">已解决历史</option>
                    </select>
                </label>
                @if($novel)<p class="xn-muted">已限定小说 #{{ $novel }} @if($chapter) · 章节 #{{ $chapter }} @endif</p>@endif
                <x-filament::button color="gray" wire:click="$refresh">刷新状态</x-filament::button>
            </div>
            <div class="xn-issue-layout">
                <section class="xn-stack" aria-label="问题列表">
                    @php($issues = $this->issues())
                    @forelse($issues as $item)
                        <button type="button" wire:click="selectIssue({{ $item->id }})" class="xn-panel xn-issue-item" @if($selectedIssueId === $item->id) aria-current="true" @endif>
                            <span class="xn-heading-row"><strong>{{ $item->novel->title }}</strong>@include('filament.components.foundation.status', ['status' => $item->status])</span>
                            <span>{{ $item->user_message }}</span>
                            <span class="xn-muted">问题 #{{ $item->id }} · {{ \App\Enums\GenerationStage::tryFrom($item->source_identity['stage'] ?? '')?->getLabel() ?? '阶段待核实' }} · {{ $item->last_seen_at?->timezone(config('app.timezone'))->format('m-d H:i') }}</span>
                        </button>
                    @empty
                        <div class="xn-panel xn-stack"><h2>此范围没有问题</h2><p class="xn-muted">可以切换到全部未解决或已解决历史查看其他记录。</p></div>
                    @endforelse
                    {{ $issues->links() }}
                </section>
                <section class="xn-stack" aria-label="问题详情">
                    @if($selected = $this->selected())
                        @php($details = $this->details($selected))
                        <div class="xn-panel xn-stack">
                            <div class="xn-heading-row"><h2>问题 #{{ $selected->id }}</h2>@include('filament.components.foundation.status', ['status' => $selected->status])</div>
                            <h3>{{ $selected->user_message }}</h3>
                            <p>{{ $selected->status === 'resolved' ? '此为已解决历史，原失败不再作为当前告警。' : (data_get($details, 'evidence.policy.blocking') ? '影响当前生成断点；处理成功后按原模式继续。' : '这是非阻塞提醒，默认继续生成。') }}</p>
                            <p class="xn-muted">已保存内容：已有正式章节、草稿及失败 Run 均保留。处理不直接改写正式历史。</p>
                            @if($selected->status !== 'resolved')
                            <div class="xn-notice xn-tone-warning">{{ data_get($details, 'evidence.policy.suggestion') }}</div>
                            @if(data_get($details, 'evidence.policy.setting'))
                                <x-filament::button color="gray" tag="a" :href="$this->settingUrl($selected)">打开相关设置 · {{ data_get($details, 'evidence.policy.setting') === 'connections' ? '连接与凭据引用' : (data_get($details, 'evidence.policy.setting') === 'prices' ? '模型容量与价格' : '生成策略与费用') }}</x-filament::button>
                            @endif
                            @endif
                            @if($details['source_error'])<p role="alert" class="xn-notice xn-tone-warning">{{ $details['source_error'] }} 人工输入已保留，不能采用旧方案。</p>@endif
                            <dl class="xn-issue-metrics">
                                <div><dt>累计处理尝试</dt><dd>{{ $selected->attempt_count }} / {{ data_get($selected->repair_limit_snapshot, 'max_attempts', 2) }}</dd></div>
                                <div><dt>累计实际执行</dt><dd>{{ round($selected->repair_elapsed_ms / 1000, 1) }} / {{ data_get($selected->repair_limit_snapshot, 'max_elapsed_ms', 1800000) / 1000 }} 秒</dd></div>
                                <div><dt>总截止时间</dt><dd>{{ $selected->deadline_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '未设置' }}</dd></div>
                            </dl>
                            <div class="xn-stack"><h3>已发生费用</h3>
                                @forelse($this->costs($selected) as $cost)
                                    <p>{{ \App\Filament\Support\GenerationPresentation::amount($cost['known'], $cost['currency']) }}（已知实际/估算合计） · {{ $cost['unknown'] }} 笔待确认 · 在途占用 {{ \App\Filament\Support\GenerationPresentation::amount($cost['reserved'], $cost['currency']) }}</p>
                                @empty <p class="xn-muted">尚无关联收费请求记录。</p>@endforelse
                                <p class="xn-muted">费用用于统计。只有用户主动启用的硬上限会阻止新请求；范围剩余额度以设置及发送前账本复核为准。</p>
                            </div>
                        </div>
                        <div class="xn-panel xn-stack">
                            <h3>失败证据与处理进展</h3>
                            @foreach(data_get($details, 'evidence.occurrences', []) as $occurrence)
                                <p><strong>Run #{{ $occurrence['run_id'] }}</strong> · {{ $occurrence['message'] }}</p>
                            @endforeach
                            @foreach(data_get($details, 'evidence.policy.fields', []) as $field => $problem)
                                <p>字段 {{ $field }}：{{ is_scalar($problem) ? $problem : json_encode($problem, JSON_UNESCAPED_UNICODE) }}</p>
                            @endforeach
                            @forelse(data_get($details, 'evidence.attempts', []) as $attemptRunId => $attempt)
                                <p>处理 Run #{{ $attemptRunId }} · {{ ($attempt['action'] ?? '') === 'diagnose' ? 'AI 只读分析' : '原断点重试' }} · {{ empty($attempt['finished_at']) ? '排队或执行中' : (($attempt['status'] ?? '') === 'succeeded' ? '执行结束，查看复验结论' : '本次处理失败，原问题保留') }}</p>
                                @if(!empty($attempt['error']))<p class="xn-muted">{{ $attempt['error'] }}</p>@endif
                            @empty <p class="xn-muted">尚未执行修复尝试。</p>@endforelse
                            @foreach($details['operations'] as $operation)
                                <p>人工记录 #{{ $operation['id'] }}：{{ data_get($operation, 'data.instructions') }}</p>
                            @endforeach
                        </div>
                        @if($details['proposal'])
                            <div class="xn-panel xn-stack"><h3>AI 分析建议 · 尚未采用</h3><p>{{ data_get($details, 'proposal.proposal.explanation') }}</p><p>{{ data_get($details, 'proposal.proposal.impact') }}</p><p>建议动作：{{ \App\Services\GenerationIssueCatalog::actionLabel(data_get($details, 'proposal.proposal.action', '')) }}。可执行范围以当前服务端按钮为准。</p><p class="xn-muted">成功条件：{{ data_get($details, 'proposal.proposal.verification') }}</p></div>
                        @endif
                        @if($selected->status !== 'resolved')
                            <div class="xn-panel xn-stack">
                                <h3>选择处理方式</h3>
                                <label class="xn-field" for="issue-instructions">人工处理说明 / AI 补充要求</label>
                                <textarea id="issue-instructions" class="xn-control" rows="5" maxlength="6000" wire:model.blur="instructions" aria-describedby="issue-input-hint"></textarea>
                                <p id="issue-input-hint" class="xn-muted">记录已检查的字段、现象或修改要求。此处不编辑正文；正文和计划编辑会在对应领域批次接入。</p>
                                @error('instructions')<p role="alert">{{ $message }}</p>@enderror
                                @php($options = $this->options($selected))
                                @foreach($options as $option)
                                    @if($loop->index === 3)<details class="xn-evidence"><summary>更多操作</summary>@endif
                                    <div class="xn-stack xn-issue-option">
                                        <x-filament::button :color="$option['action'] === 'diagnose' ? 'primary' : 'gray'" :disabled="! $option['available']" wire:click="prepare('{{ $option['action'] }}')" wire:loading.attr="disabled">
                                            {{ $option['label'] }}@if($option['available'] && $loop->index === collect($options)->search(fn ($entry) => $entry['available'])) · 推荐@endif
                                        </x-filament::button>
                                        <p class="xn-muted">{{ $option['available'] ? $option['impact'].' '.$option['cost'] : $option['reason'] }}</p>
                                    </div>
                                    @if($loop->last && count($options) > 3)</details>@endif
                                @endforeach
                                @if($actionPreview)
                                    <div class="xn-notice xn-tone-info xn-stack"><h4>执行前预览</h4><p>{{ $actionPreview['impact'] }}</p><p>{{ $actionPreview['cost'] }}</p><p>来源执行 #{{ $actionPreview['source_run_id'] }} · 仅当前问题，保留既有内容。</p>
                                        <x-filament::button wire:click="submit" wire:loading.attr="disabled" wire:target="submit">执行当前方案</x-filament::button>
                                    </div>
                                @endif
                            </div>
                        @else
                            <div class="xn-notice xn-tone-success">{{ $selected->resolution_reason }} · 解决执行 #{{ $selected->resolved_by_run_id }}，历史失败仍可查询。</div>
                        @endif
                        @if($feedback)<div role="status" aria-live="polite" class="xn-notice xn-tone-info">{{ $feedback }}</div>@endif
                        <details class="xn-panel xn-stack xn-evidence"><summary>证据、尝试历史与技术详情 · {{ $selected->error_code }}</summary>
                            <p>Issue #{{ $selected->id }} · 首次 Run #{{ $selected->first_run_id }} · 最近 Run #{{ $selected->latest_run_id }}</p>
                            <x-filament::button color="gray" wire:click="downloadDiagnostic">下载脱敏诊断包</x-filament::button>
                            <pre>{{ json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    @else
                        <div class="xn-panel xn-stack"><h2>选择一项问题</h2><p class="xn-muted">查看已保存内容、实际证据和两条处理路径。</p></div>
                    @endif
                </section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
