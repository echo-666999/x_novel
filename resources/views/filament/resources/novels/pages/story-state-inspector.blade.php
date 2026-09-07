<div class="space-y-4">
    @if ($stateVersion === null)
        <x-filament::section>
            <div class="py-6 text-center">
                <x-filament::icon
                    icon="heroicon-o-circle-stack"
                    class="mx-auto size-8 text-gray-400 dark:text-gray-500"
                />
                <h3 class="mt-3 text-sm font-semibold text-gray-950 dark:text-white">
                    故事状态尚未初始化
                </h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    请先在小说概览初始化 Story State Version 0。
                </p>
                <div class="mt-4">
                    <x-filament::button
                        tag="a"
                        :href="\App\Filament\Resources\Novels\NovelResource::getUrl('view', ['record' => $novel])"
                        color="gray"
                    >
                        返回小说概览
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section
            heading="State Version {{ $stateVersion->version }}"
            description="Canonical Story State 只读快照"
            icon="heroicon-o-circle-stack"
        >
            <x-slot name="afterHeader">
                @if ($isCurrent)
                    <x-filament::badge color="success">Current</x-filament::badge>
                @else
                    <x-filament::badge color="gray">Historical</x-filament::badge>
                @endif
            </x-slot>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label for="story-state-version" class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        查看版本
                    </label>
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input.select
                            id="story-state-version"
                            wire:change="selectVersion(Number($event.target.value))"
                        >
                            @foreach ($versions as $version)
                                <option value="{{ $version->version }}" @selected($version->version === $stateVersion->version)>
                                    v{{ $version->version }}{{ $version->id === $novel->canonical_state_version_id ? ' · Current' : '' }}
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">来源章节</p>
                    <p class="mt-2 text-sm text-gray-950 dark:text-white">
                        {{ $stateVersion->chapter_id === null ? '初始化状态' : '#'.$stateVersion->chapter_id }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">创建时间</p>
                    <p class="mt-2 text-sm tabular-nums text-gray-950 dark:text-white">
                        {{ $stateVersion->created_at->format('Y-m-d H:i:s') }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Schema Version</p>
                    <p class="mt-2 text-sm tabular-nums text-gray-950 dark:text-white">
                        {{ $stateVersion->state['schema_version'] ?? '—' }}
                    </p>
                </div>
            </div>

            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Checksum</p>
                <code class="mt-1 block break-all font-mono text-xs text-gray-700 dark:text-gray-300">
                    {{ $stateVersion->checksum }}
                </code>
            </div>
        </x-filament::section>

        <x-filament::tabs label="故事状态领域">
            @foreach ($domains as $domain => $label)
                <x-filament::tabs.item
                    :active="$activeDomain === $domain"
                    wire:click="selectDomain('{{ $domain }}')"
                >
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <x-filament::section
            :heading="$domains[$activeDomain]"
            :description="match ($activeDomain) {
                'facts' => '当前规范事实 · 操作受控',
                'story_events' => '正式故事事件 · 默认只读',
                'state_diff' => '比较两个不可变 Story State Version',
                default => 'State path: '.$activeDomain,
            }"
        >
            @if ($activeDomain === 'facts')
                {{ $this->table }}
            @elseif ($activeDomain === 'story_events')
                <div class="space-y-4">
                    <div class="flex flex-wrap gap-2">
                        @foreach (['active' => '有效', 'invalidated' => '已失效', 'all' => '全部'] as $status => $label)
                            <x-filament::button
                                size="sm"
                                :color="$eventStatus === $status ? 'primary' : 'gray'"
                                wire:click="selectEventStatus('{{ $status }}')"
                            >
                                {{ $label }}
                            </x-filament::button>
                        @endforeach
                    </div>

                    @if ($storyEvents->isEmpty())
                        <div class="rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center dark:border-white/15">
                            <x-filament::icon icon="heroicon-o-clock" class="mx-auto size-8 text-gray-400" />
                            <p class="mt-3 text-sm font-medium text-gray-950 dark:text-white">暂无故事事件</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Canonical Commit 后的正式事件会显示在这里。</p>
                        </div>
                    @else
                        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                            <table class="w-full divide-y divide-gray-200 text-left text-sm dark:divide-white/10">
                                <thead class="bg-gray-50 text-xs font-medium text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                    <tr>
                                        <th class="px-4 py-3">状态</th>
                                        <th class="px-4 py-3">章节 / 场景</th>
                                        <th class="px-4 py-3">事件类型</th>
                                        <th class="px-4 py-3">主体</th>
                                        <th class="px-4 py-3">版本</th>
                                        <th class="min-w-72 px-4 py-3">文本证据</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                    @foreach ($storyEvents as $event)
                                        <tr class="align-top">
                                            <td class="px-4 py-3">
                                                <x-filament::badge :color="$event->status->getColor()">
                                                    {{ $event->status->getLabel() }}
                                                </x-filament::badge>
                                                @if ($event->invalidated_at)
                                                    <p class="mt-1 whitespace-nowrap text-xs text-gray-500">{{ $event->invalidated_at->format('Y-m-d H:i') }}</p>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">
                                                <p>第 {{ $event->chapter->sequence }} 章</p>
                                                <p class="mt-1 text-xs text-gray-500">{{ $event->scene ? '场景 '.$event->scene->sequence : '章节级事件' }}</p>
                                            </td>
                                            <td class="px-4 py-3">
                                                <p class="font-medium text-gray-950 dark:text-white">{{ $event->event_type->getLabel() }}</p>
                                                <code class="mt-1 block text-xs text-gray-500">{{ $event->event_type->value }}</code>
                                            </td>
                                            <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                                {{ $event->subject_type ? $event->subject_type.' #'.$event->subject_id : '—' }}
                                            </td>
                                            <td class="px-4 py-3 font-mono text-gray-700 dark:text-gray-300">v{{ $event->state_version }}</td>
                                            <td class="px-4 py-3">
                                                @foreach ($event->evidence as $evidence)
                                                    <blockquote class="border-l-2 border-primary-500 pl-3 text-gray-700 dark:text-gray-300">
                                                        {{ $evidence['quote'] ?? '未提供引文' }}
                                                    </blockquote>
                                                @endforeach
                                                @if ($event->story_time)
                                                    <p class="mt-2 text-xs text-gray-500">故事时间：{{ $event->story_time }}</p>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @elseif ($activeDomain === 'state_diff')
                @include('filament.components.state-diff', [
                    'versions' => $versions,
                    'fromVersion' => $diffFrom,
                    'toVersion' => $diffTo,
                    'changes' => $stateChanges,
                    'currentVersionId' => $novel->canonical_state_version_id,
                ])
            @elseif (blank($domainState))
                <p class="py-4 text-sm text-gray-500 dark:text-gray-400">
                    当前版本在此领域没有状态数据。
                </p>
            @else
                <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 font-mono text-xs leading-6 text-gray-800 dark:bg-white/5 dark:text-gray-200"><code>{{ json_encode($domainState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}</code></pre>
            @endif
        </x-filament::section>
    @endif
</div>
