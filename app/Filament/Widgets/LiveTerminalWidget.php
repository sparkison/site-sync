<?php

namespace App\Filament\Widgets;

use App\Models\SyncLog;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;

class LiveTerminalWidget extends Widget
{
    protected string $view = 'filament.widgets.live-terminal-widget';

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    /**
     * Fetch the most recent SyncLog for display in the terminal.
     */
    #[Computed]
    public function syncLog(): ?SyncLog
    {
        return SyncLog::with(['site', 'fromEnvironment', 'toEnvironment'])
            ->latest()
            ->first();
    }
}
