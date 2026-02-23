<x-filament-widgets::widget>
    <x-filament::section collapsed collapsible>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <span>System Health</span>
                @if ($this->checked)
                    @if ($this->isHealthy())
                        <x-filament::badge color="success" icon="heroicon-m-check-circle">
                            All required tools found
                        </x-filament::badge>
                    @else
                        <x-filament::badge color="danger" icon="heroicon-m-exclamation-circle">
                            Missing required tools
                        </x-filament::badge>
                    @endif
                @endif
            </div>
        </x-slot>

        <x-slot name="afterHeader">
            <x-filament::button
                wire:click="runCheck"
                wire:loading.attr="disabled"
                size="sm"
                color="gray"
                icon="heroicon-m-arrow-path"
                wire:loading.class="opacity-50"
            >
                <span wire:loading.remove wire:target="runCheck">Check health</span>
                <span wire:loading wire:target="runCheck">Checking…</span>
            </x-filament::button>
        </x-slot>

        @if (empty($tools))
            <p class="text-sm text-gray-500 dark:text-gray-400">No tools checked yet.</p>
        @else
            <div class="divide-y space-y-2 divide-gray-100 dark:divide-white/5">
                @foreach ($tools as $tool)
                    <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                        <div class="flex items-center gap-3 min-w-0">
                            @if ($tool['installed'])
                                <x-filament::icon
                                    icon="heroicon-m-check-circle"
                                    class="h-5 w-5 shrink-0 text-success-500"
                                />
                            @else
                                <x-filament::icon
                                    icon="heroicon-m-x-circle"
                                    class="h-5 w-5 shrink-0 {{ $tool['required'] ? 'text-danger-500' : 'text-warning-400' }}"
                                />
                            @endif

                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $tool['label'] }}
                                    </span>
                                    @if (! $tool['required'])
                                        <x-filament::badge color="gray" size="sm">optional</x-filament::badge>
                                    @endif
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $tool['installed'] ? ($tool['path'] ?? 'installed') : $tool['description'] }}
                                </p>
                            </div>
                        </div>

                        @if (! $tool['installed'])
                            <span class="shrink-0 text-xs font-medium {{ $tool['required'] ? 'text-danger-600 dark:text-danger-400' : 'text-warning-600 dark:text-warning-400' }}">
                                Not found
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
