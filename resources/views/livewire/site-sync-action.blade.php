<div class="flex items-center gap-2" x-data="{
        syncing: @js($this->hasSyncInProgress),
        async poll() {
            try {
                const res = await fetch('{{ route('sync-logs.latest') }}');
                const data = await res.json();
                this.syncing = data && (data.status === 'pending' || data.status === 'running');
            } catch {}
        },
        init() { setInterval(() => this.poll(), 2000); }
    }">
    <div class="[&_svg]:animate-spin" x-show="syncing" x-cloak>
        <x-filament::button tag="a" href="{{ url('/') }}" icon="heroicon-o-arrow-path" color="warning" outlined
            size="sm">
            Syncing...
        </x-filament::button>
    </div>
    {{ $this->syncSiteAction }}
    <x-filament-actions::modals />
</div>