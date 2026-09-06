<article class="grid gap-4 py-4 first:pt-0 last:pb-0 lg:grid-cols-[minmax(0,1fr)_9rem_minmax(16rem,1.15fr)]">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::badge :color="$arc->type->getColor()">
                {{ $arc->type->getLabel() }}
            </x-filament::badge>
            <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                {{ $arc->title }}
            </h4>
            <x-filament::badge :color="$arc->status->getColor()">
                {{ $arc->status->getLabel() }}
            </x-filament::badge>
        </div>
        <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $arc->goal }}</p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            风险 / 代价：{{ $arc->stakes }}
        </p>
    </div>

    <div>
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">推进进度</p>
        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">
            {{ round($arc->progress * 100) }}%
        </p>
        <div
            class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
            role="progressbar"
            aria-label="{{ $arc->title }}推进进度"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="{{ round($arc->progress * 100) }}"
        >
            <div
                class="h-full rounded-full bg-primary-600 dark:bg-primary-500"
                style="width: {{ round($arc->progress * 100) }}%"
            ></div>
        </div>
    </div>

    <div>
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">关键节拍</p>
        @if (count($arc->beats ?? []) === 0)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">尚未设置关键节拍。</p>
        @else
            <ol class="mt-2 space-y-1.5">
                @foreach ($arc->beats as $beat)
                    <li class="flex gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <span class="mt-0.5 shrink-0 font-mono text-xs tabular-nums text-gray-400 dark:text-gray-500">
                            {{ $loop->iteration }}.
                        </span>
                        <span>{{ $beat }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</article>
