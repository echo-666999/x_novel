@php
    $statusColor = match ($progress->pageStatus) {
        'queued', 'running' => 'info',
        'retrying' => 'warning',
        'failed' => 'danger',
        'succeeded' => 'success',
        default => 'gray',
    };
    $stageStatusLabel = fn (string $status): string => match ($status) {
        'queued' => '排队中',
        'running' => '生成中',
        'retrying' => '等待重试',
        'failed' => '失败',
        'succeeded' => '已完成',
        'cancelled' => '已取消',
        default => '等待中',
    };
    $stageStatusColor = fn (string $status): string => match ($status) {
        'queued', 'running' => 'info',
        'retrying' => 'warning',
        'failed' => 'danger',
        'succeeded' => 'success',
        default => 'gray',
    };
    $currentStage = $progress->currentStage === null ? null : ($progress->stages[$progress->currentStage] ?? null);
@endphp

<x-filament::section>
    <x-slot name="heading">AI 大纲生成</x-slot>
    <x-slot name="description">进度来自 PostgreSQL 中的 Run 与不可变 Artifact，刷新页面不会丢失。</x-slot>
    <x-slot name="afterHeader">
        <x-filament::badge :color="$statusColor">{{ $progress->pageStatusLabel }}</x-filament::badge>
    </x-slot>

    <div class="space-y-5">
        @if ($progress->batchId === null)
            <p class="text-sm text-gray-600 dark:text-gray-400">尚未创建 AI 大纲生成批次。</p>
        @else
            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ $progress->currentStageLabel ?? $progress->pageStatusLabel }}
                            @if ($currentStage?->hasKnownTotal())
                                <span class="tabular-nums">{{ $currentStage->completed }} / {{ $currentStage->total }}</span>
                            @endif
                        </span>
                        <span class="font-mono text-xs text-gray-500 dark:text-gray-400">Batch #{{ $progress->batchId }}</span>
                    </div>
                    @if ($progress->currentItemLabel)
                        <p class="text-sm text-gray-700 dark:text-gray-300">当前：{{ $progress->currentItemLabel }}</p>
                    @endif
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($progress->latestAttempt !== null)
                            第 {{ $progress->latestAttempt }} 次尝试
                        @endif
                        @if ($elapsed !== null)
                            · {{ $progress->isActive() ? '已运行' : '耗时' }} {{ $elapsed }}
                        @endif
                    </p>
                </div>
                <div class="text-left text-xs text-gray-500 dark:text-gray-400 lg:text-right">
                    <div>{{ $progress->provider ?? '未记录 Provider' }} · {{ $progress->model ?? '未记录 Model' }}</div>
                    @if ($progress->reasoningEffort)
                        <div class="mt-1">reasoning: {{ $progress->reasoningEffort }}</div>
                    @endif
                </div>
            </div>

            <div class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
                @foreach ($progress->stages as $stage)
                    <div class="flex items-center justify-between gap-4 px-3 py-2.5">
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $stage->label }}</span>
                        <div class="flex items-center gap-2">
                            @if ($stage->hasKnownTotal())
                                <span class="font-mono text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $stage->completed }} / {{ $stage->total }}</span>
                            @endif
                            <x-filament::badge :color="$stageStatusColor($stage->status)">{{ $stageStatusLabel($stage->status) }}</x-filament::badge>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($progress->pageStatus === 'failed')
                <div class="rounded-lg border border-danger-200 bg-danger-50 p-4 dark:border-danger-500/30 dark:bg-danger-500/10">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="text-sm font-semibold text-danger-700 dark:text-danger-300">{{ $progress->errorMessage }}</div>
                        @if ($progress->errorCode)
                            <x-filament::badge color="danger">{{ $progress->errorCode }}</x-filament::badge>
                        @endif
                    </div>
                    <div class="mt-2 space-y-1 text-xs text-danger-700/80 dark:text-danger-200/80">
                        <p>失败位置：{{ $progress->currentStageLabel ?? '未知阶段' }}{{ $progress->currentItemLabel ? ' · '.$progress->currentItemLabel : '' }}</p>
                        <p>自动重试：{{ data_get($progress->errorMetadata, 'auto_retry_exhausted') ? '已耗尽' : '未标记为耗尽' }}</p>
                        <p>已成功且来源有效的 Artifact 会保留，继续生成不会重复执行这些阶段。</p>
                        @if ($progress->recommendedAction)
                            <p>推荐操作：{{ $progress->recommendedAction }}</p>
                        @endif
                    </div>
                </div>
            @endif

            <div class="flex justify-end">
                <x-filament::button
                    color="gray"
                    icon="heroicon-o-magnifying-glass"
                    size="sm"
                    wire:click="mountAction('viewOutlineGenerationRuns')"
                >
                    查看运行详情
                </x-filament::button>
            </div>
        @endif
    </div>
</x-filament::section>
