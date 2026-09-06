<div class="space-y-4">
    <x-filament::tabs label="规划范围">
        <x-filament::tabs.item
            :active="$scope === 'active'"
            wire:click="setScope('active')"
        >
            当前推进
        </x-filament::tabs.item>
        <x-filament::tabs.item
            :active="$scope === 'completed'"
            wire:click="setScope('completed')"
        >
            已完成
        </x-filament::tabs.item>
        <x-filament::tabs.item
            :active="$scope === 'all'"
            wire:click="setScope('all')"
        >
            全部
        </x-filament::tabs.item>
    </x-filament::tabs>

    @if ($volumes->isEmpty() && $globalArcs->isEmpty())
        <x-filament::section>
            <div class="py-6 text-center">
                <x-filament::icon
                    icon="heroicon-o-map"
                    class="mx-auto size-8 text-gray-400 dark:text-gray-500"
                />
                <h3 class="mt-3 text-sm font-semibold text-gray-950 dark:text-white">
                    当前范围没有规划内容
                </h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    可在“分卷”和“故事线”中维护小说的规划结构。
                </p>
            </div>
        </x-filament::section>
    @else
        <div class="flex items-center justify-between gap-3 text-sm text-gray-500 dark:text-gray-400">
            <p>按分卷查看故事线目标、推进进度与关键节拍。</p>
            <p class="shrink-0 tabular-nums">{{ $volumes->count() }} 卷 · {{ $arcCount }} 条故事线</p>
        </div>

        @if ($globalArcs->isNotEmpty())
            <x-filament::section
                heading="跨卷 / 全书故事线"
                description="未绑定单一分卷、贯穿全书或多个分卷的故事线。"
                icon="heroicon-o-globe-alt"
                collapsible
            >
                <div class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($globalArcs as $arc)
                        @include('filament.resources.novels.pages.partials.planning-arc', ['arc' => $arc])
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        @foreach ($volumes as $volume)
            <x-filament::section collapsible>
                <x-slot name="heading">
                    第 {{ $volume->sequence }} 卷 · {{ $volume->title }}
                </x-slot>

                <x-slot name="description">
                    {{ $volume->goal }}
                </x-slot>

                <x-slot name="afterHeader">
                    <div class="flex items-center gap-2">
                        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $volume->storyArcs->count() }} 条故事线
                        </span>
                        <x-filament::badge :color="$volume->status->getColor()">
                            {{ $volume->status->getLabel() }}
                        </x-filament::badge>
                    </div>
                </x-slot>

                @if ($volume->storyArcs->isEmpty())
                    <p class="py-2 text-sm text-gray-500 dark:text-gray-400">
                        此分卷在当前范围内没有故事线。
                    </p>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($volume->storyArcs as $arc)
                            @include('filament.resources.novels.pages.partials.planning-arc', ['arc' => $arc])
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    @endif
</div>
