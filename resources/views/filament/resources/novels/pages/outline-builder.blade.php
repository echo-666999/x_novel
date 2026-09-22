<div class="space-y-6">
    @if ($outline === null)
        <x-filament::section>
            <div class="space-y-2">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">尚未建立全书大纲</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400">选择“手工创建”或“AI 生成候选”。保存候选不会创建正式分卷、故事线、人物或世界资料。</p>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ $outline->content['title'] ?? '未命名大纲' }}</x-slot>
            <x-slot name="description">Version {{ $outline->version }} · {{ $outline->status->getLabel() }} · {{ $outline->source->getLabel() }}</x-slot>

            <div class="space-y-4">
                <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">{{ $outline->content['summary'] ?? '' }}</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Volumes</div>
                        <div class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ count($outline->content['volumes'] ?? []) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="text-xs text-gray-500 dark:text-gray-400">校验状态</div>
                        <div class="mt-1 text-sm font-medium {{ $validation?->isValid() ? 'text-success-600' : 'text-danger-600' }}">
                            {{ $validation?->isValid() ? 'VALID' : 'INVALID' }}
                        </div>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Checksum</div>
                        <div class="mt-1 truncate font-mono text-xs text-gray-700 dark:text-gray-300" title="{{ $outline->checksum }}">{{ $outline->checksum }}</div>
                    </div>
                </div>
                @if (! $validation?->isValid())
                    <ul class="list-disc space-y-1 pl-5 text-sm text-danger-600">
                        @foreach ($validation->errors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-filament::section>

        @foreach ($outline->content['volumes'] ?? [] as $volume)
            <x-filament::section collapsible>
                <x-slot name="heading">{{ $volume['sequence'] }}. {{ $volume['title'] }}</x-slot>
                <x-slot name="description">{{ $volume['key'] }} · 目标 {{ number_format($volume['target_words']) }} 字</x-slot>

                <div class="space-y-5">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><span class="text-xs text-gray-500">目标</span><p class="mt-1 text-sm text-gray-800 dark:text-gray-200">{{ $volume['goal'] }}</p></div>
                        <div><span class="text-xs text-gray-500">高潮</span><p class="mt-1 text-sm text-gray-800 dark:text-gray-200">{{ $volume['climax'] }}</p></div>
                    </div>
                    @foreach ($volume['arcs'] ?? [] as $arc)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $arc['sequence'] }}. {{ $arc['title'] }}</h3>
                                <span class="rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $arc['type'] }} · {{ $arc['key'] }}</span>
                            </div>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $arc['goal'] }}</p>
                            <div class="mt-4 divide-y divide-gray-200 dark:divide-white/10">
                                @foreach ($arc['beats'] ?? [] as $beat)
                                    <div class="grid gap-2 py-3 md:grid-cols-[minmax(0,1fr)_auto]">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $beat['sequence'] }}. {{ $beat['title'] }}</div>
                                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $beat['summary'] }}</p>
                                        </div>
                                        <div class="font-mono text-xs text-gray-500">{{ $beat['key'] }} · {{ $beat['chapter_budget']['min'] }}–{{ $beat['chapter_budget']['max'] ?? '∞' }} 章</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    @endif

    @if ($versions->isNotEmpty())
        <x-filament::section collapsed collapsible>
            <x-slot name="heading">版本历史</x-slot>
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($versions as $version)
                    <div class="flex items-center justify-between gap-4 py-3 text-sm">
                        <div>Version {{ $version->version }} · {{ $version->source->getLabel() }}</div>
                        <div class="text-gray-500">{{ $version->status->getLabel() }} · {{ $version->created_at?->format('Y-m-d H:i') }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</div>
