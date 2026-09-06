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
                'state_diff' => '比较两个不可变 Story State Version',
                default => 'State path: '.$activeDomain,
            }"
        >
            @if ($activeDomain === 'facts')
                {{ $this->table }}
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
