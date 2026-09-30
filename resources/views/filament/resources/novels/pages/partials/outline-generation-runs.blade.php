@php
    $statusColor = fn (string $status): string => match ($status) {
        'queued', 'running' => 'info',
        'failed' => 'danger',
        'succeeded' => 'success',
        default => 'gray',
    };
@endphp

<div class="space-y-6">
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">冻结路由</div>
            <div class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ $progress->provider ?? '—' }} · {{ $progress->model ?? '—' }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">reasoning: {{ $progress->reasoningEffort ?? '—' }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">批次合同</div>
            <div class="mt-1 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $progress->batchPromptVersion ?? '—' }}</div>
        </div>
    </div>

    @if ($progress->technicalError)
        <div class="rounded-lg border border-danger-200 p-3 dark:border-danger-500/30">
            <div class="text-xs font-medium text-danger-700 dark:text-danger-300">技术错误</div>
            <div class="mt-2 break-words font-mono text-xs leading-5 text-gray-700 dark:text-gray-300">{{ $progress->technicalError }}</div>
        </div>
    @endif

    <div class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Runs</h3>
        @foreach ($progress->runs as $run)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="font-mono text-xs text-gray-700 dark:text-gray-300">Run #{{ $run['id'] }} · {{ $run['scope'] }}</div>
                    <x-filament::badge :color="$statusColor($run['status'])">{{ $run['status'] }}</x-filament::badge>
                </div>
                <div class="mt-3 grid gap-2 text-xs text-gray-500 dark:text-gray-400 sm:grid-cols-2">
                    <div>区分项：<span class="font-mono">{{ $run['discriminator'] ?? '—' }}</span></div>
                    <div>尝试：<span class="tabular-nums">{{ $run['attempt'] }}</span></div>
                    <div>开始：{{ $run['started_at'] ?? '—' }}</div>
                    <div>完成：{{ $run['finished_at'] ?? '—' }}</div>
                    <div>Provider / Model：{{ $run['provider'] ?? '—' }} / {{ $run['model'] ?? '—' }}</div>
                    <div>Prompt：<span class="font-mono">{{ $run['prompt_version'] ?? '—' }}</span></div>
                    <div>Tokens：<span class="tabular-nums">{{ number_format(($run['usage']['input_tokens'] ?? 0) + ($run['usage']['output_tokens'] ?? 0)) }}</span></div>
                    <div>Cost：<span class="tabular-nums">{{ number_format((float) ($run['usage']['estimated_cost'] ?? 0), 4) }}</span></div>
                </div>
                @if ($run['error_code'])
                    <div class="mt-2 font-mono text-xs text-danger-600 dark:text-danger-400">{{ $run['error_code'] }}</div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Artifacts</h3>
        @forelse ($progress->artifacts as $artifact)
            <div class="grid gap-2 rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10 sm:grid-cols-[auto_minmax(0,1fr)]">
                <div class="font-mono text-gray-700 dark:text-gray-300">#{{ $artifact['id'] }} · {{ $artifact['type'] }} · v{{ $artifact['version'] }}</div>
                <div class="truncate text-right font-mono text-gray-500 dark:text-gray-400" title="{{ $artifact['checksum'] }}">{{ $artifact['checksum'] }}</div>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">当前批次尚无 Artifact。</p>
        @endforelse
    </div>
</div>
