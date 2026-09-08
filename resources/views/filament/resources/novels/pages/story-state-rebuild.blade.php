<div class="space-y-4">
    <div class="rounded-xl border p-4 {{ $result->matches() ? 'border-success-200 bg-success-50 dark:border-success-500/30 dark:bg-success-500/10' : 'border-danger-200 bg-danger-50 dark:border-danger-500/30 dark:bg-danger-500/10' }}">
        <div class="flex items-center gap-3">
            <x-filament::icon
                :icon="$result->matches() ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
                class="size-6 {{ $result->matches() ? 'text-success-600' : 'text-danger-600' }}"
            />
            <div>
                <p class="font-semibold text-gray-950 dark:text-white">
                    {{ $result->matches() ? '故事状态校验通过' : '故事状态存在差异' }}
                </p>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    从 State Version 0 重放 {{ $result->replayedEventCount }} 个有效事件；本次仅校验，不写入正式状态。
                </p>
            </div>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach (['当前 checksum' => $result->currentChecksum, '重建 checksum' => $result->rebuiltChecksum] as $label => $checksum)
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <code class="mt-2 block break-all font-mono text-xs text-gray-800 dark:text-gray-200">{{ $checksum }}</code>
            </div>
        @endforeach
    </div>

    @if ($result->changes === [])
        <p class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">
            当前状态内容与重建状态没有字段差异。
        </p>
    @else
        <div class="max-h-80 overflow-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full divide-y divide-gray-200 text-left text-sm dark:divide-white/10">
                <thead class="sticky top-0 bg-gray-50 text-xs text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                    <tr><th class="px-4 py-3">路径</th><th class="px-4 py-3">当前值</th><th class="px-4 py-3">重建值</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($result->changes as $change)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-mono text-xs">{{ $change['path'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $change['before_missing'] ? '（不存在）' : json_encode($change['before'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $change['after_missing'] ? '（不存在）' : json_encode($change['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
