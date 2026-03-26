<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SiteResource;
use App\Jobs\SyncJob;
use App\Models\SyncLog;
use Filament\Notifications\Notification;
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

    public function rerun(): void
    {
        $log = $this->syncLog;

        if (! $log || $log->status === 'running' || $log->status === 'pending') {
            return;
        }

        Notification::make()
            ->title('Re-running sync...')
            ->send();

        $newLog = SyncLog::create([
            'site_id' => $log->site_id,
            'from_environment_id' => $log->from_environment_id,
            'to_environment_id' => $log->to_environment_id,
            'direction' => $log->direction,
            'scope' => $log->scope,
            'status' => 'pending',
        ]);

        SyncJob::dispatch($newLog);
    }
}
