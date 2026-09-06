<div class="space-y-4">
    <div class="grid items-end gap-3 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
        <div>
            <label for="state-diff-from" class="text-xs font-medium text-gray-500 dark:text-gray-400">
                Before
            </label>
            <x-filament::input.wrapper class="mt-1">
                <x-filament::input.select
                    id="state-diff-from"
                    wire:change="selectDiffVersion('from', Number($event.target.value))"
                >
                    @foreach ($versions as $version)
                        <option value="{{ $version->version }}" @selected($version->version === $fromVersion?->version)>
                            v{{ $version->version }}{{ $version->id === $currentVersionId ? ' · Current' : '' }}
                        </option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <div class="hidden pb-2 text-sm text-gray-400 md:block dark:text-gray-500" aria-hidden="true">→</div>

        <div>
            <label for="state-diff-to" class="text-xs font-medium text-gray-500 dark:text-gray-400">
                After
            </label>
            <x-filament::input.wrapper class="mt-1">
                <x-filament::input.select
                    id="state-diff-to"
                    wire:change="selectDiffVersion('to', Number($event.target.value))"
                >
                    @foreach ($versions as $version)
                        <option value="{{ $version->version }}" @selected($version->version === $toVersion?->version)>
                            v{{ $version->version }}{{ $version->id === $currentVersionId ? ' · Current' : '' }}
                        </option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </div>

    <div class="flex items-center justify-between border-t border-gray-200 pt-4 dark:border-white/10">
        <p class="font-mono text-sm font-medium text-gray-950 dark:text-white">
            v{{ $fromVersion?->version ?? '—' }} → v{{ $toVersion?->version ?? '—' }}
        </p>
        <x-filament::badge :color="count($changes) > 0 ? 'warning' : 'success'">
            {{ count($changes) }} 处变化
        </x-filament::badge>
    </div>

    @if (count($changes) === 0)
        <div class="py-6 text-center">
            <x-filament::icon icon="heroicon-o-check-circle" class="mx-auto size-7 text-success-500" />
            <p class="mt-2 text-sm font-medium text-gray-950 dark:text-white">两个版本没有状态差异</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="bg-gray-50 text-xs font-medium text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">Changed path</th>
                        <th class="px-3 py-2">Before</th>
                        <th class="px-3 py-2">After</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($changes as $change)
                        <tr class="align-top">
                            <td class="w-1/4 px-3 py-3">
                                <code class="break-all font-mono text-xs text-gray-950 dark:text-white">{{ $change['path'] }}</code>
                                <div class="mt-1">
                                    <x-filament::badge :color="match ($change['type']) {
                                        'added' => 'success',
                                        'removed' => 'danger',
                                        default => 'warning',
                                    }">
                                        {{ match ($change['type']) {
                                            'added' => 'Added',
                                            'removed' => 'Removed',
                                            default => 'Changed',
                                        } }}
                                    </x-filament::badge>
                                </div>
                            </td>
                            <td class="w-[37.5%] px-3 py-3">
                                @if ($change['before_missing'])
                                    <span class="text-xs text-gray-400 dark:text-gray-500">不存在</span>
                                @else
                                    <pre class="whitespace-pre-wrap break-words font-mono text-xs leading-5 text-gray-700 dark:text-gray-300"><code>{{ json_encode($change['before'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}</code></pre>
                                @endif
                            </td>
                            <td class="w-[37.5%] px-3 py-3">
                                @if ($change['after_missing'])
                                    <span class="text-xs text-gray-400 dark:text-gray-500">不存在</span>
                                @else
                                    <pre class="whitespace-pre-wrap break-words font-mono text-xs leading-5 text-gray-700 dark:text-gray-300"><code>{{ json_encode($change['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}</code></pre>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
