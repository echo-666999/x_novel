@if ($diff === null)
    <div class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">尚无可比较的 Rewrite Draft。</div>
@else
    <div class="space-y-5">
        <div class="grid gap-3 md:grid-cols-2">
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Before · Artifact #{{ $diff['before']->id }}</div>
                <div class="mt-1 text-sm text-gray-950 dark:text-white">Original / 上一版本 · {{ mb_strlen($diff['before']->content ?? '') }} 字</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">After · Rewrite #{{ $diff['after']->version }}</div>
                <div class="mt-1 text-sm text-gray-950 dark:text-white">{{ data_get($diff['after']->data, 'scope') === 'scene' ? 'Scene' : '整章' }} · {{ mb_strlen($diff['after']->content ?? '') }} 字</div>
            </div>
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">正文差异</h4>
                <span class="text-xs text-gray-500 dark:text-gray-400">绿色新增 · 红色删除</span>
            </div>
            <div class="overflow-hidden rounded-lg border border-gray-200 font-mono text-xs leading-6 dark:border-white/10">
                @foreach ($diff['lines'] as $line)
                    <div @class([
                        'grid grid-cols-[2rem_1fr] border-b border-gray-200 px-3 py-1 last:border-b-0 dark:border-white/10',
                        'bg-success-50 text-success-800 dark:bg-success-500/10 dark:text-success-300' => $line['type'] === 'added',
                        'bg-danger-50 text-danger-800 dark:bg-danger-500/10 dark:text-danger-300' => $line['type'] === 'removed',
                        'text-gray-600 dark:text-gray-400' => $line['type'] === 'unchanged',
                    ])>
                        <span aria-hidden="true">{{ $line['type'] === 'added' ? '+' : ($line['type'] === 'removed' ? '−' : ' ') }}</span>
                        <span class="whitespace-pre-wrap break-words">{{ $line['text'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div>
            <h4 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">Finding 修复状态</h4>
            <div class="space-y-2">
                @forelse ($diff['findings'] as $finding)
                    <div class="flex items-start justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div>
                            <p class="text-sm text-gray-950 dark:text-white">{{ $finding['message'] ?? '未命名 Finding' }}</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $finding['evidence'] ?? $finding['code'] ?? '无证据' }}</p>
                        </div>
                        <x-filament::badge :color="match ($finding['resolution']) { 'resolved' => 'success', 'unresolved' => 'danger', default => 'warning' }">
                            {{ match ($finding['resolution']) { 'resolved' => '已解决', 'unresolved' => '未解决', default => '待复审' } }}
                        </x-filament::badge>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">来源 Review 没有 Findings。</p>
                @endforelse
            </div>
        </div>
    </div>
@endif
