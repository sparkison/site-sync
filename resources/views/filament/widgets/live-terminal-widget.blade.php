<x-filament-widgets::widget>
    <x-filament::section heading="Live Terminal" description="Real-time output from the most recent sync operation"
        icon="heroicon-o-command-line">

        @php
            /** @var \App\Models\SyncLog|null $syncLog */
            $syncLog = $this->syncLog;
            $isActive = $syncLog && in_array($syncLog->status, ['pending', 'running']);
        @endphp

        @if ($syncLog)
            <div class="mb-3 flex items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ $syncLog->fromEnvironment?->name }} → {{ $syncLog->toEnvironment?->name }}
                    </span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">({{ $syncLog->site?->name }})</span>
                </div>

                <x-filament::badge :color="match ($syncLog->status) {
                'completed' => 'success',
                'running' => 'warning',
                'failed' => 'danger',
                default => 'gray',
            }">
                    {{ ucfirst($syncLog->status) }}
                </x-filament::badge>
            </div>
        @endif

        <div x-data="{
                scrollToBottom() {
                    const el = this.$refs.terminal;
                    if (el) { el.scrollTop = el.scrollHeight; }
                }
            }"
            x-init="scrollToBottom()"
            @if ($isActive) wire:poll.1000ms="$refresh" @endif>
            <div x-ref="terminal"
                x-intersect="scrollToBottom()"
                class="font-mono text-xs leading-relaxed bg-gray-950 text-green-400 rounded-lg p-4 h-72 overflow-y-auto shadow-inner border border-gray-800 dark:border-gray-700">
                @if ($syncLog && $syncLog->output)
                    <div class="whitespace-pre-wrap break-words">{{ $syncLog->output }}</div>
                @else
                    <span class="text-gray-600 italic">
                        {{ $syncLog ? 'Waiting for process to start...' : 'No sync activity yet. Start a sync from the Sites section.' }}
                    </span>
                @endif
            </div>
        </div>

    </x-filament::section>
</x-filament-widgets::widget>