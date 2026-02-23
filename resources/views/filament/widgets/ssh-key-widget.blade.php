@php
    $keyCount = \App\Models\SshKey::count();
    $keyUrl = \App\Filament\Resources\SshKeyResource::getUrl('index');
@endphp
<x-filament-widgets::widget class="fi-filament-info-widget">
    <x-filament::section>
        <div class="flex items-center gap-x-3">
            <div>
                <a class="w-12 h-12 flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-900 outline outline-1 outline-gray-300 dark:outline-gray-700 hover:outline-primary-500 transition group-hover:outline-primary-500"
                    href="{{ $keyUrl }}" rel="noopener noreferrer">
                    <x-filament::icon icon="heroicon-o-key" class="w-6 h-6 text-indigo-500" />
                </a>
            </div>

            <div class="flex-1">
                <h2 class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    SSH Keys
                </h2>
                <p class="text-gray-600 dark:text-gray-300">
                    Manage your SSH keys for seamless authentication across all your environments.
                </p>
            </div>

            <div class="flex flex-col items-end gap-y-1">
                <x-filament::button color="gray" tag="a" href="{{ $keyUrl }}"
                    icon="heroicon-o-arrow-top-right-on-square" badge="{{ $keyCount }}"
                    icon-alias="panels::widgets.filament-info.open-documentation-button" rel="noopener noreferrer">
                    SSH Keys
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>