<div class="flex items-center gap-2" x-data="{
        syncing: @js($this->hasSyncInProgress),
        init() {
            // Seed from localStorage when the Live Terminal widget isn't on this page
            const stored = localStorage.getItem('sitesync_status') ?? '';
            if (stored) this.syncing = stored === 'pending' || stored === 'running';
        }
    }" @sitesync:status.window="syncing = ($event.detail.status === 'pending' || $event.detail.status === 'running')">
    <div class="[&_svg]:animate-spin" x-show="syncing" x-cloak>
        <x-filament::button tag="a" href="{{ url('/') }}" icon="heroicon-o-arrow-path" color="warning" outlined
            size="sm">
            Syncing...
        </x-filament::button>
    </div>
    {{ $this->syncSiteAction }}
    <x-filament-actions::modals />
</div>