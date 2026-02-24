<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SiteResource;
use App\Models\SyncLog;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;

class LiveTerminalWidget extends Widget
{
    protected string $view = 'filament.widgets.live-terminal-widget';

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    #[Computed]
    public function syncLog(): ?SyncLog
    {
        return SyncLog::with(['site', 'fromEnvironment', 'toEnvironment'])
            ->latest()
            ->first();
    }

    #[Computed]
    public function siteUrl(): ?string
    {
        $log = $this->syncLog;

        if (! $log?->site) {
            return null;
        }

        return SiteResource::getUrl('edit', ['record' => $log->site]);
    }

    public function cancel(): void
    {
        $log = SyncLog::whereIn('status', ['pending', 'running'])->latest()->first();

        $log?->markCancelled();
    }
}
