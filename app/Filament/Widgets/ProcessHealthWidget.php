<?php

namespace App\Filament\Widgets;

use App\Services\ToolHealthService;
use Filament\Widgets\Widget;

class ProcessHealthWidget extends Widget
{
    protected string $view = 'filament.widgets.process-health-widget';

    protected static ?int $sort = 99;

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'lg' => 2,
    ];

    /** @var array<int, array{key: string, label: string, description: string, required: bool, installed: bool, path: string|null}> */
    public array $tools = [];

    public bool $checked = false;

    public function mount(): void
    {
        $this->runCheck();
    }

    public function runCheck(): void
    {
        $this->tools = app(ToolHealthService::class)->check();
        $this->checked = true;
    }

    public function isHealthy(): bool
    {
        return collect($this->tools)
            ->where('required', true)
            ->where('installed', false)
            ->isEmpty();
    }
}
