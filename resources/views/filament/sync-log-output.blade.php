<div class="space-y-3">
    <div class="flex items-center gap-3 flex-wrap">
        <x-filament::badge :color="match($log->direction) { 'push' => 'warning', 'pull' => 'info', default => 'gray' }">
            {{ ucfirst($log->direction) }}
        </x-filament::badge>
        <span class="text-sm text-gray-500 dark:text-gray-400">
            {{ $log->fromEnvironment->name }} → {{ $log->toEnvironment->name }}
        </span>
        <x-filament::badge :color="match($log->status) { 'completed' => 'success', 'running' => 'warning', 'failed' => 'danger', default => 'gray' }">
            {{ ucfirst($log->status) }}
        </x-filament::badge>
    </div>

    @if($log->started_at)
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Started: {{ $log->started_at->format('Y-m-d H:i:s') }}
            @if($log->completed_at)
                · Completed: {{ $log->completed_at->format('Y-m-d H:i:s') }}
                · Duration: {{ $log->started_at->diffForHumans($log->completed_at, true) }}
            @endif
        </p>
    @endif

    <div
        class="bg-gray-950 rounded-lg p-4 font-mono text-xs text-green-400 overflow-auto max-h-[60vh] whitespace-pre-wrap break-all"
        @if($log->status === 'running') wire:poll.2s="$refresh" @endif
    >{{ $log->getOutputContent() ?: '(no output yet)' }}</div>
</div>
