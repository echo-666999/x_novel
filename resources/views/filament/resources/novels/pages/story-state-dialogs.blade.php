@if ($dialog !== null)
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4"
        role="presentation"
        wire:key="story-state-dialog-{{ $dialog }}"
    >
        <section
            role="dialog"
            aria-modal="true"
            aria-labelledby="story-state-dialog-title"
            class="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-900"
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 id="story-state-dialog-title" class="text-lg font-semibold text-gray-950 dark:text-white">
                        @switch($dialog)
                            @case('verify') 校验 Story State 重建结果 @break
                            @case('recover') 恢复 Canonical Story State @break
                            @case('projections') 重建领域投影 @break
                            @case('manual') 人工修正 Canonical Story State @break
                        @endswitch
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        @switch($dialog)
                            @case('verify') 只读校验，不写入 Canonical Story State。 @break
                            @case('recover') 将创建新的无章节恢复基线；历史快照不会被覆盖。 @break
                            @case('projections') 以当前 Canonical Story State 覆盖派生投影，不修改正式状态或 Story Events。 @break
                            @case('manual') 追加 Correction Event 并创建新的 State Version。 @break
                        @endswitch
                    </p>
                </div>
                <x-filament::icon-button
                    icon="heroicon-o-x-mark"
                    label="关闭"
                    color="gray"
                    wire:click="closeStoryStateDialog"
                />
            </div>

            <div class="mt-6">
                @if (in_array($dialog, ['verify', 'recover'], true) && $result !== null)
                    @include('filament.resources.novels.pages.story-state-rebuild', ['result' => $result])
                @elseif ($dialog === 'projections')
                    <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200">
                        此操作会重写人物、世界实体与伏笔的派生字段。Canonical State 和 Story Events 保持不变。
                    </div>
                @elseif ($dialog === 'manual')
                    <div class="space-y-4">
                        <label class="block">
                            <span class="text-sm font-medium text-gray-950 dark:text-white">状态路径</span>
                            <input
                                type="text"
                                wire:model="manualCorrectionPath"
                                placeholder="例如 characters.42.location"
                                class="fi-input mt-1 w-full"
                            />
                            @error('manualCorrectionPath') <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-gray-950 dark:text-white">新值（JSON）</span>
                            <textarea
                                wire:model="manualCorrectionValue"
                                rows="5"
                                placeholder='例如 "洛阳"、true 或 {"status":"open"}'
                                class="fi-input mt-1 w-full"
                            ></textarea>
                            @error('manualCorrectionValue') <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-gray-950 dark:text-white">修正原因</span>
                            <textarea wire:model="manualCorrectionReason" rows="3" class="fi-input mt-1 w-full"></textarea>
                            @error('manualCorrectionReason') <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span> @enderror
                        </label>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-filament::button color="gray" wire:click="closeStoryStateDialog">关闭</x-filament::button>
                @if ($dialog === 'recover')
                    <x-filament::button color="danger" wire:click="recoverCanonicalStateFromDialog" wire:loading.attr="disabled">
                        确认创建恢复版本
                    </x-filament::button>
                @elseif ($dialog === 'projections')
                    <x-filament::button color="warning" wire:click="rebuildProjectionsFromDialog" wire:loading.attr="disabled">
                        确认重建
                    </x-filament::button>
                @elseif ($dialog === 'manual')
                    <x-filament::button color="warning" wire:click="saveManualCorrection" wire:loading.attr="disabled">
                        创建修正版本
                    </x-filament::button>
                @endif
            </div>
        </section>
    </div>
@endif
