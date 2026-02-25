<x-filament-widgets::widget>
    <x-filament::section compact heading="Live Terminal" icon="heroicon-o-command-line">

        @php
            /** @var \App\Models\SyncLog|null $syncLog */
            $syncLog = $this->syncLog;
            $siteUrl = $this->siteUrl;

            // Show at most the last 50 KB on initial load; polling appends new content on top.
            $rawContent   = $syncLog?->getOutputContent() ?? '';
            $rawSize      = strlen($rawContent);
            $initialLimit = 50_000;

            if ($rawSize > $initialLimit) {
                $initialContent = "[Output truncated — showing last 50 KB]\n\n" . substr($rawContent, -$initialLimit);
            } else {
                $initialContent = $rawContent;
            }

            // Byte offset where polling should start reading from the log file.
            $initialOffset = $rawSize;
        @endphp

        <div x-data="{
                logId:     @js($syncLog?->id),
                offset:    @js($initialOffset),
                status:    @js($syncLog?->status),
                direction: @js($syncLog?->direction),
                from:      @js($syncLog?->fromEnvironment?->name),
                to:        @js($syncLog?->toEnvironment?->name),
                siteName:  @js($syncLog?->site?->name),
                siteUrl:   @js($siteUrl),
                output:    @js($initialContent),
                startedAt: @js($syncLog?->started_at?->format('H:i:s')),
                duration:  @js($syncLog?->completed_at ? $syncLog->started_at->diffForHumans($syncLog->completed_at, true) : null),

                init() {
                    this.$nextTick(() => this.scrollToBottom());
                    this.$watch('output', () => this.$nextTick(() => this.scrollToBottom()));
                    setInterval(() => this.poll(), 1000);
                },

                async poll() {
                    try {
                        const url = '{{ route('sync-logs.latest') }}?log_id=' + (this.logId ?? 0) + '&offset=' + this.offset;
                        const res  = await fetch(url);
                        const data = await res.json();

                        if (!data) return;

                        const isNewLog = data.id !== this.logId;

                        this.status    = data.status;
                        this.direction = data.direction;
                        this.from      = data.from;
                        this.to        = data.to;
                        this.siteName  = data.site_name;
                        this.siteUrl   = data.site_url;
                        this.startedAt = data.started_at;
                        this.duration  = data.duration;
                        this.logId     = data.id;
                        this.offset    = data.offset;

                        if (isNewLog) {
                            this.output = data.content;
                        } else if (data.content) {
                            this.output += data.content;
                        }
                    } catch {}
                },

                scrollToBottom() {
                    const el = this.$refs.terminal;
                    if (el) el.scrollTop = el.scrollHeight;
                },
            }">
            {{-- Header row --}}
            <div class="mb-3 flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-2 flex-wrap" x-show="status">

                    {{-- Direction badge --}}
                    <x-filament::badge color="warning" x-show="direction === 'push'">Push</x-filament::badge>
                    <x-filament::badge color="info" x-show="direction === 'pull'">Pull</x-filament::badge>

                    {{-- From → To --}}
                    <span class="text-sm text-gray-600 dark:text-gray-400" x-show="from && to">
                        <span x-text="from"></span> → <span x-text="to"></span>
                    </span>

                    {{-- Status badge --}}
                    <x-filament::badge color="gray" x-show="status === 'pending'">Pending</x-filament::badge>
                    <x-filament::badge color="warning" x-show="status === 'running'">Running</x-filament::badge>
                    <x-filament::badge color="success" x-show="status === 'completed'">Completed</x-filament::badge>
                    <x-filament::badge color="danger" x-show="status === 'failed'">Failed</x-filament::badge>
                    <x-filament::badge color="gray" x-show="status === 'cancelled'">Cancelled</x-filament::badge>

                    {{-- Time / duration --}}
                    <span class="text-xs text-gray-400 dark:text-gray-500" x-show="startedAt">
                        <span x-text="startedAt"></span>
                        <template x-if="duration">
                            <span> &middot; <span x-text="duration"></span></span>
                        </template>
                        <template x-if="!duration && (status === 'running' || status === 'pending')">
                            <span> &middot; running&hellip;</span>
                        </template>
                    </span>
                </div>

                <span class="text-sm text-gray-400 dark:text-gray-500 italic" x-show="!status">
                    No recent activity
                </span>

                {{-- Cancel button --}}
                <div class="justify-end ">
                    <div class="flex items-center gap-2">
                        <x-filament::button outlined icon="heroicon-m-x-mark" wire:click="cancel"
                            x-show="status === 'running' || status === 'pending'" color="danger" size="xs">
                            Cancel
                        </x-filament::button>

                        {{-- Site link --}}
                        <a x-bind:href="siteUrl ?? '#'" x-show="siteName"
                            class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline flex items-center gap-1">
                            <span x-text="siteName"></span>
                            <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="w-3 h-3" />
                        </a>
                    </div>

                </div>
            </div>

            {{-- Terminal --}}
            <div x-ref="terminal"
                class="font-mono text-xs leading-relaxed bg-gray-950 text-green-400 rounded-lg p-4 h-72 overflow-y-auto shadow-inner border border-gray-800 dark:border-gray-700">
                <div x-show="output" x-text="output" class="whitespace-pre-wrap break-words"></div>
                <span x-show="!output" class="text-gray-600 italic"
                    x-text="status ? 'Waiting for process to start...' : 'No sync activity yet. Start a sync from the Sites section.'"></span>
            </div>
        </div>

    </x-filament::section>
</x-filament-widgets::widget>
